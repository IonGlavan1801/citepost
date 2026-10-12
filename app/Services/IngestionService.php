<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IngestionService
{
    public function __construct(private ModelService $models) {}

    public function step(string $id, string $stage, float $start): void
    {
        $v = DB::table('document_versions')->where('id', $id)->first();
        $steps = json_decode($v->steps, true);
        $steps[] = ['stage' => $stage, 'ms' => round((microtime(true) - $start) * 1000, 2), 'at' => now()->toIso8601String()];
        DB::table('document_versions')->where('id', $id)->update(['steps' => json_encode($steps), 'status' => $stage, 'updated_at' => now()]);
    }

    public function ingest(string $id): void
    {
        Cache::lock('ingest:'.$id, 180)->block(5, function () use ($id) {
            $v = DB::table('document_versions')->where('id', $id)->first();
            if (! $v || $v->status === 'ready') {
                return;
            }
            $doc = DB::table('documents')->where('id', $v->document_id)->whereNull('deleted_at')->first();
            if (! $doc) {
                return;
            }
            $start = microtime(true);
            $bytes = Storage::get($v->path);
            if (! str_starts_with($bytes, '%PDF-')) {
                throw new \RuntimeException('Invalid PDF signature');
            }
            $this->step($id, 'validated', $start);
            $start = microtime(true);
            $parsed = $this->models->parse($bytes);
            $this->step($id, 'parsed', $start);
            $start = microtime(true);
            $chunks = $this->chunk($parsed['pages']);
            $this->step($id, 'structured', $start);
            $this->step($id, 'chunked', $start);
            if (! $chunks) {
                throw new \RuntimeException('No readable text. OCR is not supported.');
            }
            $start = microtime(true);
            $vectors = [];
            foreach (array_chunk($chunks, 64) as $batch) {
                $vectors = array_merge($vectors, $this->models->embed(array_map(fn ($c) => $c['section'].PHP_EOL.$c['content'], $batch)));
            }
            $this->step($id, 'embedded', $start);
            if (count($vectors) !== count($chunks)) {
                throw new \RuntimeException('Incomplete embedding batch');
            }
            $start = microtime(true);
            DB::transaction(function () use ($id, $doc, $chunks, $vectors, $parsed) {
                DB::table('documents')->where('id', $doc->id)->lockForUpdate()->first();
                if (DB::table('documents')->where('id', $doc->id)->whereNotNull('deleted_at')->exists()) {
                    throw new \RuntimeException('Document deactivated');
                }
                DB::table('chunks')->where('version_id', $id)->delete();
                foreach ($chunks as $i => $c) {
                    if (count($vectors[$i]) !== 384) {
                        throw new \RuntimeException('Embedding dimension mismatch');
                    }
                    DB::table('chunks')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $doc->workspace_id, 'version_id' => $id, 'collection' => $doc->collection, 'ordinal' => $i, 'section' => $c['section'], 'page' => $c['page'], 'content' => $c['content'], 'content_hash' => hash('sha256', $c['content']), 'tokens' => (int) ceil(strlen($c['content']) / 4), 'embedding' => json_encode($vectors[$i])]);
                }
                DB::table('document_versions')->where('id', $id)->update(['pages' => count($parsed['pages']), 'metadata' => json_encode(['parser' => 'pypdf', 'structure' => 'heuristic headings + paragraphs', 'chunker' => 'structural-v2', 'embedding' => config('lab.embedding_model'), 'dimensions' => 384]), 'status' => 'indexed']);
                DB::table('documents')->where('id', $doc->id)->update(['active_version' => $id, 'status' => 'ready', 'updated_at' => now()]);
            });
            $this->step($id, 'indexed', $start);
            $this->step($id, 'ready', $start);
        });
    }

    public function chunk(array $pages): array
    {
        $result = [];
        foreach ($pages as $page) {
            $text = trim(preg_replace('/[ \t]+/u', ' ', $page['text']));
            $text = preg_replace('/^CITEPOST \/ SYNTHETIC DEMO CORPUS\s*/u', '', $text);
            $text = preg_replace('/Atlas (product manual|service contract|company policies) \/ page \d+\s*$/u', '', $text);
            $lines = preg_split('/\n+/u', $text);
            $heading = mb_substr($lines[0] ?? 'Untitled section', 0, 180);
            $parts = preg_split('/\n\s*\n/u', $text);
            if (count($parts) === 1) {
                $parts = [$text];
            }
            foreach ($parts as $part) {
                $part = trim($part);
                if (mb_strlen($part) < 20) {
                    continue;
                }
                $words = preg_split('/\s+/u', $part);
                foreach (array_chunk($words, 180) as $slice) {
                    $result[] = ['section' => $heading, 'page' => $page['page'], 'content' => implode(' ', $slice)];
                }
            }
        }

        return $result;
    }
}
