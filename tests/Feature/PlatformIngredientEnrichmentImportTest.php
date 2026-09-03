<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientTranslationOrigin;
use App\Models\Ingredient;
use App\Models\IngredientIdentifier;
use App\Models\IngredientTranslation;
use App\Services\IngredientEnrichment\ApplyPlatformIngredientEnrichment;
use App\Services\IngredientEnrichment\IngredientEnrichmentPlanner;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientTranslationSourceFingerprint;
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

it('applies every corroborating identifier evidence row', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-CORROBORATED-EVIDENCE',
        'category' => IngredientCategory::Other,
    ]);
    $sourceA = [
        'source_name' => 'Supplier A technical dossier',
        'source_url' => 'https://supplier-a.example/technical/marula-oil.pdf',
        'source_tier' => 'approved_secondary',
        'confidence' => 'supported',
        'source_version' => 'supplier-a-2026',
        'source_updated_at' => null,
        'retrieved_at' => '2026-08-14T12:00:00+00:00',
    ];
    $sourceB = [
        'source_name' => 'Supplier B technical dossier',
        'source_url' => 'https://supplier-b.example/technical/marula-oil.pdf',
        'source_tier' => 'approved_secondary',
        'confidence' => 'supported',
        'source_version' => 'supplier-b-2026',
        'source_updated_at' => null,
        'retrieved_at' => '2026-08-14T12:00:00+00:00',
    ];
    $result = importResult($ingredient);
    $result['proposal']['identifiers'] = [[
        'scheme' => 'cas',
        'value' => '68956-68-3',
        'is_primary' => false,
        ...$sourceA,
    ]];
    $result['field_confidence'][] = [
        'field' => 'proposal.identifiers.0',
        'confidence' => 'supported',
    ];
    $result['evidence'] = [
        ...$result['evidence'],
        ['field' => 'proposal.identifiers.0', ...$sourceA],
        ['field' => 'proposal.identifiers.0', ...$sourceB],
    ];
    $result['value_provenance'] = [[
        'field' => 'proposal.identifiers.0',
        'kind' => 'source_confirmed',
        'reasoning' => 'Both technical dossiers print the same CAS number.',
        'source_urls' => [$sourceA['source_url'], $sourceB['source_url']],
    ]];
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', [
        'path' => $path,
        '--apply' => true,
    ])->assertExitCode(0);

    $identifier = $ingredient->fresh()
        ->identifiers()
        ->where('scheme', 'cas')
        ->where('normalized_value', '68956-68-3')
        ->firstOrFail();

    expect($identifier->evidence()->orderBy('source_url')->pluck('source_url')->all())
        ->toBe([
            'https://supplier-a.example/technical/marula-oil.pdf',
            'https://supplier-b.example/technical/marula-oil.pdf',
        ])
        ->and(data_get($ingredient->fresh()->source_data, 'enrichment.core.value_provenance.0.source_urls'))
        ->toBe([
            'https://supplier-a.example/technical/marula-oil.pdf',
            'https://supplier-b.example/technical/marula-oil.pdf',
        ]);

    unlink($path);
});

it('matches equivalent Unicode identifier formatting when applying evidence to an existing identity', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-EQUIVALENT-IDENTIFIER-FORMAT',
        'category' => IngredientCategory::Other,
    ]);
    $ingredient->identifiers()->create([
        'scheme' => 'cas',
        'value' => '68956-68-3',
        'normalized_value' => '68956-68-3',
        'is_primary' => true,
    ]);
    $source = [
        'source_name' => 'Supplier A technical dossier',
        'source_url' => 'https://supplier-a.example/technical/marula-oil.pdf',
        ...importSource(),
    ];
    $result = importResult($ingredient);
    $result['proposal']['identifiers'] = [[
        'scheme' => 'cas',
        'value' => '68956–68–3',
        'is_primary' => true,
        ...$source,
    ]];
    $result['field_confidence'][] = [
        'field' => 'proposal.identifiers.0',
        'confidence' => 'supported',
    ];
    $result['evidence'][] = [
        'field' => 'proposal.identifiers.0',
        ...$source,
    ];
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', [
        'path' => $path,
        '--apply' => true,
    ])->assertExitCode(0);

    $identifiers = $ingredient->fresh()->identifiers()->withCount('evidence')->get();
    expect($identifiers)->toHaveCount(1)
        ->and($identifiers->first()->normalized_value)->toBe('68956-68-3')
        ->and($identifiers->first()->evidence_count)->toBe(1)
        ->and($identifiers->first()->evidence()->value('source_url'))->toBe($source['source_url']);

    unlink($path);
});

