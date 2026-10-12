# CITEPOST — RAG Reliability Lab

An owned Syslora portfolio application for inspectable retrieval over synthetic or uploaded documents. Laravel 13 controls sessions, ingestion, version activation, retrieval and citation authorization. GroqCloud generates answers; private local ONNX models generate embeddings and rerank.

Public demo: https://citepost.syslora.com · English · clearly marked synthetic corpora.

## Run locally

```bash
cp .env.example .env
composer install
php artisan key:generate
npm ci && npm run build
docker compose up -d db redis models
php artisan migrate
php artisan lab:seed
php artisan serve --host=127.0.0.1 --port=8138
# Separate terminals:
php artisan horizon
php artisan schedule:work
```

Set `GROQ_API_KEY` in `.env`, never in browser code. If a shell variable with that name overrides the file, start PHP commands with `env -u GROQ_API_KEY`. Model downloads happen during the Python image build. PostgreSQL/Redis/model ports are localhost-only locally; production removes them.

## Demonstrate

Choose a corpus, ask a guided question, click a citation, inspect candidates/context/usage, compare retrieval and try an unanswerable question. Upload a text-based PDF to see real ingestion stages. Reindex keeps the current index until completion. Duplicate uploads reuse the existing version. Failure injection is explicitly simulated.

## Verify and measure

```bash
php artisan test --compact
vendor/bin/pint --test
php artisan lab:calibrate
php artisan lab:evaluate --answers
```

`resources/evaluation.json` separates 10 tuning questions from 60 held-out cases (50 answerable, 10 unanswerable). Calibration also uses five separate negative tuning cases. `docs/calibration.json` records the chosen threshold; `docs/evaluation-latest.json` records actual results/configuration. Gate precision/recall is not a verdict on LLM correctness. Citation validity checks IDs against supplied context, not claim entailment. No claim of universal hybrid superiority.

## Limits

English models, heuristic paragraph/page chunking, max 5 MB/60 text-based PDF pages and three uploads per two-hour session. No OCR, complex table interpretation or token streaming. Sources open at the PDF page with a highlighted text passage beside it; PDF pixels are not highlighted. Volume-backed storage uses Laravel's Storage abstraction; an S3 deployment is a future extension. Multi-hop query decomposition, embedding-provider switch UI, generalized document update workflows and a video walkthrough are not included. The local reranker is allowed to fall back explicitly. Prompt injection remains a risk despite untrusted context boundaries and server-side authorization.

See `docs/ARCHITECTURE.md`, `SECURITY.md`, `docs/RUNBOOK.md` and the measured evaluation for operational details.
