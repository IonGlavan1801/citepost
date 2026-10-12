<?php

namespace Tests\Feature;

use App\Services\AnswerService;
use App\Services\DemoService;
use App\Services\IngestionService;
use App\Services\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): string
    {
        return app(DemoService::class)->create();
    }

    private function seedChunk(string $workspace, string $content, string $doc = 'doc', string $version = 'ver'): void
    {
        DB::table('documents')->insert(['id' => $doc, 'workspace_id' => $workspace, 'collection' => 'manual', 'title' => 'Test source', 'checksum' => $doc, 'active_version' => $version, 'status' => 'ready']);
        DB::table('document_versions')->insert(['id' => $version, 'document_id' => $doc, 'index_version' => 'idx-'.$version, 'number' => 1, 'path' => 'test.pdf', 'status' => 'ready', 'steps' => '[]', 'metadata' => '{}']);
        DB::table('chunks')->insert(['id' => 'chunk-'.$doc, 'workspace_id' => $workspace, 'version_id' => $version, 'collection' => 'manual', 'section' => 'Test', 'page' => 1, 'ordinal' => 0, 'content' => $content, 'content_hash' => hash('sha256', $content), 'tokens' => 20, 'embedding' => json_encode([1, 0, 0])]);
    }

    public function test_unknown_or_unused_citations_are_rejected(): void
    {
        $a = app(AnswerService::class);
        $ctx = [['source_id' => 'S1', 'id' => 'c1']];
        $this->assertFalse($a->validate(['sentences' => [['text' => 'Invented', 'source_ids' => ['S99']]]], $ctx));
        $this->assertFalse($a->validate(['sentences' => [['text' => 'No citation', 'source_ids' => []]]], $ctx));
        $this->assertTrue($a->validate(['sentences' => [['text' => 'Supported', 'source_ids' => ['S1']]]], $ctx));
    }

    public function test_abstention_does_not_call_the_llm(): void
    {
        Http::fake();
        $a = app(AnswerService::class)->generate('Unknown', ['evidence' => ['sufficient' => false]]);
        $this->assertSame('insufficient', $a['status']);
        Http::assertNothingSent();
    }

    public function test_isolation_prevents_cross_workspace_retrieval_in_one_thousand_queries(): void
    {
        $a = $this->workspace();
        $b = $this->workspace();
        $this->seedChunk($b, 'Secret ERR_AUTH_1042 token credentials');
        Http::fake(['*/embed' => Http::response(['vectors' => [[1, 0, 0]]])]);
        for ($i = 0; $i < 1000; $i++) {
            $t = app(RetrievalService::class)->run($a, 'manual', 'ERR_AUTH_1042', 'vector');
            $this->assertSame([], $t['context']);
            $this->assertSame([], $t['candidates']);
        }
    }

    public function test_source_and_history_endpoints_require_the_owning_workspace(): void
    {
        $a = $this->workspace();
        $b = $this->workspace();
        $this->seedChunk($b, 'Secret');
        Storage::fake();
        Storage::put('test.pdf', '%PDF-data');
        $this->withSession(['workspace' => $a])->get('/api/sources/ver')->assertNotFound();
        DB::table('queries')->insert(['id' => 'q', 'workspace_id' => $b, 'question' => 'Private', 'collection' => 'manual', 'mode' => 'vector', 'trace' => '{}', 'answer' => '{}']);
        $this->get('/api/queries/q')->assertNotFound();
    }

    public function test_reranker_failure_is_explicit_and_preserves_fused_context(): void
    {
        $a = $this->workspace();
        $this->seedChunk($a, 'ERR_AUTH_1042 token has expired');
        Http::fake(['*/embed' => Http::response(['vectors' => [[1, 0, 0]]])]);
        $t = app(RetrievalService::class)->run($a, 'manual', 'ERR_AUTH_1042', 'hybrid_rerank', 'reranker_timeout');
        $this->assertTrue($t['fallback']);
        $this->assertTrue($t['simulated']);
        $this->assertSame('chunk-doc', $t['context'][0]['id']);
        $this->assertNull($t['config']['reranker']);
    }

    public function test_unknown_exact_code_is_not_answered_from_similar_text(): void
    {
        $a = $this->workspace();
        $this->seedChunk($a, 'ERR_AUTH_1042 token has expired');
        Http::fake(['*/embed' => Http::response(['vectors' => [[1, 0, 0]]])]);
        $t = app(RetrievalService::class)->run($a, 'manual', 'ERR_MAGIC_9999', 'vector');
        $this->assertFalse($t['evidence']['sufficient']);
    }

    public function test_upload_checks_pdf_signature_and_deduplicates(): void
    {
        Queue::fake();
        Storage::fake();
        $w = $this->workspace();
        $this->withSession(['workspace' => $w])->post('/api/documents', ['file' => UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf')])->assertStatus(422);
        $file = UploadedFile::fake()->createWithContent('real.pdf', "%PDF-1.4\nsynthetic bytes");
        $this->post('/api/documents', ['file' => $file])->assertStatus(202);
        $file2 = UploadedFile::fake()->createWithContent('copy.pdf', "%PDF-1.4\nsynthetic bytes");
        $this->post('/api/documents', ['file' => $file2])->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame(1, DB::table('documents')->where('workspace_id', $w)->count());
    }

    public function test_deletion_disables_retrieval_and_source_access(): void
    {
        Storage::fake();
        $w = $this->workspace();
        $this->seedChunk($w, 'Data');
        Storage::put('test.pdf', 'PDF');
        $this->withSession(['workspace' => $w])->deleteJson('/api/documents/doc')->assertOk();
        $this->get('/api/sources/ver')->assertNotFound();
        $this->assertSame(0, DB::table('chunks')->count());
    }

    public function test_structural_chunks_preserve_page_and_section(): void
    {
        $chunks = app(IngestionService::class)->chunk([['page' => 2, 'text' => "14.3 Refunds\n\nPrepaid unused periods are refundable within 14 days."]]);
        $this->assertSame(2, $chunks[0]['page']);
        $this->assertSame('14.3 Refunds', $chunks[0]['section']);
    }

    public function test_reindex_does_not_replace_the_active_version_before_completion(): void
    {
        Queue::fake();
        $w = $this->workspace();
        $this->seedChunk($w, 'Data');
        $this->withSession(['workspace' => $w])->postJson('/api/documents/doc/reindex')->assertAccepted();
        $this->assertSame('ver', DB::table('documents')->where('id', 'doc')->value('active_version'));
        $this->assertSame(2, DB::table('document_versions')->count());
    }

    public function test_purge_removes_expired_documents_vectors_and_queries(): void
    {
        Storage::fake();
        $w = $this->workspace();
        $this->seedChunk($w, 'Data');
        Storage::put('test.pdf', 'PDF');
        DB::table('workspaces')->where('id', $w)->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, app(DemoService::class)->purge());
        $this->assertSame(0, DB::table('chunks')->count());
        $this->assertFalse(Storage::exists('test.pdf'));
    }

    public function test_new_session_state_is_initialized_server_side(): void
    {
        $this->getJson('/api/state')->assertOk()->assertJsonStructure(['documents', 'expires_at', 'model']);
        $this->assertSame(1, DB::table('workspaces')->count());
    }
}