it('accepts and canonicalizes a newly applied Unicode-dash identifier', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-NEW-UNICODE-IDENTIFIER',
        'category' => IngredientCategory::Other,
    ]);
    $source = [
        'source_name' => 'Supplier A technical dossier',
        'source_url' => 'https://supplier-a.example/technical/marula-oil.pdf',
        ...importSource(),
    ];
    $result = importResult($ingredient);
    $result['proposal']['identifiers'] = [[
        'scheme' => 'cas',
        'value' => '68956–68–3',
        'is_primary' => true,
        ...$source,
    ]];
    $result['field_confidence'][] = [
        'field' => 'proposal.identifiers.0',
        'confidence' => 'supported',
    ];
    $result['evidence'][] = [
        'field' => 'proposal.identifiers.0',
        ...$source,
    ];
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', [
        'path' => $path,
        '--apply' => true,
    ])->assertExitCode(0);

    expect($ingredient->fresh()->identifiers()->pluck('normalized_value')->all())
        ->toBe(['68956-68-3']);

    unlink($path);
});

it('attaches accepted identifier evidence to its matching identifier after a merge', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-CORROBORATED-EVIDENCE-MERGE',
        'category' => IngredientCategory::Other,
    ]);
    $ingredient->identifiers()->create([
        'scheme' => 'cas',
        'value' => '111-11-1',
        'normalized_value' => '111-11-1',
        'is_primary' => true,
    ]);
    $sourceA = [
        'source_name' => 'Supplier A technical dossier',
        'source_url' => 'https://supplier-a.example/technical/marula-oil.pdf',
        ...importSource(),
    ];
    $sourceB = [
        'source_name' => 'Supplier B technical dossier',
        'source_url' => 'https://supplier-b.example/technical/marula-oil.pdf',
        ...importSource(),
    ];
    $result = importResult($ingredient);
    $result['proposal']['identifiers'] = [[
        'scheme' => 'cas',
        'value' => '68956-68-3',
        'is_primary' => false,
        ...$sourceA,
    ]];
    $result['field_confidence'][] = [
        'field' => 'proposal.identifiers.0',
        'confidence' => 'supported',
    ];
    $result['evidence'] = [
        ...$result['evidence'],
        ['field' => 'proposal.identifiers.0', ...$sourceA],
        ['field' => 'proposal.identifiers.0', ...$sourceB],
    ];
    $result['value_provenance'] = [[
        'field' => 'proposal.identifiers.0',
        'kind' => 'source_confirmed',
        'reasoning' => 'Both technical dossiers print the same CAS number.',
        'source_urls' => [$sourceA['source_url'], $sourceB['source_url']],
    ]];
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', [
        'path' => $path,
        '--apply' => true,
    ])->assertExitCode(0);

    $identifiers = $ingredient->fresh()->identifiers()->withCount('evidence')->get()->keyBy('value');

    expect($identifiers['111-11-1']->evidence_count)->toBe(0)
        ->and($identifiers['68956-68-3']->evidence_count)->toBe(2);

    unlink($path);
});

it('does not attach identifier evidence without a matching proposed identifier', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-STALE-IDENTIFIER-EVIDENCE',
        'category' => IngredientCategory::Other,
    ]);
    $ingredient->identifiers()->create([
        'scheme' => 'cas',
        'value' => '111-11-1',
        'normalized_value' => '111-11-1',
        'is_primary' => true,
    ]);
    $result = importResult($ingredient);
    $result['evidence'][] = [
        'field' => 'proposal.identifiers.0',
        'source_name' => 'Stale identifier source',
        'source_url' => 'https://stale.example/technical/marula-oil.pdf',
        ...importSource(),
    ];
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $path = writeJsonl($result);

    $this->artisan('ingredients:enrichment:import', [
        'path' => $path,
        '--apply' => true,
    ])->assertExitCode(0);

    expect($ingredient->fresh()->identifiers()->withCount('evidence')->value('evidence_count'))->toBe(0);

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
        ->toBe('ingredient-guidance-research-v7');
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
        ->toBe('ingredient-guidance-research-v7')
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.guidance_prompt_version'))
        ->toBe('ingredient-guidance-v13');

    $this->artisan('ingredients:enrichment:import', [
        'path' => $path,
        '--apply' => true,
    ])
        ->expectsOutputToContain('Totals: applied 0, planned 0, unchanged 1')
        ->assertExitCode(0);

    unlink($path);
});

