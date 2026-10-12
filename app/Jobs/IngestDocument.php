<?php

namespace App\Jobs;

use App\Services\IngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class IngestDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 150;

    public function __construct(public string $version) {}

    public function backoff(): array
    {
        return [5, 20, 60];
    }

    public function handle(IngestionService $service): void
    {
        $service->ingest($this->version);
    }

    public function failed(?\Throwable $error): void
    {
        DB::table('document_versions')->where('id', $this->version)->update(['status' => 'failed', 'metadata' => json_encode(['error' => 'Parsing or embedding failed after retries. Text-based PDF required.'])]);
        $v = DB::table('document_versions')->where('id', $this->version)->first();
        if ($v) {
            DB::table('documents')->where('id', $v->document_id)->whereNull('active_version')->update(['status' => 'failed']);
        }
    }
}
