<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ModelService
{
    public function embed(array $texts, bool $query = false): array
    {
        return Http::connectTimeout(3)->timeout(60)->retry(2, 500)->post(config('lab.model_url').'/embed', ['texts' => $texts, 'query' => $query])->throw()->json('vectors');
    }

    public function rerank(string $query, array $chunks): array
    {
        return Http::connectTimeout(2)->timeout(10)->post(config('lab.model_url').'/rerank', ['query' => $query, 'passages' => array_map(fn ($c) => ['id' => $c['id'], 'text' => $c['section'].'
'.$c['content']], $chunks)])->throw()->json('results');
    }

    public function parse(string $bytes): array
    {
        return Http::connectTimeout(3)->timeout(45)->attach('file', $bytes, 'source.pdf')->post(config('lab.model_url').'/parse')->throw()->json();
    }
}