it('fills missing localized identity names without replacing existing names or guidance in merge mode', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-LEGACY-TRANSLATION',
        'category' => IngredientCategory::Other,
        'display_name' => 'Existing ingredient',
        'info_markdown' => "## Overview\nOriginal guidance.\n\n## Formulation use\nUse this material in a simple formula.",
        'source_data' => ['enrichment' => ['guidance' => [
            'localization_prompt_version' => 'stored-localization-v1',
        ]]],
    ]);
    $translation = IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'fr',
        'display_name' => 'Nom français existant',
        'saponification_name' => 'Nom de saponification existant',
        'info_markdown' => "## Vue d’ensemble\nTraduction générée avant la nouvelle version.\n\n## Utilisation en formulation\nUtiliser ce matériau dans une formule simple.",
        'source_fingerprint' => 'legacy-generated-fingerprint',
        'origin' => IngredientTranslationOrigin::AiGenerated,
        'prompt_version' => 'legacy-localization-v1',
    ]);
    IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'de',
        'display_name' => null,
        'saponification_name' => null,
        'info_markdown' => "## Übersicht\nBestehende Anleitung.\n\n## Verwendung in Formulierungen\nBestehende Verwendung.",
        'source_fingerprint' => 'existing-german-fingerprint',
        'origin' => IngredientTranslationOrigin::AiGenerated,
        'prompt_version' => 'legacy-localization-v1',
    ]);
    $existingLocalizedGuidance = $translation->info_markdown;
    $result = importResult($ingredient);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['proposal']['display_name'] = $ingredient->display_name;
    $result['proposal']['inci_name'] = $ingredient->inci_name;
    $result['proposal']['info_markdown'] = "## Overview\nUpdated guidance.\n\n## Formulation use\nUse this material in a measured formula.";
    $result['proposal']['translations'][2] = [
        'locale' => 'fr',
        'display_name' => 'Nouveau nom français',
        'saponification_name' => null,
    ];
    $plan = app(IngredientEnrichmentPlanner::class)->plan($ingredient, $result, ['info_markdown']);

    $applied = app(ApplyPlatformIngredientEnrichment::class)->apply($plan, $result, ['info_markdown']);

    $ingredient->refresh();
    expect($applied['status'])->toBe('applied')
        ->and($ingredient->info_markdown)->toBe($result['proposal']['info_markdown'])
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.localization_prompt_version'))->toBe('stored-localization-v1')
        ->and($ingredient->translations()->count())->toBe(6)
        ->and($ingredient->translations()->where('locale', 'fr')->firstOrFail()->display_name)->toBe('Nom français existant')
        ->and($ingredient->translations()->where('locale', 'fr')->firstOrFail()->saponification_name)->toBe('Nom de saponification existant')
        ->and($ingredient->translations()->where('locale', 'fr')->firstOrFail()->info_markdown)->toBe($existingLocalizedGuidance)
        ->and($ingredient->translations()->where('locale', 'fr')->firstOrFail()->origin)->toBe(IngredientTranslationOrigin::AiGenerated)
        ->and($ingredient->translations()->where('locale', 'fr')->firstOrFail()->source_fingerprint)->toBe('legacy-generated-fingerprint')
        ->and($ingredient->translations()->where('locale', 'fr')->firstOrFail()->prompt_version)->toBe('legacy-localization-v1')
        ->and($ingredient->translations()->where('locale', 'de')->firstOrFail()->display_name)->toBe('Translated de')
        ->and($ingredient->translations()->where('locale', 'de')->firstOrFail()->info_markdown)->toContain('Bestehende Anleitung')
        ->and($ingredient->translations()->where('locale', 'de')->firstOrFail()->source_fingerprint)->toBe('existing-german-fingerprint')
        ->and($ingredient->translations()->where('locale', 'it')->firstOrFail()->origin)->toBe(IngredientTranslationOrigin::AiGenerated)
        ->and($ingredient->translations()->where('locale', 'it')->firstOrFail()->prompt_version)->toBe('ingredient-identity-name-localization-v1')
        ->and($ingredient->translations()->where('locale', 'it')->firstOrFail()->source_fingerprint)
        ->toBe(app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient))
        ->and($ingredient->translations()->where('locale', 'it')->firstOrFail()->info_markdown)->toBeNull();
});

