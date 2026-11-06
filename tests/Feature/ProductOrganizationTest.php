<?php

use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Brand;
use App\Models\Ingredient;
use App\Models\ProductCollection;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductOrganizationService;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates workspace brands and collections and derives the collection brand', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $user->update(['active_workspace_id' => $workspace->id]);
    $service = app(ProductOrganizationService::class);
    $brand = $service->createBrand($user, 'Umbrella');
    $collection = $service->createCollection($user, $brand->id, 'Botanical');
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);

    expect($service->assignment($user, ['product_collection_id' => $collection->id], $recipe))
        ->toBe(['brand_id' => $brand->id, 'product_collection_id' => $collection->id]);
    expect($service->options($user)['collections'])->toHaveCount(1);
});

it('rejects organization assignments from another workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $user->update(['active_workspace_id' => $workspace->id]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $brand = Brand::factory()->create();
    app(ProductOrganizationService::class)->assignment($user, ['brand_id' => $brand->id], $recipe);
})->throws(ValidationException::class);

it('preserves omitted organization and clears an old collection when changing brand', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $user->update(['active_workspace_id' => $workspace->id]);
    $brand = Brand::factory()->create(['workspace_id' => $workspace->id]);
    $otherBrand = Brand::factory()->create(['workspace_id' => $workspace->id]);
    $collection = ProductCollection::factory()->create(['workspace_id' => $workspace->id, 'brand_id' => $brand->id]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'brand_id' => $brand->id, 'product_collection_id' => $collection->id]);
    $service = app(ProductOrganizationService::class);

    expect($service->assignment($user, [], $recipe))->toBe(['brand_id' => $brand->id, 'product_collection_id' => $collection->id]);
    expect($service->assignment($user, ['brand_id' => $otherBrand->id], $recipe))->toBe(['brand_id' => $otherBrand->id, 'product_collection_id' => null]);
});

it('round trips product organization through save duplicate and explicit clearing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $user->update(['active_workspace_id' => $workspace->id]);
    $family = ProductFamily::factory()->create(['slug' => 'cosmetic', 'calculation_basis' => 'total_formula']);
    $type = ProductType::factory()->create(['product_family_id' => $family->id]);
    $ingredient = Ingredient::factory()->create(['is_active' => true]);
    $brand = Brand::factory()->create(['workspace_id' => $workspace->id]);
    $collection = ProductCollection::factory()->create(['workspace_id' => $workspace->id, 'brand_id' => $brand->id]);
    $payload = [
        'name' => 'Organized cream', 'product_type_id' => $type->id,
        'oil_unit' => 'g', 'oil_weight' => 100,
        'manufacturing_mode' => 'blend_only', 'exposure_mode' => 'leave_on',
        'regulatory_regime' => 'none', 'editing_mode' => 'percentage',
        'phases' => [['key' => 'phase_a', 'name' => 'Main']],
        'phase_items' => ['phase_a' => [['ingredient_id' => $ingredient->id, 'percentage' => 100, 'weight' => 100]]],
        'brand_id' => $brand->id, 'product_collection_id' => $collection->id,
    ];
    $service = app(RecipeWorkbenchService::class);
    $version = $service->save($user, $family, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    expect($recipe->brand_id)->toBe($brand->id)
        ->and($recipe->product_collection_id)->toBe($collection->id);
    expect($service->currentVersionPayload($recipe)['productCollectionId'])->toBe($collection->id);
    $copy = $service->duplicateRecipe($user, $recipe);
    expect($copy->recipe->brand_id)->toBe($brand->id)
        ->and($copy->recipe->product_collection_id)->toBe($collection->id);
    unset($payload['brand_id'], $payload['product_collection_id']);
    $service->save($user, $family, $payload, $recipe);
    expect($recipe->fresh()->product_collection_id)->toBe($collection->id);
    $service->save($user, $family, $payload + ['brand_id' => null, 'product_collection_id' => null], $recipe);
    expect($recipe->fresh()->brand_id)->toBeNull()
        ->and($recipe->fresh()->product_collection_id)->toBeNull();
});

it('rejects a collection paired with a different brand', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $user->update(['active_workspace_id' => $workspace->id]);
    $brand = Brand::factory()->create(['workspace_id' => $workspace->id]);
    $other = Brand::factory()->create(['workspace_id' => $workspace->id]);
    $collection = ProductCollection::factory()->create(['workspace_id' => $workspace->id, 'brand_id' => $brand->id]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    app(ProductOrganizationService::class)->assignment($user, ['brand_id' => $other->id, 'product_collection_id' => $collection->id], $recipe);
})->throws(ValidationException::class);

