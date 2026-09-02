<?php

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientIdentifier;
use App\Models\IngredientTranslation;
use App\Services\IngredientEnrichment\ApplyPlatformIngredientEnrichment;
use App\Services\IngredientEnrichment\IngredientEnrichmentPlanner;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps import dry-run read-only and reports preserved versus new fields', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-DRY-RUN',
        'category' => IngredientCategory::Other,
        'display_name' => 'Existing name',
    ]);
    $result = importResult($ingredient);
    $result['proposal']['display_name'] = 'Researched name';
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', [
        'path' => $path,
    ])
        ->expectsOutputToContain('Totals: applied 0, planned 1')
        ->assertExitCode(0);

    expect($ingredient->refresh()->display_name)->toBe('Existing name');
    unlink($path);
});

it('allows explicit scalar replacement and rejects unknown replacement fields before reading', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-REPLACE',
        'category' => IngredientCategory::Other,
        'display_name' => 'Existing name',
    ]);
    $result = importResult($ingredient);
    $result['proposal']['display_name'] = 'Researched name';
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', [
        'path' => $path,
        '--replace' => ['display_name'],
    ])->assertExitCode(0);

    $this->artisan('ingredients:enrichment:import', [
        'path' => '/path/that/should/not/be/read.jsonl',
        '--replace' => ['unknown_field'],
    ])
        ->expectsOutputToContain('Unknown enrichment replacement field')
        ->assertExitCode(1);

    unlink($path);
});

it('reports malformed and duplicate rows while still planning unrelated valid rows', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $first = Ingredient::factory()->create(['catalog_key' => 'ADM-FIRST', 'category' => IngredientCategory::Other]);
    $second = Ingredient::factory()->create(['catalog_key' => 'ADM-SECOND', 'category' => IngredientCategory::Other]);
    $firstResult = importResult($first);
    $firstResult['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($first);
    $secondResult = importResult($second);
    $secondResult['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($second);
    $path = tempnam(sys_get_temp_dir(), 'ingredient-enrichment-import-');
    file_put_contents($path, implode(PHP_EOL, [
        json_encode($firstResult, JSON_THROW_ON_ERROR),
        json_encode($firstResult, JSON_THROW_ON_ERROR),
        'not-json',
        json_encode($secondResult, JSON_THROW_ON_ERROR),
    ]).PHP_EOL);

    $this->artisan('ingredients:enrichment:import', ['path' => $path])
        ->expectsOutputToContain('Totals: applied 0, planned 2, unchanged 0, skipped 0, warned 0, failed 2.')
        ->assertExitCode(1);

    expect($first->refresh()->display_name)->toBe($first->getRawOriginal('display_name'))
        ->and($second->refresh()->display_name)->toBe($second->getRawOriginal('display_name'));
    unlink($path);
});

it('preserves secondary CAS and EC identifiers during default apply', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-SECONDARY-IDENTIFIERS',
        'category' => IngredientCategory::Other,
        'info_markdown' => null,
    ]);
    foreach ([
        ['scheme' => 'cas', 'value' => '111-11-1', 'normalized_value' => '111-11-1', 'is_primary' => true],
        ['scheme' => 'cas', 'value' => '222-22-2', 'normalized_value' => '222-22-2', 'is_primary' => false],
        ['scheme' => 'ec', 'value' => '111-111-1', 'normalized_value' => '111-111-1', 'is_primary' => true],
        ['scheme' => 'ec', 'value' => '222-222-2', 'normalized_value' => '222-222-2', 'is_primary' => false],
    ] as $identifier) {
        IngredientIdentifier::factory()->create([
            'ingredient_id' => $ingredient->id,
            ...$identifier,
        ]);
    }

    $result = importResult($ingredient);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', ['path' => $path, '--apply' => true])
        ->assertExitCode(0);

    expect($ingredient->fresh()->identifiers()->orderBy('value')->pluck('value')->all())
        ->toBe(['111-11-1', '111-111-1', '222-22-2', '222-222-2']);

    unlink($path);
});