it('replaces AI identity names explicitly without erasing localized guidance', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-REPLACE-TRANSLATED-NAME',
        'category' => IngredientCategory::Other,
        'display_name' => 'Existing ingredient',
    ]);
    $translation = IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'fr',
        'display_name' => 'Nom existant',
        'saponification_name' => 'Nom savon existant',
        'info_markdown' => "## Vue d’ensemble\nConseils existants.\n\n## Utilisation en formulation\nUtilisation existante.",
        'source_fingerprint' => 'existing-guidance-fingerprint',
        'origin' => IngredientTranslationOrigin::AiGenerated,
        'prompt_version' => 'legacy-localization-v1',
    ]);
    $result = importResult($ingredient);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['proposal']['display_name'] = $ingredient->display_name;
    $result['proposal']['inci_name'] = $ingredient->inci_name;
    $result['proposal']['info_markdown'] = $ingredient->info_markdown;
    $result['proposal']['translations'] = [[
        'locale' => 'fr',
        'display_name' => 'Nouveau nom',
        'saponification_name' => 'Nouveau nom savon',
    ]];
    $plan = app(IngredientEnrichmentPlanner::class)->plan($ingredient, $result, ['translations']);

    $applied = app(ApplyPlatformIngredientEnrichment::class)->apply($plan, $result, ['translations']);

    $translation->refresh();
    expect($applied['status'])->toBe('applied')
        ->and($translation->display_name)->toBe('Nouveau nom')
        ->and($translation->saponification_name)->toBe('Nouveau nom savon')
        ->and($translation->info_markdown)->toContain('Conseils existants')
        ->and($translation->source_fingerprint)->toBe('existing-guidance-fingerprint')
        ->and($translation->prompt_version)->toBe('legacy-localization-v1');
});

it('does not apply preserved identity-name differences as changes', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create(['category' => IngredientCategory::Other]);
    IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'fr',
        'display_name' => 'Nom conservé',
        'saponification_name' => null,
        'origin' => IngredientTranslationOrigin::AiGenerated,
    ]);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = ['source_fingerprint' => $sourceFingerprint, 'proposal' => ['translations' => [[
        'locale' => 'fr',
        'display_name' => 'Nom proposé',
        'saponification_name' => null,
    ]]]];
    $plan = [
        'ingredient_id' => $ingredient->id,
        'changed' => true,
        'effective' => ['translations' => [[
            'locale' => 'fr',
            'display_name' => 'Nom conservé',
            'saponification_name' => null,
        ]]],
        'decisions' => [[
            'field' => 'proposal.translations.fr.display_name',
            'decision' => 'preserved',
            'current' => 'Nom conservé',
            'proposed' => 'Nom proposé',
        ]],
    ];

    $applied = app(ApplyPlatformIngredientEnrichment::class)->apply($plan, $result);

    expect($applied['status'])->toBe('unchanged')
        ->and($ingredient->translations()->where('locale', 'fr')->firstOrFail()->display_name)->toBe('Nom conservé');
});

it('preserves reviewer-owned translations when a legacy full result requests replacement', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-REVIEWER-TRANSLATION',
        'category' => IngredientCategory::Other,
        'display_name' => 'Existing ingredient',
        'info_markdown' => "## Overview\nOriginal guidance.\n\n## Formulation use\nUse this material in a simple formula.",
    ]);
    $translation = IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'fr',
        'display_name' => 'Nom français relu',
        'saponification_name' => 'Nom de saponification relu',
        'info_markdown' => "## Vue d’ensemble\nTexte relu par un réviseur.\n\n## Utilisation en formulation\nUtiliser ce matériau avec discernement.",
        'source_fingerprint' => 'reviewer-owned-fingerprint',
        'origin' => IngredientTranslationOrigin::ReviewerEdited,
        'prompt_version' => null,
    ]);
    $beforeTranslation = $translation->fresh()->only([
        'display_name',
        'saponification_name',
        'info_markdown',
        'source_fingerprint',
        'origin',
        'prompt_version',
    ]);
    $result = importResult($ingredient);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['proposal']['display_name'] = $ingredient->display_name;
    $result['proposal']['inci_name'] = $ingredient->inci_name;
    $result['proposal']['info_markdown'] = "## Overview\nUpdated guidance.\n\n## Formulation use\nUse this material in a measured formula.";
    $result['proposal']['translations'][2] = [
        'locale' => 'fr',
        'display_name' => 'Generated translated name',
        'saponification_name' => 'Generated translated soap name',
    ];
    $plan = app(IngredientEnrichmentPlanner::class)->plan($ingredient, $result, ['info_markdown', 'translations']);

    $applied = app(ApplyPlatformIngredientEnrichment::class)->apply($plan, $result, ['info_markdown', 'translations']);

    $ingredient->refresh();
    expect($applied['status'])->toBe('applied')
        ->and($ingredient->info_markdown)->toBe($result['proposal']['info_markdown'])
        ->and($ingredient->translations()->where('locale', 'fr')->firstOrFail()->only([
            'display_name',
            'saponification_name',
            'info_markdown',
            'source_fingerprint',
            'origin',
            'prompt_version',
        ]))->toBe($beforeTranslation);
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
