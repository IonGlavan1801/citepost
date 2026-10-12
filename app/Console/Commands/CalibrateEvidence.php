<?php

namespace App\Console\Commands;

use App\Services\DemoService;
use App\Services\RetrievalService;
use Illuminate\Console\Command;

class CalibrateEvidence extends Command
{
    protected $signature = 'lab:calibrate';

    protected $description = 'Choose an evidence threshold using only separate tuning cases';

    public function handle(): int
    {
        $data = json_decode(file_get_contents(resource_path('evaluation.json')), true);
        $cases = array_values(array_filter($data['cases'], fn ($c) => $c['split'] === 'tuning'));
        foreach (['Who invented quantum teleportation?', 'How long is the extended insurance coverage?', 'What is the price of an Atlas subscription?', 'Who won the presidential election?', 'What is the warranty period?'] as $q) {
            $cases[] = ['question' => $q, 'collection' => 'manual', 'answerable' => false];
        }
        $scores = [];
        foreach ($cases as $c) {
            $trace = app(RetrievalService::class)->run(app(DemoService::class)->template(), $c['collection'], $c['question']);
            $scores[] = ['question' => $c['question'], 'answerable' => $c['answerable'], 'score' => $trace['candidates'][0]['rerank_score'] ?? 0];
        }
        $best = -1;
        $threshold = .12;
        foreach ([.001, .002, .005, .01, .02, .05, .1, .12, .2, .3, .5] as $t) {
            $pos = 0;
            $neg = 0;
            $np = 0;
            $nn = 0;
            foreach ($scores as $s) {
                if ($s['answerable']) {
                    $np++;
                    $pos += ($s['score'] >= $t) ? 1 : 0;
                } else {
                    $nn++;
                    $neg += ($s['score'] < $t) ? 1 : 0;
                }
            }$accuracy = ($pos / max(1, $np) + $neg / max(1, $nn)) / 2;
            if ($accuracy > $best) {
                $best = $accuracy;
                $threshold = $t;
            }
        }
        file_put_contents(base_path('docs/calibration.json'), json_encode(['threshold' => $threshold, 'balanced_accuracy' => $best, 'split' => 'tuning only; never held-out evaluation', 'timestamp' => now()->toIso8601String(), 'cases' => $scores], JSON_PRETTY_PRINT));
        $this->info('Recommended threshold: '.$threshold.'; tuning balanced accuracy: '.$best);

        return self::SUCCESS;
    }
}