it('keeps proposed secondary CAS and EC identifiers during explicit replacement', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-REPLACE-IDENTIFIERS',
        'category' => IngredientCategory::Other,
    ]);
    $ingredient->identifiers()->createMany([
        ['scheme' => 'cas', 'value' => '111-11-1', 'normalized_value' => '111-11-1', 'is_primary' => true],
        ['scheme' => 'cas', 'value' => '222-22-2', 'normalized_value' => '222-22-2', 'is_primary' => false],
        ['scheme' => 'ec', 'value' => '111-111-1', 'normalized_value' => '111-111-1', 'is_primary' => true],
    ]);

    $result = importResult($ingredient);
    $result['proposal']['identifiers'] = collect([
        ['scheme' => 'cas', 'value' => '333-33-3', 'is_primary' => true],
        ['scheme' => 'cas', 'value' => '444-44-4', 'is_primary' => false],
        ['scheme' => 'ec', 'value' => '333-333-3', 'is_primary' => true],
        ['scheme' => 'ec', 'value' => '444-444-4', 'is_primary' => false],
    ])->map(fn (array $row): array => [
        ...$row,
        'source_name' => 'Reference source',
        'source_url' => 'https://cosingchecker.com/ingredients/test/',
        ...importSource(),
    ])->all();
    foreach (array_keys($result['proposal']['identifiers']) as $index) {
        $result['evidence'][] = [
            'field' => "proposal.identifiers.{$index}",
            'source_name' => 'Reference source',
            'source_url' => 'https://cosingchecker.com/ingredients/test/',
            ...importSource(),
        ];
        $result['field_confidence'][] = [
            'field' => "proposal.identifiers.{$index}",
            'confidence' => 'supported',
        ];
    }
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', [
        'path' => $path,
        '--apply' => true,
        '--replace' => ['identifiers'],
    ])->assertExitCode(0);

    expect($ingredient->fresh()->identifiers()->orderBy('value')->pluck('value')->all())
        ->toBe(['333-33-3', '333-333-3', '444-44-4', '444-444-4'])
        ->and($ingredient->fresh()->identifiers()->withCount('evidence')->get()->pluck('evidence_count')->all())
        ->each->toBe(1);

    unlink($path);
});

it('merges source backed aliases without removing existing reviewed aliases', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-MERGE-ALIASES',
        'category' => IngredientCategory::Other,
        'info_markdown' => null,
    ]);
    $ingredient->aliases()->create([
        'locale' => 'und',
        'name' => 'Existing reviewed name',
        'normalized_name' => 'existing reviewed name',
        'kind' => 'common',
    ]);
    $result = importResult($ingredient);
    $result['proposal']['aliases'] = [[
        'locale' => 'und',
        'name' => 'Source synonym',
        'kind' => 'common',
        'source_name' => 'FDA Global Substance Registration System',
        'source_url' => 'https://api.fda.gov/other/substance.json',
        'source_tier' => 'official',
        'confidence' => 'verified',
        'source_version' => 'openfda-gsrs-v1',
        'source_updated_at' => null,
        'retrieved_at' => '2026-08-13T12:00:00+00:00',
    ]];
    $result['field_confidence'][] = ['field' => 'proposal.aliases.0', 'confidence' => 'verified'];
    $result['evidence'][] = [
        'field' => 'proposal.aliases.0',
        'source_name' => 'FDA Global Substance Registration System',
        'source_url' => 'https://api.fda.gov/other/substance.json',
        'source_tier' => 'official',
        'confidence' => 'verified',
        'source_version' => 'openfda-gsrs-v1',
        'source_updated_at' => null,
        'retrieved_at' => '2026-08-13T12:00:00+00:00',
    ];
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', ['path' => $path, '--apply' => true])
        ->assertExitCode(0);

    expect($ingredient->fresh()->aliases()->orderBy('name')->pluck('name')->all())->toBe([
        'Existing reviewed name',
        'Source synonym',
    ]);

    unlink($path);
});

