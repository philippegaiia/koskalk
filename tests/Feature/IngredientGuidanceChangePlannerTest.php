<?php

use App\Enums\IngredientEnrichmentBatchMode;
use App\Models\Ingredient;
use App\Models\IngredientTranslation;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceChangePlanner;
use App\Services\IngredientTranslationSourceFingerprint;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SupportedLocaleSeeder::class);
});

it('plans replacement when English guidance text changes', function (): void {
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => guidancePlannerText('Current'),
    ]);

    $plan = app(IngredientGuidanceChangePlanner::class)->plan(
        $ingredient,
        guidancePlannerResult($ingredient, guidancePlannerText('Proposed')),
        IngredientEnrichmentBatchMode::GuidanceRefresh,
    );

    expect($plan['changed'])->toBeTrue()
        ->and(collect($plan['decisions'])->firstWhere('field', 'proposal.info_markdown'))
        ->toMatchArray([
            'decision' => 'replace',
            'current' => guidancePlannerText('Current'),
            'proposed' => guidancePlannerText('Proposed'),
        ]);
});

it('creates a revalidate decision for identical stale localization guidance', function (): void {
    $english = guidancePlannerText('Current');
    $french = guidancePlannerLocalizedText('French');
    $ingredient = Ingredient::factory()->create(['info_markdown' => $english]);
    IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'fr',
        'info_markdown' => $french,
        'source_fingerprint' => str_repeat('0', 64),
    ]);

    $plan = app(IngredientGuidanceChangePlanner::class)->plan(
        $ingredient,
        guidancePlannerResult($ingredient, $english, [[
            'locale' => 'fr',
            'info_markdown' => $french,
        ]]),
        IngredientEnrichmentBatchMode::GuidanceLocalization,
    );

    expect($plan['changed'])->toBeTrue()
        ->and(collect($plan['decisions'])
            ->firstWhere('field', 'proposal.translations.fr.info_markdown')['decision'])
        ->toBe('revalidate');
});

it('creates a revalidate decision for identical stale guidance refresh localization', function (): void {
    $english = guidancePlannerText('Current');
    $french = guidancePlannerLocalizedText('French');
    $ingredient = Ingredient::factory()->create(['info_markdown' => $english]);
    IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'fr',
        'info_markdown' => $french,
        'source_fingerprint' => str_repeat('0', 64),
    ]);

    $plan = app(IngredientGuidanceChangePlanner::class)->plan(
        $ingredient,
        guidancePlannerResult($ingredient, $english, [[
            'locale' => 'fr',
            'info_markdown' => $french,
        ]]),
        IngredientEnrichmentBatchMode::GuidanceRefresh,
    );

    expect($plan['changed'])->toBeTrue()
        ->and(collect($plan['decisions'])
            ->firstWhere('field', 'proposal.translations.fr.info_markdown')['decision'])
        ->toBe('revalidate');
});

it('does not plan a content or metadata change for identical current localization guidance', function (): void {
    $english = guidancePlannerText('Current');
    $french = guidancePlannerLocalizedText('French');
    $ingredient = Ingredient::factory()->create(['info_markdown' => $english]);
    $fingerprint = app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient);
    IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'fr',
        'info_markdown' => $french,
        'source_fingerprint' => $fingerprint,
    ]);

    $plan = app(IngredientGuidanceChangePlanner::class)->plan(
        $ingredient,
        guidancePlannerResult($ingredient, $english, [[
            'locale' => 'fr',
            'info_markdown' => $french,
        ]]),
        IngredientEnrichmentBatchMode::GuidanceLocalization,
    );

    expect($plan['changed'])->toBeFalse()
        ->and(collect($plan['decisions'])
            ->firstWhere('field', 'proposal.translations.fr.info_markdown'))
        ->toBeNull();
});

