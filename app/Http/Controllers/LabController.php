<?php

namespace App\Http\Controllers;

use App\Jobs\IngestDocument;
use App\Services\AnswerService;
use App\Services\DemoService;
use App\Services\RetrievalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LabController extends Controller
{
    public function workspace(Request $request): string
    {
        $id = $request->session()->get('workspace');
        if (! $id || ! DB::table('workspaces')->where('id', $id)->where('expires_at', '>', now())->exists()) {
            $limit = 'sessions:'.$request->ip();
            abort_if(RateLimiter::tooManyAttempts($limit, 6), 429, 'Session creation limit reached. Try later.');
            RateLimiter::hit($limit, 3600);
            $id = app(DemoService::class)->create();
            $request->session()->put('workspace', $id);
            $request->session()->regenerate();
        }

        return $id;
    }

    public function state(Request $request): JsonResponse
    {
        $id = $this->workspace($request);
        $docs = DB::table('documents')->where('workspace_id', $id)->whereNull('deleted_at')->get()->map(function ($d) {
            $v = DB::table('document_versions')->where('document_id', $d->id)->orderByDesc('number')->first();

            return ['id' => $d->id, 'collection' => $d->collection, 'title' => $d->title, 'status' => $d->status, 'synthetic' => (bool) $d->synthetic, 'active_version' => $d->active_version, 'ingestion' => $v ? ['id' => $v->id, 'status' => $v->status, 'pages' => $v->pages, 'number' => $v->number, 'steps' => json_decode($v->steps, true), 'metadata' => json_decode($v->metadata, true)] : null];
        });
        $ev = DB::table('evaluation_runs')->latest()->first();
        $summary = $ev ? json_decode($ev->results, true) : null;
        if ($summary) {
            unset($summary['runs']);
        }

        return response()->json(['documents' => $docs, 'groq_configured' => (bool) config('lab.groq_key'), 'model' => config('lab.groq_model'), 'expires_at' => DB::table('workspaces')->where('id', $id)->value('expires_at'), 'evaluation' => $summary]);
    }

    public function upload(Request $request): JsonResponse
    {
        $id = $this->workspace($request);
        $request->validate(['file' => 'required|file|mimes:pdf|max:5120']);
        abort_if(DB::table('documents')->where('workspace_id', $id)->where('synthetic', false)->whereNull('deleted_at')->count() >= 3, 422, 'Maximum three uploaded documents per session.');
        $file = $request->file('file');
        abort_unless(str_starts_with(file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-'), 422, 'Invalid PDF signature');
        $hash = hash_file('sha256', $file->getRealPath());
        $same = DB::table('documents')->where('workspace_id', $id)->where('collection', 'upload')->where('checksum', $hash)->first();
        if ($same) {
            return response()->json(['id' => $same->id, 'duplicate' => true, 'message' => 'Same checksum: existing version reused, no duplicate embeddings.']);
        }
        $doc = (string) Str::uuid();
        $version = (string) Str::uuid();
        $path = $file->store('uploads/'.$id);
        DB::table('documents')->insert(['id' => $doc, 'workspace_id' => $id, 'collection' => 'upload', 'title' => mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 180), 'checksum' => $hash, 'status' => 'uploaded', 'synthetic' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('document_versions')->insert(['id' => $version, 'document_id' => $doc, 'index_version' => (string) Str::uuid(), 'number' => 1, 'path' => $path, 'status' => 'uploaded', 'steps' => json_encode([['stage' => 'uploaded', 'ms' => 0]]), 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        IngestDocument::dispatch($version);

        return response()->json(['id' => $doc, 'version' => $version, 'duplicate' => false], 202);
    }

    public function delete(Request $request, string $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $doc = DB::table('documents')->where('id', $id)->where('workspace_id', $workspace)->whereNull('deleted_at')->first();
        abort_unless($doc, 404);
        DB::table('documents')->where('id', $id)->update(['deleted_at' => now(), 'status' => 'deleted']);
        foreach (DB::table('document_versions')->where('document_id', $id)->get() as $v) {
            if (! $doc->synthetic) {
                Storage::delete($v->path);
            }DB::table('chunks')->where('version_id', $v->id)->delete();
        }

        return response()->json(['status' => 'deleted', 'message' => 'Removed from retrieval; source files and embeddings purged. Historical citation locators remain, source access is disabled.']);
    }

    public function reindex(Request $request, string $id): JsonResponse
    {
        $doc = DB::table('documents')->where('id', $id)->where('workspace_id', $this->workspace($request))->whereNull('deleted_at')->first();
        abort_unless($doc, 404);
        $old = DB::table('document_versions')->where('document_id', $id)->orderByDesc('number')->first();
        abort_unless($old && $old->status === 'ready', 409, 'An ingestion must finish before another reindex.');
        $v = (string) Str::uuid();
        DB::table('document_versions')->insert(['id' => $v, 'document_id' => $id, 'index_version' => (string) Str::uuid(), 'number' => $old->number + 1, 'path' => $old->path, 'status' => 'uploaded', 'steps' => json_encode([['stage' => 'uploaded', 'ms' => 0]]), 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        IngestDocument::dispatch($v);

        return response()->json(['version' => $v], 202);
    }

    public function query(Request $request): JsonResponse
    {
        $input = $request->validate(['question' => 'required|string|min:3|max:1000', 'collection' => 'required|in:manual,contract,policies,upload', 'mode' => 'required|in:vector,lexical,hybrid,hybrid_rerank', 'failure' => 'nullable|in:none,reranker_timeout,llm_timeout,empty_retrieval,weak_retrieval', 'compare' => 'boolean']);
        $workspace = $this->workspace($request);
        abort_if(DB::table('queries')->where('workspace_id', $workspace)->count() >= 60, 429, 'This demo session has reached its 60-query limit.');
        $start = microtime(true);
        $id = (string) Str::uuid();
        try {
            $trace = app(RetrievalService::class)->run($workspace, $input['collection'], $input['question'], $input['mode'], $input['failure'] ?? 'none');
        } catch (\Throwable) {
            return response()->json(['message' => 'Retrieval provider unavailable. Please retry; no answer was generated.'], 503);
        }
        $answer = app(AnswerService::class)->generate($input['question'], $trace);
        $trace['total_ms'] = round((microtime(true) - $start) * 1000, 2);
        $trace['correlation_id'] = $id;
        $trace['prompt_template'] = AnswerService::PROMPT;
        if ($input['compare'] ?? false) {
            $trace['comparison'] = [];
            foreach (['vector', 'lexical', 'hybrid', 'hybrid_rerank'] as $mode) {
                $other = app(RetrievalService::class)->run($workspace, $input['collection'], $input['question'], $mode);
                $trace['comparison'][] = ['mode' => $mode, 'ms' => $other['retrieval_ms'], 'evidence' => $other['evidence']['sufficient'], 'top' => array_slice($other['candidates'], 0, 5)];
            }
        }
        DB::table('queries')->insert(['id' => $id, 'workspace_id' => $workspace, 'question' => $input['question'], 'collection' => $input['collection'], 'mode' => $input['mode'], 'trace' => json_encode($trace), 'answer' => json_encode($answer), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id, 'question' => $input['question'], 'trace' => $trace, 'answer' => $answer]);
    }

    public function source(Request $request, string $version): mixed
    {
        $v = DB::table('document_versions as v')->join('documents as d', 'd.id', '=', 'v.document_id')->where('v.id', $version)->where('d.workspace_id', $this->workspace($request))->whereNull('d.deleted_at')->select('v.path')->first();
        abort_unless($v && Storage::exists($v->path), 404);

        return response(Storage::get($v->path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="source.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function historical(Request $request, string $id): JsonResponse
    {
        $q = DB::table('queries')->where('id', $id)->where('workspace_id', $this->workspace($request))->first();
        abort_unless($q, 404);

        return response()->json(['id' => $id, 'question' => $q->question, 'trace' => json_decode($q->trace, true), 'answer' => json_decode($q->answer, true)]);
    }

    public function feedback(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['feedback' => 'required|in:helpful,unsupported']);
        $n = DB::table('queries')->where('id', $id)->where('workspace_id', $this->workspace($request))->update($data);
        abort_unless($n, 404);

        return response()->json(['saved' => true]);
    }
}
