# CITEPOST — approved delivery

Owned portfolio application, English, dark/cyan. User approved Laravel, public GitHub repository, VPS/public demo at citepost.syslora.com, Syslora portfolio listing and automatic deployment. Source reference: supplied 02-CITEPOST-RAG.md. GroqCloud chosen for answering. API key entered by owner in local ignored .env.

Implementation: Laravel 13 + Alpine interface; PostgreSQL 17/pgvector, Redis/Horizon workers, private Python ONNX embedding/reranking and pypdf parsing service. Structural paragraph chunks with immutable version/page/section provenance; PostgreSQL full text and exact identifiers + vector retrieval, RRF, local cross encoder, evidence gate, controlled JSON answers and citation validation. Real stages and timing, three synthetic corpora, 60-case evaluation, guided failures, session isolation, TTL purge and upload deduplication.

MVP decisions: private volume-backed file storage through Laravel Storage abstraction (S3 adapter is a future option); source PDF opens at page with adjacent highlighted text passage, not pixel-coordinate PDF highlights. Responses are returned after validation, without token streaming. OCR, provider switch UI and automatic multi-hop decomposition remain future extensions. These limits must be disclosed publicly. Implement the core reliability flows; never simulate AI metrics.

Acceptance: PDF upload through queued ready/failed stages, document removal/reindex, real Groq answer with valid sources, actual vector/lexical/hybrid comparison, unknown-question abstention, inspector/context/usage, real evaluation metrics, explicit simulated failures, isolation tests and deployed desktop/mobile verification.