it('plans guidance evidence changes even when guidance text is unchanged', function (): void {
    $english = guidancePlannerText('Current');
    $currentEvidence = [
        [
            'source_name' => 'Existing source',
            'source_url' => 'https://example.test/existing',
            'summary' => 'Existing evidence.',
            'source_tier' => 'editorial',
            'retrieved_at' => '2026-08-01T00:00:00+00:00',
        ],
    ];
    $proposedEvidence = [
        [
            'source_name' => 'Updated source',
            'source_url' => 'https://example.test/updated',
            'summary' => 'Updated evidence.',
            'source_tier' => 'editorial',
            'retrieved_at' => '2026-08-28T00:00:00+00:00',
        ],
    ];
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => $english,
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $currentEvidence]]],
    ]);

    $plan = app(IngredientGuidanceChangePlanner::class)->plan(
        $ingredient,
        guidancePlannerResult($ingredient, $english, [], $proposedEvidence),
        IngredientEnrichmentBatchMode::GuidanceRefresh,
    );

    expect($plan['changed'])->toBeTrue()
        ->and(collect($plan['decisions'])->firstWhere('field', 'guidance.evidence'))
        ->toMatchArray([
            'decision' => 'replace',
            'current' => $currentEvidence,
            'proposed' => $proposedEvidence,
        ]);
});

it('plans removal when fresh guidance research accepts no evidence', function (): void {
    $english = guidancePlannerText('Current');
    $currentEvidence = [[
        'source_name' => 'Previous source',
        'source_url' => 'https://example.test/previous',
        'summary' => 'Evidence from the previous generation.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
    ]];
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => $english,
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $currentEvidence]]],
    ]);

    $plan = app(IngredientGuidanceChangePlanner::class)->plan(
        $ingredient,
        guidancePlannerResult($ingredient, $english),
        IngredientEnrichmentBatchMode::GuidanceRefresh,
    );

    expect($plan['changed'])->toBeTrue()
        ->and(collect($plan['decisions'])->firstWhere('field', 'guidance.evidence'))
        ->toMatchArray([
            'decision' => 'replace',
            'current' => $currentEvidence,
            'proposed' => [],
        ]);
});

it('preserves current evidence when localization-only returns no evidence', function (): void {
    $english = guidancePlannerText('Current');
    $currentEvidence = [[
        'source_name' => 'Previous source',
        'source_url' => 'https://example.test/previous',
        'summary' => 'Evidence from the previous generation.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
    ]];
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => $english,
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $currentEvidence]]],
    ]);

    $plan = app(IngredientGuidanceChangePlanner::class)->plan(
        $ingredient,
        guidancePlannerResult($ingredient, $english),
        IngredientEnrichmentBatchMode::GuidanceLocalization,
    );

    expect(collect($plan['decisions'])->firstWhere('field', 'guidance.evidence'))->toBeNull()
        ->and($plan['effective']['guidance_evidence'])->toBe($currentEvidence);
});

it('always uses current evidence for localization-only results', function (): void {
    $english = guidancePlannerText('Current');
    $currentEvidence = [[
        'source_name' => 'Current source',
        'source_url' => 'https://example.test/current',
        'summary' => 'Current evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
    ]];
    $staleEvidence = [[
        'source_name' => 'Stale source',
        'source_url' => 'https://example.test/current',
        'summary' => 'Current evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-02T00:00:00+00:00',
    ], [
        'source_name' => 'Stale distinct source',
        'source_url' => 'https://example.test/stale-distinct',
        'summary' => 'Stale distinct evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-02T00:00:00+00:00',
    ]];
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => $english,
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $currentEvidence]]],
    ]);

    $plan = app(IngredientGuidanceChangePlanner::class)->plan(
        $ingredient,
        guidancePlannerResult($ingredient, $english, [], $staleEvidence),
        IngredientEnrichmentBatchMode::GuidanceLocalization,
    );

    expect($plan['changed'])->toBeFalse()
        ->and(collect($plan['decisions'])->firstWhere('field', 'guidance.evidence'))->toBeNull()
        ->and($plan['effective']['guidance_evidence'])->toBe($currentEvidence);
});

/** @return array<string, mixed> */
function guidancePlannerResult(
    Ingredient $ingredient,
    string $english,
    array $translations = [],
    array $evidence = [],
): array {
    return [
        'source_fingerprint' => app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient),
        'info_markdown' => $english,
        'translations' => $translations,
        'guidance_evidence' => $evidence,
    ];
}

function guidancePlannerText(string $label): string
{
    return "## Overview\n{$label} English guidance.\n\n## Formulation use\nUse this material in a suitable formulation.";
}

function guidancePlannerLocalizedText(string $label): string
{
    return "## Vue d’ensemble\n{$label} French guidance.\n\n## Utilisation en formulation\nUtiliser ce matériau dans une formule adaptée.";
}
