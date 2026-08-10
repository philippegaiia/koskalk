<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\Workspace;
use App\Services\IngredientCatalogAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('passes for a platform ingredient matching exact metadata and capability requirements', function (): void {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'OB1',
        'category' => IngredientCategory::Lipids,
        'subcategory' => IngredientSubcategory::VegetableOils,
        'is_soap_saponification_trusted' => true,
        'requires_aromatic_compliance' => false,
        'owner_type' => null,
    ]);
    $ingredient->sapProfile()->create(['koh_sap_value' => 0.188]);

    $this->artisan('ingredients:audit-catalog-metadata')
        ->expectsOutputToContain('Invalid taxonomy: 0')
        ->expectsOutputToContain('Missing platform subtype: 0')
        ->expectsOutputToContain('Soap trust without KOH SAP: 0')
        ->assertSuccessful();
});

it('fails when a platform ingredient has no exact taxonomy assignment', function (): void {
    Ingredient::factory()->create([
        'catalog_key' => 'UNKNOWN-CATALOG-KEY',
        'category' => IngredientCategory::Other,
        'owner_type' => null,
    ]);

    $this->artisan('ingredients:audit-catalog-metadata')
        ->expectsOutputToContain('Invalid taxonomy: 1')
        ->expectsOutputToContain('UNKNOWN-CATALOG-KEY')
        ->assertFailed();
});

it('fails for stale taxonomy and missing explicit capabilities', function (): void {
    Ingredient::factory()->create([
        'catalog_key' => 'OB1',
        'category' => IngredientCategory::Lipids,
        'subcategory' => null,
        'is_soap_saponification_trusted' => true,
        'owner_type' => null,
    ]);
    Ingredient::factory()->create([
        'catalog_key' => 'EO1',
        'category' => IngredientCategory::AromaticMaterials,
        'subcategory' => IngredientSubcategory::EssentialOils,
        'requires_aromatic_compliance' => false,
        'owner_type' => null,
    ]);

    $this->artisan('ingredients:audit-catalog-metadata')
        ->expectsOutputToContain('Invalid taxonomy: 1')
        ->expectsOutputToContain('Soap trust without KOH SAP: 1')
        ->expectsOutputToContain('Aromatic subtype without compliance capability: 1')
        ->assertFailed();
});

it('reports unresolved consolidation decisions only when their source record exists', function (): void {
    Ingredient::factory()->create([
        'catalog_key' => 'ING029',
        'category' => IngredientCategory::Other,
        'owner_type' => null,
    ]);

    $this->artisan('ingredients:audit-catalog-metadata')
        ->expectsOutputToContain('Unresolved consolidation decisions: 1')
        ->expectsOutputToContain('ING029')
        ->assertFailed();
});

it('ignores workspace-owned ingredients because their taxonomy is workspace data', function (): void {
    Ingredient::factory()->create([
        'catalog_key' => 'WORKSPACE-ONLY',
        'category' => IngredientCategory::Other,
        'owner_type' => 'workspace',
        'owner_id' => 999,
        'workspace_id' => null,
    ]);

    $this->artisan('ingredients:audit-catalog-metadata')->assertSuccessful();
});

it('reports conflicting workspace prices for a proposed platform merge', function (): void {
    $workspace = Workspace::factory()->create();
    $source = Ingredient::factory()->create([
        'catalog_key' => 'ADM-P8YDYPKK',
        'owner_type' => null,
    ]);
    $target = Ingredient::factory()->create([
        'catalog_key' => 'CH1',
        'owner_type' => null,
    ]);

    CurrentMaterialPrice::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
        'price_per_canonical_unit' => '0.001000000000',
        'currency' => 'EUR',
    ]);
    CurrentMaterialPrice::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $target->id,
        'price_per_canonical_unit' => '0.002000000000',
        'currency' => 'EUR',
    ]);

    $result = app(IngredientCatalogAuditService::class)->audit();

    expect($result['conflicting_duplicate_prices'])
        ->toBe(["ADM-P8YDYPKK -> CH1 @ workspace {$workspace->id}"]);
});

it('allows matching workspace prices for a proposed platform merge', function (): void {
    $workspace = Workspace::factory()->create();
    $source = Ingredient::factory()->create(['catalog_key' => 'ADM-P8YDYPKK', 'owner_type' => null]);
    $target = Ingredient::factory()->create(['catalog_key' => 'CH1', 'owner_type' => null]);

    foreach ([$source, $target] as $ingredient) {
        CurrentMaterialPrice::factory()->create([
            'workspace_id' => $workspace->id,
            'ingredient_id' => $ingredient->id,
            'price_per_canonical_unit' => '0.001000000000',
            'currency' => 'EUR',
        ]);
    }

    expect(app(IngredientCatalogAuditService::class)->audit()['conflicting_duplicate_prices'])->toBe([]);
});

it('separates CosIng no-match and ambiguous-INCI review lists', function (): void {
    Ingredient::factory()->create(['catalog_key' => 'UNIQUE', 'inci_name' => 'UNIQUE INCI', 'owner_type' => null]);
    Ingredient::factory()->create(['catalog_key' => 'DUPLICATE-A', 'inci_name' => ' SAME   INCI ', 'owner_type' => null]);
    Ingredient::factory()->create(['catalog_key' => 'DUPLICATE-B', 'inci_name' => 'same inci', 'owner_type' => null]);

    $result = app(IngredientCatalogAuditService::class)->audit();

    expect($result['cosing_no_match'])->toContain('UNIQUE')
        ->and($result['cosing_no_match'])->not->toContain('DUPLICATE-A', 'DUPLICATE-B')
        ->and($result['cosing_ambiguous_match'])->toBe(['DUPLICATE-A', 'DUPLICATE-B']);
});
