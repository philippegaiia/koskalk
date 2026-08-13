<?php

return [
    'input_format' => 'soapkraft-platform-ingredient-enrichment-input',
    'result_format' => 'soapkraft-platform-ingredient-enrichment-result',
    'schema_version' => 1,
    'default_export_path' => 'storage/app/private/ingredient-enrichment/platform-ingredients.jsonl',
    'market_codes' => ['eu', 'us'],
    'guidance' => [
        'minimum_words' => 80,
        'maximum_words' => 220,
        'required_headings' => ['Overview', 'Formulation use'],
        'soapmaking_heading' => 'Soapmaking',
    ],
    'direct_ai' => [
        'enabled' => env('INGREDIENT_ENRICHMENT_AI_ENABLED', false),
        'queue' => env('INGREDIENT_ENRICHMENT_QUEUE', 'default'),
        'default_batch_size' => (int) env('INGREDIENT_ENRICHMENT_DEFAULT_BATCH_SIZE', 10),
        'maximum_batch_size' => (int) env('INGREDIENT_ENRICHMENT_MAXIMUM_BATCH_SIZE', 25),
    ],
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('INGREDIENT_ENRICHMENT_MODEL', 'gpt-5.6-terra'),
        'reasoning_effort' => env('INGREDIENT_ENRICHMENT_REASONING_EFFORT', 'low'),
        'timeout_seconds' => (int) env('INGREDIENT_ENRICHMENT_TIMEOUT', 300),
        'connect_timeout_seconds' => (int) env('INGREDIENT_ENRICHMENT_CONNECT_TIMEOUT', 15),
        'prompt_version' => 'ingredient-enrichment-research-v1',
        'allowed_domains' => [
            'ec.europa.eu',
            'single-market-economy.ec.europa.eu',
            'eur-lex.europa.eu',
            'echa.europa.eu',
            'commonchemistry.cas.org',
            'pubchem.ncbi.nlm.nih.gov',
            'powo.science.kew.org',
            'fda.gov',
            'ecfr.gov',
            'pubmed.ncbi.nlm.nih.gov',
            'cir-safety.org',
        ],
    ],
];
