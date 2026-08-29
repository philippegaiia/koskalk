<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\RecipeItem;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use App\Models\WorkspaceIngredientGuidance;
use App\Services\IngredientCatalogConsolidationService;
use App\Support\IngredientCatalogConsolidationDataset;
use App\Support\IngredientCatalogTaxonomyDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function consolidationServiceFor(array $decisions): IngredientCatalogConsolidationService
{
    $dataset = Mockery::mock(IngredientCatalogConsolidationDataset::class);
    $dataset->shouldReceive('all')->andReturn($decisions);

    return new IngredientCatalogConsolidationService(
        app(IngredientCatalogTaxonomyDataset::class),
        $dataset,
    );
}

it('previews exact catalog-key taxonomy without mutating platform ingredients', function () {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'OB1',
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive oil virgin',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'owner_type' => null,
    ]);

    $this->artisan('ingredients:consolidate-catalog', ['--json' => true])->assertSuccessful();

    expect(app(IngredientCatalogConsolidationService::class)->preview()->firstWhere('catalog_key', 'OB1'))
        ->toMatchArray(['to' => 'lipids', 'subcategory' => 'vegetable_oils', 'status' => 'ready']);

    expect($ingredient->fresh()->category)->toBe(IngredientCategory::Lipids)
        ->and($ingredient->fresh()->subcategory)->toBeNull();
});

it('applies exact taxonomy metadata without deleting records or specialist chemistry', function () {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'OB1',
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive oil virgin',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'owner_type' => null,
        'is_soap_saponification_trusted' => true,
    ]);
    $ingredient->sapProfile()->create(['koh_sap_value' => 0.18]);

    app(IngredientCatalogConsolidationService::class)->applyMetadata();

    $ingredient = $ingredient->fresh(['sapProfile']);

    expect($ingredient->category)->toBe(IngredientCategory::Lipids)
        ->and($ingredient->subcategory)->toBe(IngredientSubcategory::VegetableOils)
        ->and($ingredient->taxonomy_source)->toBe('platform_curated')
        ->and($ingredient->is_soap_saponification_trusted)->toBeFalse()
        ->and($ingredient->sapProfile)->not->toBeNull();
});

it('refuses destructive consolidation while review decisions remain unresolved', function (): void {
    Ingredient::factory()->create([
        'catalog_key' => 'ING029',
        'owner_type' => null,
    ]);

    $this->artisan('ingredients:consolidate-catalog', ['--apply' => true])
        ->expectsOutputToContain('Unresolved consolidation decisions')
        ->assertFailed();

    expect(Ingredient::query()->where('catalog_key', 'ING029')->exists())->toBeTrue();
});

it('reports unknown catalog keys instead of guessing from names or INCI', function () {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'OB-TEST',
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Grape Seed Oil',
        'inci_name' => 'VITIS VINIFERA SEED OIL, TOCOPHEROL',
        'owner_type' => null,
    ]);

    expect(app(IngredientCatalogConsolidationService::class)->preview()->firstWhere('catalog_key', 'OB-TEST'))
        ->toMatchArray(['to' => null, 'subcategory' => null, 'status' => 'missing_metadata']);

    $this->artisan('ingredients:consolidate-catalog', ['--apply' => true])
        ->assertFailed();

    expect($ingredient->fresh()->category)->toBe(IngredientCategory::Lipids);
});

it('contains exact reviewed corrections for known catalog errors', function (string $catalogKey, string $category, string $subcategory): void {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => $catalogKey,
        'category' => IngredientCategory::Other,
        'owner_type' => null,
    ]);

    expect(app(IngredientCatalogConsolidationService::class)->preview()->firstWhere('catalog_key', $catalogKey))
        ->toMatchArray(['to' => $category, 'subcategory' => $subcategory, 'status' => 'ready']);
})->with([
    ['BE4', 'preservation_stability', 'antioxidants'],
    ['BE6', 'botanicals_extracts', 'plant_powders'],
    ['OB19', 'botanicals_extracts', 'plant_powders'],
    ['CH1', 'soapmaking_alkalis', 'sodium_hydroxide'],
    ['CH3', 'soapmaking_alkalis', 'potassium_hydroxide'],
    ['EC2', 'colourants', 'mineral_pigments'],
    ['EC3', 'colourants', 'mineral_pigments'],
]);

