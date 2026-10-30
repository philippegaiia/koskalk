<?php

it('configures direct batches and an official web search domain allow list safely', function (): void {
    expect(config('ingredient-enrichment.direct_ai'))->toMatchArray([
        'default_batch_size' => 10,
        'maximum_batch_size' => 25,
    ])
        ->and(file_get_contents(base_path('.env.example')))->toContain('INGREDIENT_ENRICHMENT_AI_ENABLED=false')
        ->and(config('ingredient-enrichment.openai.model'))->toBe('gpt-5.6-terra')
        ->and(config('ingredient-enrichment.openai.allowed_domains'))->toBe([
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
        ]);
});

it('uses a dedicated enrichment queue with a reservation margin for long jobs', function (): void {
    expect(config('ingredient-enrichment.direct_ai.queue'))->toBe('enrichment')
        ->and(config('ingredient-enrichment.direct_ai.job_timeout_seconds'))->toBe(2000)
        ->and(config('queue.connections.database.retry_after'))->toBe(2100)
        ->and(file_get_contents(base_path('.env.example')))
        ->toContain('INGREDIENT_ENRICHMENT_QUEUE=enrichment');
});

it('keeps guidance research policy separate from the official metadata domain allow list', function (): void {
    expect(config('ingredient-enrichment.guidance'))
        ->toMatchArray([
            'maximum_words' => 0,
            'maximum_characters' => 10000,
        ])
        ->and(config('ingredient-enrichment.openai.guidance_generation'))
        ->toMatchArray(['enabled' => false])
        ->and(file_get_contents(base_path('.env.example')))
        ->toContain('INGREDIENT_ENRICHMENT_GUIDANCE_GENERATION_ENABLED=false')
        ->and(config('ingredient-enrichment.openai.gap_research'))->toBeNull()
        ->and(file_get_contents(base_path('.env.example')))->not->toContain('GAP_RESEARCH')
        ->and(config('ingredient-enrichment.openai.guidance_research.maximum_tool_calls'))->toBe(5)
        ->and(config('ingredient-enrichment.openai.guidance_research.allowed_source_kinds'))
        ->toContain('supplier_technical', 'professional_reference', 'specialist_reference')
        ->and(config('ingredient-enrichment.openai.guidance_research.blocked_domains'))
        ->toContain('amazon.com', 'patents.google.com', 'reddit.com', 'youtube.com');
});
