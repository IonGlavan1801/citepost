import Alpine from "alpinejs";
import "../css/app.css";
window.Alpine = Alpine;
Alpine.data("lab", () => ({
    state: { documents: [], evaluation: null },
    stateStatus: "loading",
    refreshing: false,
    collection: "manual",
    mode: "hybrid_rerank",
    failure: "none",
    compare: false,
    question: "",
    messages: [],
    selected: null,
    tab: "pipeline",
    mobile: "chat",
    busy: false,
    error: "",
    source: null,
    notice: "",
    uploading: false,
    async init() {
        await this.refresh();
        this.timer = setInterval(() => this.refresh(), 5000);
    },
    destroy() {
        clearInterval(this.timer);
    },
    async api(path, opts = {}) {
        const response = await fetch("/api/" + path, {
            ...opts,
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]',
                ).content,
                ...(opts.body instanceof FormData
                    ? {}
                    : { "Content-Type": "application/json" }),
                ...opts.headers,
            },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || "Request failed");
        return data;
    },
    async refresh() {
        if (this.refreshing) return;
        this.refreshing = true;
        this.stateStatus = "loading";
        try {
            this.state = await this.api("state");
            this.stateStatus = "ready";
        } catch {
            this.stateStatus = "error";
        } finally {
            this.refreshing = false;
        }
    },
    async ask(text) {
        if (this.busy) return;
        const q = text || this.question.trim();
        if (!q) return;
        this.busy = true;
        this.error = "";
        this.question = "";
        this.mobile = "chat";
        try {
            const result = await this.api("queries", {
                method: "POST",
                body: JSON.stringify({
                    question: q,
                    collection: this.collection,
                    mode: this.mode,
                    failure: this.failure,
                    compare: this.compare,
                }),
            });
            this.messages.push(result);
            this.selected = result;
            this.tab = "pipeline";
        } catch (e) {
            this.error = e.message;
        } finally {
            this.busy = false;
            this.$nextTick(() =>
                document
                    .querySelector("#conversation-bottom")
                    ?.scrollIntoView({ behavior: "smooth", block: "nearest" }),
            );
        }
    },
    guided(kind) {
        const qs = {
            manual: [
                "How do I factory reset the gateway?",
                "What should I do for ERR_AUTH_1042?",
                "What is the gateway retail price in Japan?",
            ],
            contract: [
                "When are invoices payable?",
                "What does article 14.3 say about refunds?",
                "What does article 99.9 say?",
            ],
            policies: [
                "How often is team access reviewed?",
                "What is the current meal reimbursement limit under POL-17.4?",
                "What is the 2027 hiring budget?",
            ],
            upload: [
                "What is the main purpose of this document?",
                "Which exact identifiers appear in this document?",
                "What is the company bank account number?",
            ],
        };
        if (kind === 3) {
            this.compare = true;
            kind = 1;
        }
        this.ask(qs[this.collection][kind]);
    },
    async upload(event) {
        const file = event.target.files[0];
        if (!file) return;
        this.uploading = true;
        this.error = "";
        const form = new FormData();
        form.append("file", file);
        try {
            const result = await this.api("documents", {
                method: "POST",
                body: form,
            });
            this.notice = result.duplicate
                ? result.message
                : "Upload accepted. The real ingestion stages appear below.";
            this.collection = "upload";
            await this.refresh();
        } catch (e) {
            this.error = e.message;
        } finally {
            this.uploading = false;
            event.target.value = "";
        }
    },
    async remove(id) {
        try {
            const r = await this.api("documents/" + id, { method: "DELETE" });
            this.notice = r.message;
            await this.refresh();
        } catch (e) {
            this.error = e.message;
        }
    },
    async reindex(id) {
        try {
            await this.api("documents/" + id + "/reindex", { method: "POST" });
            this.notice =
                "Building a new index version. Queries keep the current ready version until activation.";
            await this.refresh();
        } catch (e) {
            this.error = e.message;
        }
    },
    cite(message, id) {
        this.source =
            message.trace.context.find((c) => c.source_id === id) || null;
    },
    async feedback(message, feedback) {
        try {
            await this.api("queries/" + message.id + "/feedback", {
                method: "POST",
                body: JSON.stringify({ feedback }),
            });
            this.notice = "Feedback saved for this temporary session.";
        } catch (e) {
            this.error = e.message;
        }
    },
    label(mode) {
        return (
            {
                vector: "Vector only",
                lexical: "Lexical only",
                hybrid: "Hybrid / RRF",
                hybrid_rerank: "Hybrid + rerank",
            }[mode] || mode
        );
    },
    ms(n) {
        return Number(n || 0).toFixed(0) + " ms";
    },
    money(n) {
        return n === null ? "Unavailable" : "$" + Number(n || 0).toFixed(6);
    },
    get docs() {
        return this.state.documents.filter(
            (d) => d.collection === this.collection,
        );
    },
}));
Alpine.start();
