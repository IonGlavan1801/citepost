<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }
        Schema::create('workspaces', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('workspace_id')->index();
            $t->string('collection');
            $t->string('title');
            $t->string('checksum');
            $t->uuid('active_version')->nullable();
            $t->string('status');
            $t->boolean('synthetic')->default(false);
            $t->softDeletes();
            $t->timestamps();
            $t->unique(['workspace_id', 'collection', 'checksum']);
        });
        Schema::create('document_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('document_id')->index();
            $t->uuid('index_version');
            $t->integer('number');
            $t->string('path');
            $t->string('status');
            $t->integer('pages')->default(0);
            $t->json('steps');
            $t->json('metadata');
            $t->timestamps();
        });
        Schema::create('chunks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('workspace_id')->index();
            $t->uuid('version_id')->index();
            $t->string('collection');
            $t->string('section');
            $t->integer('page');
            $t->integer('ordinal');
            $t->text('content');
            $t->string('content_hash');
            $t->integer('tokens');
            $t->text('embedding')->nullable();
            $t->unique(['version_id', 'ordinal']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE chunks ALTER COLUMN embedding TYPE vector(384) USING embedding::vector');
            DB::statement("ALTER TABLE chunks ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('english', section || ' ' || content)) STORED");
            DB::statement('CREATE INDEX chunks_search_idx ON chunks USING gin(search)');
        }
        Schema::create('queries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('workspace_id')->index();
            $t->text('question');
            $t->string('collection');
            $t->string('mode');
            $t->json('trace');
            $t->json('answer');
            $t->string('feedback')->nullable();
            $t->timestamps();
        });
        Schema::create('evaluation_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->json('results');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['evaluation_runs', 'queries', 'chunks', 'document_versions', 'documents', 'workspaces'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
