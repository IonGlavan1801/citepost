<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoService
{
    public function seed(): void
    {
        DB::table('workspaces')->insertOrIgnore(['id' => '00000000-0000-0000-0000-000000000001', 'created_at' => now(), 'updated_at' => now()]);
        $corpus = json_decode(file_get_contents(resource_path('corpus.json')), true);
        foreach ($corpus as $collection => $data) {
            $checksum = hash('sha256', json_encode($data));
            $existing = DB::table('documents')->where('workspace_id', $this->template())->where('collection', $collection)->where('checksum', $checksum)->first();
            if ($existing && $existing->status === 'ready') {
                continue;
            }
            $pdf = Http::timeout(30)->post(config('lab.model_url').'/synthetic-pdf', $data)->throw()->body();
            if (! $existing) {
                $doc = (string) Str::uuid();
                $version = (string) Str::uuid();
                $path = 'corpus/'.$checksum.'.pdf';
                Storage::put($path, $pdf);
                DB::table('documents')->insert(['id' => $doc, 'workspace_id' => $this->template(), 'collection' => $collection, 'title' => $data['title'], 'checksum' => $checksum, 'status' => 'uploaded', 'synthetic' => true, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('document_versions')->insert(['id' => $version, 'document_id' => $doc, 'index_version' => (string) Str::uuid(), 'number' => 1, 'path' => $path, 'status' => 'uploaded', 'steps' => json_encode([['stage' => 'uploaded', 'ms' => 0]]), 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $version = DB::table('document_versions')->where('document_id', $existing->id)->value('id');
            }
            app(IngestionService::class)->ingest($version);
        }
    }

    public function template(): string
    {
        return '00000000-0000-0000-0000-000000000001';
    }

    public function create(): string
    {
        $workspace = (string) Str::uuid();
        DB::transaction(function () use ($workspace) {
            DB::table('workspaces')->insert(['id' => $workspace, 'expires_at' => now()->addHours(2), 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('documents')->where('workspace_id', $this->template())->where('status', 'ready')->get() as $d) {
                $doc = (array) $d;
                $old = $d->active_version;
                $doc['id'] = (string) Str::uuid();
                $doc['workspace_id'] = $workspace;
                $doc['active_version'] = (string) Str::uuid();
                DB::table('documents')->insert($doc);
                $version = (array) DB::table('document_versions')->where('id', $old)->first();
                $version['id'] = $doc['active_version'];
                $version['document_id'] = $doc['id'];
                DB::table('document_versions')->insert($version);
                foreach (DB::table('chunks')->where('version_id', $old)->get() as $c) {
                    $chunk = (array) $c;
                    unset($chunk['search']);
                    $chunk['id'] = (string) Str::uuid();
                    $chunk['workspace_id'] = $workspace;
                    $chunk['version_id'] = $doc['active_version'];
                    DB::table('chunks')->insert($chunk);
                }
            }
        });

        return $workspace;
    }

    public function purge(): int
    {
        $ids = DB::table('workspaces')->whereNotNull('expires_at')->where('expires_at', '<', now())->pluck('id');
        foreach ($ids as $id) {
            $docs = DB::table('documents')->where('workspace_id', $id)->get();
            foreach ($docs as $d) {
                $versions = DB::table('document_versions')->where('document_id', $d->id)->get();
                foreach ($versions as $v) {
                    if (! $d->synthetic) {
                        Storage::delete($v->path);
                    }
                }DB::table('document_versions')->where('document_id', $d->id)->delete();
            }
            DB::table('chunks')->where('workspace_id', $id)->delete();
            DB::table('queries')->where('workspace_id', $id)->delete();
            DB::table('documents')->where('workspace_id', $id)->delete();
            DB::table('workspaces')->where('id', $id)->delete();
        }

        return count($ids);
    }
}
