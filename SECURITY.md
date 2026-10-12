# Security and privacy boundaries

Public owned demo, synthetic corpora; uploaded PDFs and query traces are scoped to a temporary session. Uploaded passages may be sent to GroqCloud. Do not upload confidential or personal documents to a public portfolio lab. Files, embeddings and traces expire after two hours and the five-minute purge schedule removes them. Explicit deletion removes retrieval entries and source access immediately; old query locators can remain until session expiry.

Workspace identity comes from the server session, not JSON inputs. Source, feedback and historical query endpoints check ownership. Tests include 1,000 negative cross-workspace retrievals. Rate limits distinguish query, upload, session creation and reindex; there is a 60-query session allowance and global 1000-query/day ceiling. These limits mitigate public-demo abuse but are not a provider spending guarantee. Set Groq account spending limits separately.

PDF signature, MIME, size, page and extracted-text limits; no OCR or active-content execution. Parser and ONNX inference live in a private capped container. Only Nginx joins the shared routing network; PostgreSQL/Redis/model service are internal in production. Database runtime role cannot create databases or schemas. Horizon is restricted by its production authorization gate.

Documents are untrusted source data. They cannot choose tools, alter tenant filters or retrieve secrets. Source IDs are controlled; output text renders without HTML. Citation validation proves provenance only; it cannot guarantee every sentence is entailed. Prompt injection is not solved. The included policy contains an adversarial training passage for regression tests.

Secrets are ignored by Git, generated separately for production and configured server-side. CI deploy key can execute only the project deploy script. Backup files contain temporary data and follow the same expiry requirement: deployment backups are deleted after two hours by the scheduled cleanup job. Never publish database dumps.