it('merges an approved duplicate transactionally and preserves workspace ownership of prices', function (): void {
    $workspace = Workspace::factory()->create();
    $source = Ingredient::factory()->create(['catalog_key' => 'EO26', 'owner_type' => null]);
    $target = Ingredient::factory()->create(['catalog_key' => 'EO25', 'owner_type' => null]);
    $recipeItem = RecipeItem::factory()->create(['ingredient_id' => $source->id]);
    $price = CurrentMaterialPrice::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
        'price_per_canonical_unit' => '0.001000000000',
    ]);

    $result = consolidationServiceFor([[
        'action' => 'merge_into',
        'source_catalog_key' => 'EO26',
        'target_catalog_key' => 'EO25',
        'reason' => 'Test-approved duplicate.',
    ]])->apply();

    expect($result['merged'])->toBe(1)
        ->and($recipeItem->fresh()->ingredient_id)->toBe($target->id)
        ->and($price->fresh()->ingredient_id)->toBe($target->id)
        ->and(Ingredient::query()->whereKey($source->id)->exists())->toBeFalse();
});

it('moves a source workspace material code to the target during a catalogue merge', function (): void {
    $workspace = Workspace::factory()->create();
    $source = Ingredient::factory()->create(['catalog_key' => 'EO26', 'owner_type' => null]);
    $target = Ingredient::factory()->create(['catalog_key' => 'EO25', 'owner_type' => null]);
    $code = WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
        'material_code' => 'EO-LAVENDER',
    ]);

    consolidationServiceFor([[
        'action' => 'merge_into',
        'source_catalog_key' => 'EO26',
        'target_catalog_key' => 'EO25',
        'reason' => 'Test-approved duplicate.',
    ]])->apply();

    expect($code->fresh()->ingredient_id)->toBe($target->id);
});

it('moves a source workspace ingredient guidance override to the target during a catalogue merge', function (): void {
    $workspace = Workspace::factory()->create();
    $source = Ingredient::factory()->create(['catalog_key' => 'EO26', 'owner_type' => null]);
    $target = Ingredient::factory()->create(['catalog_key' => 'EO25', 'owner_type' => null]);
    $guidance = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
        'guidance_markdown' => 'Source guidance',
    ]);

    consolidationServiceFor([[
        'action' => 'merge_into',
        'source_catalog_key' => 'EO26',
        'target_catalog_key' => 'EO25',
        'reason' => 'Test-approved duplicate.',
    ]])->apply();

    expect($guidance->fresh()->ingredient_id)->toBe($target->id);
});

it('keeps a target-only workspace ingredient guidance override during a catalogue merge', function (): void {
    $workspace = Workspace::factory()->create();
    $source = Ingredient::factory()->create(['catalog_key' => 'EO26', 'owner_type' => null]);
    $target = Ingredient::factory()->create(['catalog_key' => 'EO25', 'owner_type' => null]);
    $guidance = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $target->id,
        'guidance_markdown' => 'Target guidance',
    ]);

    consolidationServiceFor([[
        'action' => 'merge_into',
        'source_catalog_key' => 'EO26',
        'target_catalog_key' => 'EO25',
        'reason' => 'Test-approved duplicate.',
    ]])->apply();

    expect($guidance->fresh()->ingredient_id)->toBe($target->id)
        ->and(WorkspaceIngredientGuidance::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $target->id)
            ->count())->toBe(1);
});

it('deduplicates identical workspace ingredient guidance overrides during a catalogue merge', function (): void {
    $workspace = Workspace::factory()->create();
    $source = Ingredient::factory()->create(['catalog_key' => 'EO26', 'owner_type' => null]);
    $target = Ingredient::factory()->create(['catalog_key' => 'EO25', 'owner_type' => null]);
    $sourceGuidance = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
        'guidance_markdown' => 'Shared guidance',
    ]);
    $targetGuidance = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $target->id,
        'guidance_markdown' => 'Shared guidance',
    ]);

    consolidationServiceFor([[
        'action' => 'merge_into',
        'source_catalog_key' => 'EO26',
        'target_catalog_key' => 'EO25',
        'reason' => 'Test-approved duplicate.',
    ]])->apply();

    expect(WorkspaceIngredientGuidance::query()->whereKey($sourceGuidance->id)->exists())->toBeFalse()
        ->and(WorkspaceIngredientGuidance::query()->whereKey($targetGuidance->id)->exists())->toBeTrue();
});

