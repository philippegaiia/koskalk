<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('synchronizes only existing platform taxonomy metadata when explicitly applied', function (): void {
    $platformIngredient = Ingredient::factory()->create([
        'catalog_key' => 'OB1',
        'category' => IngredientCategory::Other,
        'subcategory' => null,
        'taxonomy_source' => 'platform_curated',
        'display_name' => 'AI-enriched olive oil name',
        'info_markdown' => 'AI-enriched guidance that must be preserved.',
        'is_soap_saponification_trusted' => true,
        'requires_aromatic_compliance' => true,
        'owner_type' => null,
    ]);
    $workspaceIngredient = Ingredient::factory()->create([
        'catalog_key' => 'WORKSPACE-TAXONOMY-TEST',
        'category' => IngredientCategory::Other,
        'subcategory' => null,
        'taxonomy_source' => 'workspace_user',
        'owner_type' => 'workspace',
        'owner_id' => 999,
    ]);

    $this->artisan('ingredients:sync-catalog-taxonomy', ['--apply' => true])
        ->expectsOutputToContain('Exact platform taxonomy synchronized: 1 updated, 0 unchanged, 1 reviewed.')
        ->assertSuccessful();

    $platformIngredient->refresh();
    $workspaceIngredient->refresh();

    expect($platformIngredient->category)->toBe(IngredientCategory::Lipids)
        ->and($platformIngredient->subcategory)->toBe(IngredientSubcategory::VegetableOils)
        ->and($platformIngredient->taxonomy_source)->toBe('platform_curated')
        ->and($platformIngredient->is_soap_saponification_trusted)->toBeFalse()
        ->and($platformIngredient->requires_aromatic_compliance)->toBeFalse()
        ->and($platformIngredient->display_name)->toBe('AI-enriched olive oil name')
        ->and($platformIngredient->info_markdown)->toBe('AI-enriched guidance that must be preserved.')
        ->and($workspaceIngredient->category)->toBe(IngredientCategory::Other)
        ->and($workspaceIngredient->subcategory)->toBeNull()
        ->and($workspaceIngredient->taxonomy_source)->toBe('workspace_user');
});

it('previews exact taxonomy changes without writing by default', function (): void {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'OB1',
        'category' => IngredientCategory::Other,
        'subcategory' => null,
        'owner_type' => null,
    ]);

    $this->artisan('ingredients:sync-catalog-taxonomy')
        ->expectsTable(
            ['Catalog key', 'Ingredient', 'Current', 'Canonical', 'Subcategory'],
            [['OB1', $ingredient->display_name, 'other', 'lipids', 'vegetable_oils']],
        )
        ->expectsOutputToContain('Dry run only. Pass --apply to synchronize exact platform taxonomy metadata.')
        ->assertSuccessful();

    expect($ingredient->fresh()->category)->toBe(IngredientCategory::Other)
        ->and($ingredient->fresh()->subcategory)->toBeNull();
});