it('does not write an unchanged normalized result and previews all warnings', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-UNCHANGED',
        'category' => IngredientCategory::Other,
        'display_name' => 'Researched ingredient',
        'inci_name' => 'RESEARCHED INGREDIENT',
        'saponification_name' => null,
        'info_markdown' => "## Overview\nA useful researched ingredient.\n\n## Formulation use\nUsed in simple formulas.",
        'requires_admin_review' => false,
        'source_data' => ['existing' => 'preserved'],
    ]);
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        IngredientTranslation::factory()->create([
            'ingredient_id' => $ingredient->id,
            'locale' => $locale,
            'display_name' => "Translated {$locale}",
            'saponification_name' => null,
            'info_markdown' => "## Overview\nA translated ingredient.\n\n## Formulation use\nUsed in translated formulas.",
        ]);
    }

    $result = importResult($ingredient);
    $result['source_fingerprint'] = strtoupper(app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient));
    $result['guidance_evidence'] = [];
    $result['warnings'] = ['Research confidence needs Admin confirmation.'];
    $result['unresolved_questions'] = ['Confirm the supplier grade.'];
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', ['path' => $path, '--apply' => true])
        ->expectsOutputToContain('Research confidence needs Admin confirmation.')
        ->expectsOutputToContain('Unresolved: Confirm the supplier grade.')
        ->expectsOutputToContain('Totals: applied 0, planned 0, unchanged 1, skipped 0, warned 1, failed 0.')
        ->assertExitCode(0);

    expect($ingredient->fresh()->requires_admin_review)->toBeFalse()
        ->and($ingredient->source_data)->toBe(['existing' => 'preserved']);

    unlink($path);
});

it('merges collections by stable keys and replaces only explicit collections', function (): void {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-PLAN',
        'category' => IngredientCategory::Other,
    ]);
    $result = importResult($ingredient);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['proposal']['translations'][0]['display_name'] = 'New German name';

    $planner = app(IngredientEnrichmentPlanner::class);
    $mergePlan = $planner->plan($ingredient, $result);
    $replacePlan = $planner->plan($ingredient, $result, ['translations']);

    expect($mergePlan['changed'])->toBeTrue()
        ->and(collect($mergePlan['decisions'])->contains(
            fn (array $decision): bool => $decision['field'] === 'proposal.translations',
        ))->toBeTrue()
        ->and(collect($replacePlan['decisions'])->contains(
            fn (array $decision): bool => $decision['field'] === 'proposal.translations'
                && $decision['decision'] === 'replace',
        ))->toBeTrue();
});

it('plans changed guidance evidence during full enrichment', function (): void {
    $currentEvidence = [[
        'source_name' => 'Existing source',
        'source_url' => 'https://example.test/existing',
        'summary' => 'Existing evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
    ]];
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-EVIDENCE-PLAN',
        'category' => IngredientCategory::Other,
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $currentEvidence]]],
    ]);
    $result = importResult($ingredient);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);

    $plan = app(IngredientEnrichmentPlanner::class)->plan($ingredient, $result);

    expect($plan['changed'])->toBeTrue()
        ->and(collect($plan['decisions'])->firstWhere('field', 'guidance.evidence'))
        ->toMatchArray([
            'decision' => 'replace',
            'current' => $currentEvidence,
            'proposed' => $result['guidance_evidence'],
        ]);
});

it('plans and applies removal of stale guidance evidence after empty fresh research', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $oldEvidence = [[
        'source_name' => 'Previous source',
        'source_url' => 'https://example.test/previous',
        'summary' => 'Evidence from the previous generation.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
    ]];
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-EVIDENCE-CLEAR',
        'category' => IngredientCategory::Other,
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $oldEvidence]]],
    ]);
    $result = importResult($ingredient);
    $result['guidance_evidence'] = [];
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $plan = app(IngredientEnrichmentPlanner::class)->plan($ingredient, $result);

    $applied = app(ApplyPlatformIngredientEnrichment::class)->apply($plan, $result);

    expect(collect($plan['decisions'])->firstWhere('field', 'guidance.evidence'))
        ->toMatchArray([
            'decision' => 'replace',
            'current' => $oldEvidence,
            'proposed' => [],
        ])
        ->and($applied['status'])->toBe('applied')
        ->and(data_get($applied['ingredient']->source_data, 'enrichment.guidance.evidence'))->toBe([])
        ->and(data_get($applied['ingredient']->source_data, 'enrichment.guidance.research_prompt_version'))
        ->toBe('ingredient-guidance-research-v6');
});

