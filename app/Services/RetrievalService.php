<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class RetrievalService
{
    public function __construct(private ModelService $models) {}

    public function run(string $workspace, string $collection, string $question, string $mode = 'hybrid_rerank', string $failure = 'none'): array
    {
        $start = microtime(true);
        $timings = [];
        $normal = trim(preg_replace('/\s+/u', ' ', $question));
        $pinned = DB::table('documents')->where('workspace_id', $workspace)->where('collection', $collection)->whereNull('deleted_at')->pluck('active_version')->filter()->all();
        $base = DB::table('chunks as c')->join('document_versions as pv', 'pv.id', '=', 'c.version_id')->join('documents as d', 'd.id', '=', 'pv.document_id')->whereIn('c.version_id', $pinned)->join('document_versions as v', 'v.id', '=', 'c.version_id')->where('c.workspace_id', $workspace)->where('c.collection', $collection)->whereNull('d.deleted_at')->where('v.status', 'ready');
        $cols = ['c.id', 'c.version_id', 'c.section', 'c.page', 'c.content', 'c.tokens', 'd.title', 'v.number', 'v.index_version'];
        $t = microtime(true);
        $vectors = $this->models->embed([$normal], true);
        $timings['query_embedding'] = round((microtime(true) - $t) * 1000, 2);
        $t = microtime(true);
        if (DB::getDriverName() === 'pgsql') {
            $vector = (clone $base)->select($cols)->selectRaw('1 - (c.embedding <=> ?::vector) as vector_score', [json_encode($vectors[0])])->orderByRaw('c.embedding <=> ?::vector', [json_encode($vectors[0])])->limit(12)->get()->map(fn ($c) => (array) $c)->all();
        } else {
            $vector = (clone $base)->select(array_merge($cols, ['c.embedding']))->get()->map(function ($c) use ($vectors) {
                $a = (array) $c;
                $emb = json_decode($a['embedding'], true);
                unset($a['embedding']);
                $a['vector_score'] = array_sum(array_map(fn ($x, $y) => $x * $y, $emb, $vectors[0]));

                return $a;
            })->sortByDesc('vector_score')->take(12)->values()->all();
        }
        $timings['vector_search'] = round((microtime(true) - $t) * 1000, 2);
        $t = microtime(true);
        preg_match_all('/\b(?:[A-Z]{2,}[A-Z0-9]*[-_][A-Z0-9_.-]+|\d+\.\d+)\b/', $normal, $ids);
        $identifiers = $ids[0];
        if (DB::getDriverName() === 'pgsql') {
            $lex = (clone $base)->select($cols)->selectRaw("ts_rank_cd(c.search, websearch_to_tsquery('english', ?)) AS lexical_score", [$normal])->where(function ($q) use ($normal, $identifiers) {
                $q->whereRaw("c.search @@ websearch_to_tsquery('english', ?)", [$normal]);
                foreach ($identifiers as $id) {
                    $q->orWhere('c.content', 'ilike', '%'.$id.'%');
                }
            })->orderByDesc('lexical_score')->limit(12)->get()->map(fn ($c) => (array) $c)->all();
        } else {
            $terms = preg_split('/\W+/u', strtolower($normal));
            $lex = (clone $base)->select($cols)->get()->map(function ($c) use ($terms) {
                $a = (array) $c;
                $a['lexical_score'] = count(array_filter($terms, fn ($x) => strlen($x) > 2 && str_contains(strtolower($c->content), $x)));

                return $a;
            })->filter(fn ($c) => $c['lexical_score'] > 0)->sortByDesc('lexical_score')->take(12)->values()->all();
        }
        $timings['lexical_search'] = round((microtime(true) - $t) * 1000, 2);
        $t = microtime(true);
        $all = [];
        foreach (['vector' => $vector, 'lexical' => $lex] as $source => $list) {
            foreach ($list as $i => $c) {
                $id = $c['id'];
                $all[$id] = array_merge($all[$id] ?? [], $c, [$source.'_rank' => $i + 1]);
            }
        }
        foreach ($all as &$c) {
            $c['source'] = isset($c['vector_rank'],$c['lexical_rank']) ? 'BOTH' : (isset($c['vector_rank']) ? 'VECTOR' : 'LEXICAL');
            $c['fusion_score'] = (isset($c['vector_rank']) ? 1 / (60 + $c['vector_rank']) : 0) + (isset($c['lexical_rank']) ? 1 / (60 + $c['lexical_rank']) : 0);
            $c['status'] = 'excluded';
        }unset($c);
        $fused = array_values($all);
        usort($fused, fn ($a, $b) => $b['fusion_score'] <=> $a['fusion_score']);
        foreach ($fused as $i => &$c) {
            $c['fusion_rank'] = $i + 1;
        }unset($c);
        $timings['fusion'] = round((microtime(true) - $t) * 1000, 2);
        $fallback = false;
        $t = microtime(true);
        if ($mode === 'vector') {
            usort($fused, fn ($a, $b) => ($a['vector_rank'] ?? 999) <=> ($b['vector_rank'] ?? 999));
            $ranked = array_values(array_filter($fused, fn ($c) => isset($c['vector_rank'])));
        } elseif ($mode === 'lexical') {
            usort($fused, fn ($a, $b) => ($a['lexical_rank'] ?? 999) <=> ($b['lexical_rank'] ?? 999));
            $ranked = array_values(array_filter($fused, fn ($c) => isset($c['lexical_rank'])));
        } else {
            $ranked = $fused;
        }
        if ($mode === 'hybrid_rerank' && $ranked) {
            try {
                if ($failure === 'reranker_timeout') {
                    throw new \RuntimeException('Simulated timeout');
                }$scores = $this->models->rerank($normal, array_slice($ranked, 0, 16));
                $map = array_column($scores, 'score', 'id');
                foreach ($ranked as &$c) {
                    $c['rerank_score'] = $map[$c['id']] ?? 0;
                }unset($c);
                usort($ranked, fn ($a, $b) => $b['rerank_score'] <=> $a['rerank_score']);
                foreach ($ranked as $i => &$c) {
                    $c['rerank_rank'] = $i + 1;
                }unset($c);
            } catch (\Throwable) {
                $fallback = true;
            }
        }
        $timings['rerank'] = round((microtime(true) - $t) * 1000, 2);
        if ($failure === 'empty_retrieval') {
            $ranked = [];
        }
        $top = $ranked[0] ?? null;
        $exactOk = ! $identifiers || ($top && count(array_filter($identifiers, fn ($id) => str_contains(strtolower($top['content']), strtolower($id)))) === count($identifiers));
        $sufficient = $top && $exactOk && ($failure !== 'weak_retrieval') && (isset($top['rerank_score']) ? $top['rerank_score'] >= config('lab.evidence_threshold') : ($top['vector_score'] ?? 0) >= 0.70);
        $context = [];
        $budget = 0;
        foreach (array_slice($ranked, 0, 4) as $c) {
            if ($budget + $c['tokens'] > 1600) {
                continue;
            }$budget += $c['tokens'];
            $c['source_id'] = 'S'.(count($context) + 1);
            $context[] = $c;
        }
        $included = array_column($context, 'source_id', 'id');
        foreach ($ranked as &$c) {
            $c['status'] = isset($included[$c['id']]) ? 'included' : 'excluded';
            $c['reason'] = isset($included[$c['id']]) ? 'Within final context budget' : 'Outside top 4 / token budget';
        }unset($c);

        return ['original' => $question, 'normalized' => $normal, 'identifiers' => $identifiers, 'candidates' => $ranked, 'context' => $context, 'evidence' => ['sufficient' => (bool) $sufficient, 'threshold' => config('lab.evidence_threshold'), 'exact_identifier_match' => $exactOk, 'policy' => 'cross-encoder threshold + exact identifier coverage; scores are not truth probabilities'], 'timings' => $timings, 'retrieval_ms' => round((microtime(true) - $start) * 1000, 2), 'fallback' => $fallback, 'failure' => $failure, 'simulated' => $failure !== 'none', 'cache_hit' => false, 'config' => ['version' => config('lab.config_version'), 'top_k' => 12, 'rrf_k' => 60, 'context_k' => 4, 'context_budget' => 1600, 'mode' => $mode, 'embedding' => config('lab.embedding_model'), 'dimensions' => 384, 'reranker' => $mode === 'hybrid_rerank' && ! $fallback ? config('lab.reranker') : null, 'chunker' => 'structural-v2'], 'indexes' => array_values(array_unique(array_column($context, 'index_version')))];
    }
}
