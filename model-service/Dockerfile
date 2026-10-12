FROM python:3.12-slim
ENV PYTHONUNBUFFERED=1 HF_HUB_DISABLE_TELEMETRY=1 HF_HUB_DISABLE_XET=1 OMP_NUM_THREADS=2
WORKDIR /service
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app.py .
RUN mkdir /models && python -c "from fastembed import TextEmbedding; from flashrank import Ranker; TextEmbedding(model_name='BAAI/bge-small-en-v1.5',cache_dir='/models'); Ranker(model_name='ms-marco-MiniLM-L-12-v2',cache_dir='/models')" && useradd -u 1000 model && chown -R model:model /models /service
USER model
CMD ["uvicorn","app:app","--host","0.0.0.0","--port","8000"]