it('applies successive evidence-only updates with the same source fingerprint', function (): void {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-EVIDENCE-REPLAY',
        'category' => IngredientCategory::Other,
        'display_name' => 'Existing ingredient',
        'inci_name' => 'EXISTING INGREDIENT',
        'info_markdown' => "## Overview\nExisting guidance.\n\n## Formulation use\nUse this material in a suitable formulation.",
    ]);
    $result = importResult($ingredient);
    $result['proposal'] = [
        'display_name' => $ingredient->display_name,
        'inci_name' => $ingredient->inci_name,
        'category' => $ingredient->category->value,
        'subcategory' => null,
        'saponification_name' => null,
        'soap_inci_naoh_name' => null,
        'soap_inci_koh_name' => null,
        'info_markdown' => $ingredient->info_markdown,
        'soapmaking_relevant' => false,
        'aliases' => [],
        'identifiers' => [],
        'cosing_functions' => [],
        'translations' => [],
        'market_labels' => [],
    ];
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $firstEvidence = [[
        'source_name' => 'First source',
        'source_url' => 'https://example.test/first',
        'summary' => 'First evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
    ]];
    $secondEvidence = [[
        'source_name' => 'Second source',
        'source_url' => 'https://example.test/second',
        'summary' => 'Second evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-28T00:00:00+00:00',
    ]];
    $result['guidance_evidence'] = $firstEvidence;
    $planner = app(IngredientEnrichmentPlanner::class);
    $applier = app(ApplyPlatformIngredientEnrichment::class);
    $firstPlan = $planner->plan($ingredient, $result);

    expect($firstPlan['changed'])->toBeTrue()
        ->and(collect($firstPlan['decisions'])
            ->reject(fn (array $decision): bool => $decision['decision'] === 'unchanged')
            ->pluck('field')
            ->all())->toBe(['guidance.evidence'])
        ->and($applier->apply($firstPlan, $result)['status'])->toBe('applied');

    $result['guidance_evidence'] = $secondEvidence;
    $currentIngredient = $ingredient->fresh();
    $secondPlan = $planner->plan($currentIngredient, $result);
    $secondApply = $applier->apply($secondPlan, $result);

    expect($secondPlan['changed'])->toBeTrue()
        ->and($secondApply['status'])->toBe('applied')
        ->and(data_get($secondApply['ingredient']->source_data, 'enrichment.guidance.evidence'))
        ->toBe($secondEvidence);
});

it('applies a valid result atomically, records enrichment metadata, and is idempotent', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-APPLY',
        'category' => IngredientCategory::Other,
        'display_name' => 'Existing name',
        'info_markdown' => null,
    ]);
    $result = importResult($ingredient);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', [
        'path' => $path,
        '--apply' => true,
    ])
        ->expectsOutputToContain('Totals: applied 1')
        ->assertExitCode(0);

    $ingredient->refresh();
    expect($ingredient->display_name)->toBe('Existing name')
        ->and($ingredient->info_markdown)->toContain('## Overview')
        ->and($ingredient->requires_admin_review)->toBeTrue()
        ->and(data_get($ingredient->source_data, 'enrichment.core.source_fingerprint'))
        ->toBe($result['source_fingerprint'])
        ->and(data_get($ingredient->source_data, 'enrichment.core.result_fingerprint'))
        ->toMatch('/^[a-f0-9]{64}$/')
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.evidence.0.source_name'))
        ->toBe('COSMILE Europe')
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.research_prompt_version'))
        ->toBe('ingredient-guidance-research-v6')
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.guidance_prompt_version'))
        ->toBe('ingredient-guidance-v11');

    $this->artisan('ingredients:enrichment:import', [
        'path' => $path,
        '--apply' => true,
    ])
        ->expectsOutputToContain('Totals: applied 0, planned 0, unchanged 1')
        ->assertExitCode(0);

    unlink($path);
});

