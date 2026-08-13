<?php

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Services\IngredientEnrichment\ExportPlatformIngredientEnrichment;
use App\Services\IngredientEnrichment\IngredientEnrichmentInputBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds the same deterministic research record used by the jsonl exporter', function (): void {
    $this->seed(SupportedLocaleSeeder::class);

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'apricot_oil',
        'display_name' => 'Apricot Kernel Oil',
        'category' => IngredientCategory::Lipids,
        'info_markdown' => null,
    ]);

    $record = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);

    expect(array_keys($record))->toBe([
        'format',
        'schema_version',
        'catalog_key',
        'source_fingerprint',
        'current',
        'vocabulary',
        'requested_output',
        'research_rules',
    ])
        ->and($record['catalog_key'])->toBe('apricot_oil')
        ->and($record['requested_output']['fields'])->toContain(
            'translations',
            'identifiers',
            'cosing_functions',
            'market_labels',
        );

    $path = sys_get_temp_dir().'/ingredient-input-builder-'.uniqid().'.jsonl';

    app(ExportPlatformIngredientEnrichment::class)->handle($path, [$ingredient->catalog_key]);

    expect(json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
        ->toBe($record);

    unlink($path);
});
