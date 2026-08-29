<?php

use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prefers the persisted guidance evidence envelope', function (): void {
    $ingredient = Ingredient::factory()->create([
        'source_data' => [
            'enrichment' => [
                'guidance' => [
                    'evidence' => [[
                        'source_name' => 'COSMILE Europe',
                        'source_url' => 'https://cosmileeurope.eu/example',
                        'summary' => 'Persisted evidence.',
                        'source_tier' => 'editorial',
                        'retrieved_at' => '2026-08-28T00:00:00+00:00',
                    ]],
                ],
            ],
        ],
    ]);

    $context = app(IngredientGuidanceContextBuilder::class)->build($ingredient);

    expect($context['guidance_evidence'][0]['summary'])->toBe('Persisted evidence.')
        ->and($context['warnings'])->toBe([])
        ->and($context['source_fingerprint'])->toBe(app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient));
});

it('falls back to the newest applied batch guidance evidence', function (): void {
    $ingredient = Ingredient::factory()->create();
    IngredientEnrichmentBatchItem::factory()->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Applied,
        'applied_at' => now(),
        'research_stages' => [
            'ai_guidance_research' => [
                'data' => [
                    'candidate_evidence' => [[
                        'field' => 'proposal.info_markdown',
                        'source_name' => 'COSMILE Europe',
                        'source_url' => 'https://cosmileeurope.eu/example',
                        'summary' => 'Legacy batch evidence.',
                    ]],
                ],
            ],
        ],
    ]);

    $context = app(IngredientGuidanceContextBuilder::class)->build($ingredient);

    expect($context['guidance_evidence'][0]['summary'])->toBe('Legacy batch evidence.')
        ->and($context['guidance_evidence'][0]['source_tier'])->toBe('editorial');
});

it('returns a concrete warning when no reusable evidence exists', function (): void {
    $context = app(IngredientGuidanceContextBuilder::class)->build(Ingredient::factory()->create());

    expect($context['guidance_evidence'])->toBe([])
        ->and($context['warnings'][0])->toContain('No persisted guidance evidence');
});
