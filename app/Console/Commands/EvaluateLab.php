<?php

namespace App\Console\Commands;

use App\Services\AnswerService;
use App\Services\DemoService;
use App\Services\RetrievalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvaluateLab extends Command
{
    protected $signature = 'lab:evaluate {--answers : Also evaluate real Groq answer citation mapping}';

    protected $description = 'Measure retrieval and evidence gate on the held-out synthetic dataset';

    public function handle(): int
    {
        $data = json_decode(file_get_contents(resource_path('evaluation.json')), true);
        $cases = array_values(array_filter($data['cases'], fn ($c) => $c['split'] === 'evaluation'));
        $workspace = app(DemoService::class)->template();
        $runs = [];
        $modeResults = [];
        $tp = 0;
        $fp = 0;
        $fn = 0;
        $valid = 0;
        $generated = 0;
        $cost = 0;
        foreach (['vector', 'lexical', 'hybrid', 'hybrid_rerank'] as $mode) {
            $hits = 0;
            $mrr = 0;
            $answerables = 0;
            $lat = [];
            foreach ($cases as $case) {
                $trace = app(RetrievalService::class)->run($workspace, $case['collection'], $case['question'], $mode);
                $lat[] = $trace['retrieval_ms'];
                $rank = null;
                foreach ($trace['candidates'] as $i => $c) {
                    if ($case['page'] !== null && $c['page'] === $case['page']) {
                        $rank = $i + 1;
                        break;
                    }
                }
                if ($case['answerable']) {
                    $answerables++;
                    $hits += ($rank !== null && $rank <= 5) ? 1 : 0;
                    $mrr += $rank ? 1 / $rank : 0;
                }
                $row = ['question' => $case['question'], 'collection' => $case['collection'], 'answerable' => $case['answerable'], 'expected_page' => $case['page'], 'relevant_rank' => $rank, 'evidence' => $trace['evidence'], 'retrieval_ms' => $trace['retrieval_ms'], 'top5' => array_map(fn ($c) => ['page' => $c['page'], 'section' => $c['section'], 'vector_score' => $c['vector_score'] ?? null, 'rerank_score' => $c['rerank_score'] ?? null], array_slice($trace['candidates'], 0, 5))];
                if ($mode === 'hybrid_rerank') {
                    $abstains = ! $trace['evidence']['sufficient'];
                    $tp += (! $case['answerable'] && $abstains) ? 1 : 0;
                    $fp += ($case['answerable'] && $abstains) ? 1 : 0;
                    $fn += (! $case['answerable'] && ! $abstains) ? 1 : 0;
                    if ($this->option('answers')) {
                        $a = app(AnswerService::class)->generate($case['question'], $trace);
                        $row['answer'] = $a;
                        $cost += $a['usage']['estimated_cost'];
                        if ($a['status'] === 'grounded') {
                            $generated++;
                            $valid += app(AnswerService::class)->validate($a, $trace['context']) ? 1 : 0;
                        }
                    }
                }
                $runs[$mode][] = $row;
            }
            sort($lat);
            $modeResults[] = ['mode' => $mode, 'recall5' => $hits / max(1, $answerables), 'mrr' => $mrr / max(1, $answerables), 'p50_ms' => $lat[(int) floor((count($lat) - 1) * .5)], 'p95_ms' => $lat[(int) ceil((count($lat) - 1) * .95)]];
            $this->info($mode.' complete');
        }
        $result = ['dataset' => $data['version'], 'count' => count($cases), 'answerable_count' => count(array_filter($cases, fn ($c) => $c['answerable'])), 'timestamp' => now()->toIso8601String(), 'modes' => $modeResults, 'abstention_precision' => $tp / max(1, $tp + $fp), 'abstention_recall' => $tp / max(1, $tp + $fn), 'evidence_threshold' => config('lab.evidence_threshold'), 'citation_validity' => $generated ? $valid / $generated : null, 'generated_answers' => $generated, 'answer_cost_estimate' => $cost, 'config_version' => config('lab.config_version'), 'models' => ['embedding' => config('lab.embedding_model'), 'reranker' => config('lab.reranker'), 'llm' => config('lab.groq_model')], 'hardware' => 'ONNX CPU; runtime hardware recorded in RUNBOOK', 'runs' => $runs];
        DB::table('evaluation_runs')->insert(['id' => (string) Str::uuid(), 'results' => json_encode($result), 'created_at' => now(), 'updated_at' => now()]);
        file_put_contents(base_path('docs/evaluation-latest.json'), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Saved measured evaluation.');

        return self::SUCCESS;
    }
}
