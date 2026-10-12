# Measured evaluation — 12 October 2026

Source: `evaluation-latest.json`, produced on the production VPS. Dataset `atlas-1`: 50 answerable and 10 unanswerable held-out cases; 10 separate positive tuning cases plus five negative tuning queries are recorded in `calibration.json`.

| Mode | Recall@5 | MRR | Retrieval p95 |
|---|---:|---:|---:|
| Vector only | 98% | 0.957 | 171 ms |
| Lexical only | 54% | 0.525 | 64 ms |
| Hybrid / RRF | 100% | 0.965 | 72 ms |
| Hybrid + rerank | 100% | 0.985 | 860 ms |

GroqCloud gpt-oss-20b generated 50 answers with source IDs valid against the actual context; all 10 unsupported questions received an insufficient-evidence response. No provider errors occurred in the final paced run. Total reported-token generation cost was approximately $0.00255 at the versioned model rate; hosting CPU is excluded.

The retrieval evidence gate alone refused nine of the ten unsupported questions (precision 1.0 / recall 0.9). The answer model abstained on the remaining case. The UI ledger reports gate metrics separately; do not conflate them with end-to-end abstention or factual accuracy.

Citation validity is provenance validation, not entailment or independent answer correctness. This small synthetic English dataset does not establish general enterprise performance. Ranks tied at the same score may differ across runs. Both successful examples and provider failures from the earlier unpaced local run remain in Git history. The final run used six-second spacing before answer calls; these pauses are excluded from retrieval latencies.

Runtime: 4 vCPU VPS on AMD EPYC 9354P host, 16 GB RAM, no GPU; ONNX container limited to 2 CPUs / 2 GB RAM. Models: BAAI/bge-small-en-v1.5 (384 dimensions), FlashRank ms-marco-MiniLM-L-12-v2, structural-v2 chunking, RRF k=60, top-k=12, at most four context passages, threshold 0.001 calibrated only on tuning inputs.

Verification: 16 backend tests / 2044 assertions (including 1000 cross-workspace negative queries and 10 repeated-ingestion attempts), plus browser integration passing locally and publicly for retrieval comparison, citations, source isolation, uploads, reindex, delete and mobile inspector. Historical citations were also manually checked to remain on v1 after a v2 reindex. Syslora's broader existing i18n suite has unrelated stale brief-link expectations and a 320px header overflow; the five new CITEPOST locale routes were individually checked at 390px with no horizontal overflow.

No video walkthrough, OCR, S3 deployment, PDF pixel highlights, token streaming, provider-switch UI or automatic multi-hop decomposition is included in this MVP; these are future extensions from the supplied reference plan.
