<?php

use App\Enums\IngredientCategory;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
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

it('snapshots existing soap chemistry only when the ingredient is trusted for saponification', function (): void {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'black_cumin_oil',
        'display_name' => 'Black Cumin Oil',
        'category' => IngredientCategory::Lipids,
        'subcategory' => 'vegetable_oils',
        'is_soap_saponification_trusted' => true,
    ]);
    IngredientSapProfile::factory()->for($ingredient)->create([
        'koh_sap_value' => '0.195000',
        'iodine_value' => '133.000',
        'ins_value' => '62.000',
    ]);
    $oleic = FattyAcid::factory()->create([
        'key' => 'oleic',
        'name' => 'Oleic',
        'saturation_class' => 'monounsaturated',
        'display_order' => 10,
    ]);
    $linoleic = FattyAcid::factory()->create([
        'key' => 'linoleic',
        'name' => 'Linoleic',
        'saturation_class' => 'polyunsaturated',
        'display_order' => 20,
    ]);
    IngredientFattyAcid::factory()->for($ingredient)->for($linoleic, 'fattyAcid')->create([
        'percentage' => '59.00000',
    ]);
    IngredientFattyAcid::factory()->for($ingredient)->for($oleic, 'fattyAcid')->create([
        'percentage' => '24.00000',
    ]);

    $trusted = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);

    expect(data_get($trusted, 'current.soap_chemistry'))->toBe([
        'koh_sap_value' => '0.195000',
        'naoh_sap_value' => '0.139035',
        'iodine_value' => '133.000',
        'ins_value' => '62.000',
        'fatty_acids' => [
            [
                'key' => 'oleic',
                'name' => 'Oleic',
                'saturation_class' => 'monounsaturated',
                'percentage' => '24.00000',
            ],
            [
                'key' => 'linoleic',
                'name' => 'Linoleic',
                'saturation_class' => 'polyunsaturated',
                'percentage' => '59.00000',
            ],
        ],
    ]);

    $ingredient->update(['is_soap_saponification_trusted' => false]);
    $untrusted = app(IngredientEnrichmentInputBuilder::class)->build($ingredient->fresh());

    expect($untrusted['current'])->not->toHaveKey('soap_chemistry');
});
