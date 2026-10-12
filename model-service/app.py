import io, os, threading
from fastapi import FastAPI, UploadFile, HTTPException
from pydantic import BaseModel, Field
from pypdf import PdfReader
from reportlab.pdfgen import canvas
from reportlab.lib.utils import simpleSplit
from fastembed import TextEmbedding
from flashrank import Ranker, RerankRequest
app = FastAPI()
lock = threading.Lock()
embedder = TextEmbedding(model_name="BAAI/bge-small-en-v1.5", cache_dir="/models", threads=2)
ranker = Ranker(model_name="ms-marco-MiniLM-L-12-v2", cache_dir="/models", max_length=256)
class Embeds(BaseModel):
    texts: list[str] = Field(max_length=100)
    query: bool = False
class Ranks(BaseModel):
    query: str = Field(max_length=2000)
    passages: list[dict] = Field(max_length=24)
class Pdf(BaseModel):
    title: str
    pages: list[dict] = Field(max_length=60)
@app.get('/health')
def health(): return {'status':'ok','embedding':'BAAI/bge-small-en-v1.5','dimensions':384,'reranker':'ms-marco-MiniLM-L-12-v2'}
@app.post('/embed')
def embed(req: Embeds):
    if any(len(t)>12000 for t in req.texts): raise HTTPException(422,'Text too long')
    with lock:
        vectors = list(embedder.query_embed(req.texts)) if req.query else list(embedder.passage_embed(req.texts))
    return {'vectors':[v.tolist() for v in vectors]}
@app.post('/rerank')
def rerank(req: Ranks):
    with lock: result = ranker.rerank(RerankRequest(query=req.query,passages=req.passages))
    return {'results':[{'id':p['id'],'score':float(p['score'])} for p in result]}
@app.post('/parse')
def parse(file: UploadFile):
    data=file.file.read(5*1024*1024+1)
    if len(data)>5*1024*1024 or not data.startswith(b'%PDF-'): raise HTTPException(422,'Invalid or oversized PDF')
    try:
        pdf=PdfReader(io.BytesIO(data))
        if pdf.is_encrypted or not 1<=len(pdf.pages)<=60: raise ValueError('Encrypted PDF or page limit exceeded')
        pages=[{'page':i+1,'text':p.extract_text() or ''} for i,p in enumerate(pdf.pages)]
        if sum(len(p['text']) for p in pages)<40: raise ValueError('No readable text; OCR not supported')
        if sum(len(p['text']) for p in pages)>300000: raise ValueError('Text limit exceeded')
        return {'pages':pages}
    except Exception: raise HTTPException(422,'Cannot parse this PDF; use a text-based PDF, at most 60 pages')
@app.post('/synthetic-pdf')
def synthetic(req: Pdf):
    from fastapi.responses import Response
    out=io.BytesIO(); c=canvas.Canvas(out,pagesize=(595,842))
    c.setTitle(req.title+' — synthetic demo')
    for p in req.pages:
        c.setFillColorRGB(.04,.1,.15);c.rect(0,0,595,842,fill=1,stroke=0)
        c.setFillColorRGB(.4,.9,.95);c.setFont('Helvetica-Bold',10);c.drawString(48,785,'CITEPOST / SYNTHETIC DEMO CORPUS')
        c.setFillColorRGB(.95,.97,1);c.setFont('Helvetica-Bold',17)
        y=735
        for line in simpleSplit(p['heading'],'Helvetica-Bold',17,495):c.drawString(48,y,line);y-=24
        c.setFont('Helvetica',12);y-=30
        content=p['text'].split('\n\n',1)[-1] if '\n\n' in p['text'] else p['text']
        for line in simpleSplit(content,'Helvetica',12,495):c.drawString(48,y,line);y-=20
        c.setFillColorRGB(.6,.7,.75);c.setFont('Helvetica',10);c.drawString(48,55,req.title+' / page '+str(p['page']));c.showPage()
    c.save();return Response(out.getvalue(),media_type='application/pdf')
