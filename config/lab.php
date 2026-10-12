<?php

return [
    'model_url' => env('MODEL_SERVICE_URL', 'http://127.0.0.1:8099'),
    'groq_key' => env('GROQ_API_KEY'),
    'groq_model' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
    'embedding_model' => 'BAAI/bge-small-en-v1.5',
    'reranker' => 'ms-marco-MiniLM-L-12-v2',
    'config_version' => 'hybrid-1',
    'pricing' => ['version' => 'groq-2026-10-12', 'input' => 0.075, 'output' => 0.30, 'source' => 'https://console.groq.com/docs/models'],
    'evidence_threshold' => (float) env('EVIDENCE_THRESHOLD', 0.001),
];