it('creates organization groups through the mounted workbench action', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $component = app(RecipeWorkbench::class);
    $component->mount();
    $service = app(ProductOrganizationService::class);
    $brand = $component->createProductOrganizationGroup(['type' => 'brand', 'name' => 'Umbrella'], $service);
    $collection = $component->createProductOrganizationGroup(['type' => 'collection', 'name' => 'Botanical', 'brand_id' => $brand['id']], $service);
    expect($brand['ok'])->toBeTrue()
        ->and($collection['ok'])->toBeTrue()
        ->and($collection['organization']['collections'])->toHaveCount(1);
});

it('keeps dependent organization choices and draft serialization aligned in the browser', function () {
    runRecipeWorkbenchScenario(<<<'JS'
workbench.organizationBrands = [{ id: 1, name: 'Umbrella' }, { id: 2, name: 'Other' }];
workbench.organizationCollections = [{ id: 3, brand_id: 1, name: 'Botanical' }];
assert.equal(workbench.productCollectionLabel(workbench.organizationCollections[0]), 'Umbrella · Botanical');
workbench.changeProductCollection(3);
assert.equal(workbench.brandId, 1);
assert.equal(workbench.productCollectionId, 3);
workbench.changeOrganizationBrand(2);
assert.equal(workbench.productCollectionId, null);
assert.equal(workbench.availableProductCollections.length, 0);
workbench.applyDraft({ brandId: 1, productCollectionId: 3 });
const payloadModule = loadModule('resources/js/recipe-workbench/payload.js');
const draft = payloadModule.namespace.serializeDraft(workbench);
assert.equal(draft.brand_id, 1);
assert.equal(draft.product_collection_id, 3);
workbench.$wire.createProductOrganizationGroup = async () => ({ ok: true, id: 4,
    organization: { brands: workbench.organizationBrands, collections: [...workbench.organizationCollections, {id: 4, brand_id: 1, name: 'New'}] },
    message: 'Saved',
});


workbench.startOrganizationGroup('collection');
workbench.organizationGroupName = 'New';
assert.equal(await workbench.createOrganizationGroup(), true);
assert.equal(workbench.productCollectionId, 4);
assert.equal(workbench.brandId, 1);
assert.equal(workbench.organizationGroupType, null);
const creating = deferred();
workbench.$wire.createProductOrganizationGroup = async () => creating.promise;
workbench.startOrganizationGroup('brand');
workbench.organizationGroupName = 'Pending brand';
const pending = workbench.createOrganizationGroup();
workbench.startOrganizationGroup('collection');
assert.equal(workbench.organizationGroupType, 'brand');
creating.resolve({ ok: true, id: 5, organization: { brands: [...workbench.organizationBrands, {id: 5, name: 'Pending brand'}], collections: workbench.organizationCollections }, message: 'Saved' });
await pending;
assert.equal(workbench.brandId, 5);
assert.equal(workbench.productCollectionId, null);
JS);
});

it('opens organization editing independently from formula settings without losing selections', function () {
    runRecipeWorkbenchScenario(<<<'JS'
workbench.organizationBrands = [{ id: 1, name: 'Umbrella' }];
workbench.organizationCollections = [{ id: 2, brand_id: 1, name: 'Botanical' }];
workbench.changeProductCollection(2);
assert.equal(workbench.productOrganizationLabel, 'Umbrella · Botanical');
let focusCount = 0;
workbench.$refs = { productOrganizationTrigger: { focus() { focusCount++; } } };
workbench.$nextTick = (callback) => callback();
workbench.isFormulaSettingsOpen = false;
workbench.openOrganizationDialog();
assert.equal(workbench.isOrganizationDialogOpen, true);
assert.equal(workbench.isFormulaSettingsOpen, false);
workbench.closeOrganizationDialog();
assert.equal(workbench.isOrganizationDialogOpen, false);
assert.equal(workbench.brandId, 1);
assert.equal(workbench.productCollectionId, 2);
assert.equal(focusCount, 1);
JS);
});

it('keeps differently named brands and collections distinct when their slugs collide', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $user->update(['active_workspace_id' => $workspace->id]);
    $service = app(ProductOrganizationService::class);
    $first = $service->createBrand($user, 'Brand A');
    $second = $service->createBrand($user, 'Brand A+');
    expect($second->id)->not->toBe($first->id)
        ->and($second->name)->toBe('Brand A+')
        ->and($service->createBrand($user, 'brand a')->id)->toBe($first->id);
    $firstCollection = $service->createCollection($user, $first->id, 'Natural');
    $secondCollection = $service->createCollection($user, $first->id, 'Natural+');
    expect($secondCollection->id)->not->toBe($firstCollection->id)
        ->and($secondCollection->name)->toBe('Natural+');
});

it('rejects organization creation after switching away from the mounted workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $page = Livewire::actingAs($user)->test(RecipeWorkbench::class, ['productFamilySlug' => 'soap']);
    $other = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $user->forceFill(['active_workspace_id' => $other->id])->save();

    $page->call('createProductOrganizationGroup', ['type' => 'brand', 'name' => 'Must not move'])->assertForbidden();
    $this->assertDatabaseMissing('brands', ['name' => 'Must not move']);
});
