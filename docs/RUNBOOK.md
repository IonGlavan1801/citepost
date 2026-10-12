# Operations

Production: `/home/johnny/development/citepost`, immutable archives under `.local/releases/<sha>`, Docker project `citepost`. Port is allocated through syslora-demo-kit, bound to loopback and routed by Traefik Host(citepost.syslora.com).

`./scripts/deploy.sh` serializes releases with flock, archives origin/main, builds images, snapshots DB, runs admin migrations, grants the restricted runtime role, seeds only missing synthetic corpus, starts app/Horizon/scheduler/Nginx and checks health. On failure it restores previous application images. Migrations are not reversed; avoid destructive migrations. Never `down -v`.

`docker compose -p citepost --project-directory <release> --env-file <root>/.env -f <release>/compose.yaml -f <release>/compose.vps.yaml --profile app ps` inspects the active stack. Horizon is private; use `exec -T app php artisan horizon:status` and worker logs. `/api/health` checks HTTP routing; `/up` checks Laravel bootstrap. Check DB/model health and queue status separately.

TTL: scheduler runs `lab:purge` every five minutes. Deployment backup cleanup must run at least every five minutes and delete backups older than two hours, so deleted uploads cannot survive indefinitely in snapshots. Files reside in the named storage volume; the corpus is synthetic and shared safely, upload keys are generated under a workspace path. Deletion deactivates the document and purges its private source and vectors. Do not restore an expired session from backup.

Models are downloaded at image build and run on CPU with a two-thread limit; production VPS is 4 vCPUs (AMD EPYC 9354P host), 16 GB RAM, no GPU; model container is capped at 2 CPUs and 2 GB RAM. Embedding vectors must never be mixed across models/dimensions. Changing the configured embedding implementation requires a new index version and rebuilding each affected document.

Groq configuration: `GROQ_API_KEY`, `GROQ_MODEL=openai/gpt-oss-20b`. Default pricing is tied to this model; update pricing version before switching models. API 401 is authentication, not a normal model permission failure. If local .env seems ignored, check for an inherited shell variable without printing its contents.

Evaluation commands run on the active release and write raw result JSON to that release's docs folder plus the DB ledger. Copy only synthetic evaluation JSON back to the repository for reproducibility; never commit .env or uploads. State API returns the summary, not large raw runs.