it('rejects a stale result without changing the ingredient', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-STALE',
        'category' => IngredientCategory::Other,
    ]);
    $result = importResult($ingredient);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $ingredient->update(['display_name' => 'Edited after export']);
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', ['path' => $path, '--apply' => true])
        ->expectsOutputToContain('stale')
        ->expectsOutputToContain('skipped 1')
        ->assertExitCode(1);

    expect($ingredient->refresh()->display_name)->toBe('Edited after export');
    unlink($path);
});

/**
 * @return array<string, mixed>
 */
function importResult(Ingredient $ingredient): array
{
    $translations = collect(['de', 'es', 'fr', 'it', 'nl', 'pt_BR'])
        ->map(function (string $locale): array {
            $headings = config("ingredient-enrichment.guidance.localized_headings.{$locale}");

            return [
                'locale' => $locale,
                'display_name' => "Translated {$locale}",
                'saponification_name' => null,
                'info_markdown' => "## {$headings['overview']}\nA translated ingredient.\n\n## {$headings['formulation_use']}\nUsed in translated formulas.",
            ];
        })
        ->all();

    return [
        'format' => 'soapkraft-platform-ingredient-enrichment-result',
        'schema_version' => 2,
        'catalog_key' => $ingredient->catalog_key,
        'source_fingerprint' => str_repeat('0', 64),
        'proposal' => [
            'display_name' => 'Researched ingredient',
            'inci_name' => 'RESEARCHED INGREDIENT',
            'category' => 'other',
            'subcategory' => null,
            'saponification_name' => null,
            'soap_inci_naoh_name' => null,
            'soap_inci_koh_name' => null,
            'info_markdown' => "## Overview\nA useful researched ingredient.\n\n## Formulation use\nUsed in simple formulas.",
            'soapmaking_relevant' => false,
            'aliases' => [],
            'identifiers' => [],
            'cosing_functions' => [],
            'translations' => $translations,
            'market_labels' => [],
        ],
        'field_confidence' => [
            ['field' => 'proposal.inci_name', 'confidence' => 'verified'],
        ],
        'evidence' => [[
            'field' => 'proposal.inci_name',
            'source_name' => 'EUR-Lex Common Ingredient Names Glossary',
            'source_url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175',
            'source_tier' => 'official',
            'confidence' => 'verified',
            'source_version' => '32025D1175',
            'source_updated_at' => null,
            'retrieved_at' => '2026-08-13T12:00:00+00:00',
        ]],
        'guidance_evidence' => [[
            'source_name' => 'COSMILE Europe',
            'source_url' => 'https://cosmileeurope.eu/inci/detail/1152/argania-spinosa-kernel-oil/',
            'summary' => 'A supported practical formulation fact.',
            'source_tier' => 'editorial',
            'retrieved_at' => '2026-08-13T12:00:00+00:00',
        ]],
        'regulatory_findings' => [],
        'confidence' => 'high',
        'warnings' => [],
        'unresolved_questions' => [],
    ];
}

/** @return array<string, mixed> */
function importSource(): array
{
    return [
        'source_tier' => 'structured_mirror',
        'confidence' => 'supported',
        'source_version' => 'inventory-test',
        'source_updated_at' => null,
        'retrieved_at' => '2026-08-13T12:00:00+00:00',
    ];
}

/**
 * @param  array<string, mixed>  $record
 */
function writeJsonl(array $record): string
{
    $path = tempnam(sys_get_temp_dir(), 'ingredient-enrichment-import-');
    file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL);

    return $path;
}