it('rolls back a catalogue merge when workspace ingredient guidance overrides conflict', function (): void {
    $workspace = Workspace::factory()->create();
    $source = Ingredient::factory()->create(['catalog_key' => 'EO26', 'owner_type' => null]);
    $target = Ingredient::factory()->create(['catalog_key' => 'EO25', 'owner_type' => null]);
    $sourceGuidance = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
        'guidance_markdown' => 'Source guidance',
    ]);
    $targetGuidance = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $target->id,
        'guidance_markdown' => 'Target guidance',
    ]);

    expect(fn () => consolidationServiceFor([[
        'action' => 'merge_into',
        'source_catalog_key' => 'EO26',
        'target_catalog_key' => 'EO25',
        'reason' => 'Test-approved duplicate.',
    ]])->apply())->toThrow(RuntimeException::class, 'workspace ingredient guidance conflict');

    expect(Ingredient::query()->whereKey($source->id)->exists())->toBeTrue()
        ->and(Ingredient::query()->whereKey($target->id)->exists())->toBeTrue()
        ->and($sourceGuidance->fresh()->ingredient_id)->toBe($source->id)
        ->and($sourceGuidance->fresh()->guidance_markdown)->toBe('Source guidance')
        ->and($targetGuidance->fresh()->ingredient_id)->toBe($target->id)
        ->and($targetGuidance->fresh()->guidance_markdown)->toBe('Target guidance');
});

it('rolls back an approved merge when workspace material codes conflict', function (): void {
    $workspace = Workspace::factory()->create();
    $source = Ingredient::factory()->create(['catalog_key' => 'EO26', 'owner_type' => null]);
    $target = Ingredient::factory()->create(['catalog_key' => 'EO25', 'owner_type' => null]);

    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
        'material_code' => 'EO-LAVENDER-OLD',
    ]);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $target->id,
        'material_code' => 'EO-LAVENDER-NEW',
    ]);

    expect(fn () => consolidationServiceFor([[
        'action' => 'merge_into',
        'source_catalog_key' => 'EO26',
        'target_catalog_key' => 'EO25',
        'reason' => 'Test-approved duplicate.',
    ]])->apply())->toThrow(RuntimeException::class, 'workspace material code conflict');

    expect(Ingredient::query()->whereKey($source->id)->exists())->toBeTrue()
        ->and(WorkspaceIngredientCode::query()->where('ingredient_id', $source->id)->value('material_code'))->toBe('EO-LAVENDER-OLD')
        ->and(WorkspaceIngredientCode::query()->where('ingredient_id', $target->id)->value('material_code'))->toBe('EO-LAVENDER-NEW');
});

it('rolls back an approved merge when workspace prices conflict', function (): void {
    $workspace = Workspace::factory()->create();
    $source = Ingredient::factory()->create(['catalog_key' => 'EO26', 'owner_type' => null]);
    $target = Ingredient::factory()->create(['catalog_key' => 'EO25', 'owner_type' => null]);
    $recipeItem = RecipeItem::factory()->create(['ingredient_id' => $source->id]);

    CurrentMaterialPrice::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $source->id,
        'price_per_canonical_unit' => '0.001000000000',
    ]);
    CurrentMaterialPrice::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $target->id,
        'price_per_canonical_unit' => '0.002000000000',
    ]);

    expect(fn () => consolidationServiceFor([[
        'action' => 'merge_into',
        'source_catalog_key' => 'EO26',
        'target_catalog_key' => 'EO25',
        'reason' => 'Test-approved duplicate.',
    ]])->apply())->toThrow(RuntimeException::class, 'workspace price conflict');

    expect($recipeItem->fresh()->ingredient_id)->toBe($source->id)
        ->and(Ingredient::query()->whereKey($source->id)->exists())->toBeTrue();
});
