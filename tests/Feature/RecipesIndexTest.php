<?php

use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Livewire\Dashboard\RecipesIndex;
use App\Models\ProductArea;
use App\Models\ProductCategory;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows saved recipes on the recipes index page', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Olive Coconut Bar',
        'slug' => 'olive-coconut-bar',
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Published Olive Coconut Bar',
        'is_current' => false,
        'version_number' => 2,
        'saved_at' => now(),
    ]);
    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $recipe->name,
        'is_current' => true,
        'version_number' => 3,
    ]);

    $this->actingAs($user)
        ->get(route('recipes.index'))
        ->assertSuccessful()
        ->assertSee('Olive Coconut Bar')
        ->assertSee('Open')
        ->assertSee('Duplicate')
        ->assertSee('Lock product')
        ->assertDontSee('Open draft')
        ->assertDontSee('Edit formula')
        ->assertDontSee('Use recipe');
});

it('only shows recipes that belong to the current user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $visibleRecipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Visible Formula',
        'slug' => 'visible-formula',
    ]);

    $hiddenRecipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'name' => 'Hidden Formula',
        'slug' => 'hidden-formula',
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $visibleRecipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $visibleRecipe->name,
        'is_current' => true,
        'version_number' => 1,
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $hiddenRecipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'name' => $hiddenRecipe->name,
        'is_current' => true,
        'version_number' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('recipes.index'))
        ->assertSuccessful()
        ->assertSee('Visible Formula')
        ->assertDontSee('Hidden Formula');
});

it('only resolves owned workspace ids once while rendering the recipes index', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
    ]);
    WorkspaceMember::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Workspace Formula',
        'slug' => 'workspace-formula',
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => $recipe->name,
        'is_current' => true,
        'version_number' => 1,
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Workspace Formula Published',
        'is_current' => false,
        'version_number' => 2,
        'saved_at' => now(),
    ]);

    $workspaceQueries = [];

    DB::listen(function ($query) use (&$workspaceQueries): void {
        if (
            str_contains($query->sql, '"workspaces"')
            || str_contains($query->sql, '"workspace_members"')
        ) {
            $workspaceQueries[] = $query->sql;
        }
    });

    $this->actingAs($user)
        ->get(route('recipes.index'))
        ->assertSuccessful();

    expect($workspaceQueries)->toHaveCount(1);
});

it('searches Products by finished-product area category and type names', function (string $searchTerm): void {
    $fixture = recipesIndexTaxonomyFixture();

    $this->actingAs($fixture['user'])
        ->get(route('recipes.index', ['q' => $searchTerm]))
        ->assertSuccessful()
        ->assertSee('Daily Moisturizer')
        ->assertDontSee('Winter Candle');
})->with(['Personal care', 'Skin care', 'Face cream']);

it('cascades finished-product taxonomy options and clears child filters', function (): void {
    $fixture = recipesIndexTaxonomyFixture();
    $this->actingAs($fixture['user']);

    Livewire::test(RecipesIndex::class)
        ->set('productAreaFilter', 'personal-care')
        ->assertViewHas('productCategoryOptions', fn ($options): bool => $options->keys()->all() === ['hair-care', 'skin-care'])
        ->set('productCategoryFilter', 'skin-care')
        ->assertViewHas('productTypeOptions', fn ($options): bool => $options->keys()->all() === ['face-cream'])
        ->set('productTypeFilter', 'face-cream')
        ->set('productAreaFilter', 'home-household')
        ->assertSet('productCategoryFilter', '')
        ->assertSet('productTypeFilter', '')
        ->assertViewHas('productCategoryOptions', fn ($options): bool => $options->keys()->all() === ['home-fragrance']);
});

it('filters Products by area category and type URL state', function (): void {
    $fixture = recipesIndexTaxonomyFixture();

    $this->actingAs($fixture['user'])
        ->get(route('recipes.index', [
            'area' => 'personal-care',
            'category' => 'skin-care',
            'type' => 'face-cream',
        ]))
        ->assertSuccessful()
        ->assertSee('Daily Moisturizer')
        ->assertDontSee('Clarifying Shampoo')
        ->assertDontSee('Winter Candle');
});

it('uses Product Type as the card label and a concise legacy fallback', function (): void {
    $fixture = recipesIndexTaxonomyFixture();

    Recipe::factory()->create([
        'product_family_id' => $fixture['cosmeticFamily']->id,
        'product_type_id' => null,
        'owner_type' => OwnerType::User,
        'owner_id' => $fixture['user']->id,
        'visibility' => Visibility::Private,
        'name' => 'Legacy Product',
        'slug' => 'legacy-product',
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('recipes.index'))
        ->assertSuccessful()
        ->assertSee('<span class="sk-badge sk-badge-neutral">Face cream</span>', false)
        ->assertSee('<span class="sk-badge sk-badge-neutral">Unclassified product</span>', false)
        ->assertDontSee('<span class="sk-badge sk-badge-neutral">Cosmetic</span>', false);
});

it('uses the product type fallback image when the recipe has no uploaded image', function () {
    Storage::fake(MediaStorage::publicDisk());

    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'slug' => 'cosmetic',
        'name' => 'Cosmetic',
    ]);
    $lotionType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
        'fallback_image_path' => 'product-types/fallback-images/cream-lotion.webp',
    ]);

    Storage::disk(MediaStorage::publicDisk())->put($lotionType->fallback_image_path, 'fake-webp');

    Recipe::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'product_type_id' => $lotionType->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Daily Moisturizer',
        'slug' => 'daily-moisturizer',
    ]);

    $this->actingAs($user)
        ->get(route('recipes.index'))
        ->assertSuccessful()
        ->assertSee('product-types/fallback-images/cream-lotion.webp', false)
        ->assertSee('Cream / lotion');
});

/**
 * @return array{user: User, cosmeticFamily: ProductFamily}
 */
function recipesIndexTaxonomyFixture(): array
{
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'slug' => 'cosmetic',
        'name' => 'Cosmetic',
    ]);
    $personalArea = ProductArea::factory()->create([
        'name' => 'Personal care',
        'slug' => 'personal-care',
        'sort_order' => 10,
    ]);
    $homeArea = ProductArea::factory()->create([
        'name' => 'Home & household',
        'slug' => 'home-household',
        'sort_order' => 20,
    ]);
    $skinCategory = ProductCategory::factory()->create([
        'product_area_id' => $personalArea->id,
        'name' => 'Skin care',
        'slug' => 'skin-care',
        'sort_order' => 20,
    ]);
    $hairCategory = ProductCategory::factory()->create([
        'product_area_id' => $personalArea->id,
        'name' => 'Hair care',
        'slug' => 'hair-care',
        'sort_order' => 10,
    ]);
    $homeFragranceCategory = ProductCategory::factory()->create([
        'product_area_id' => $homeArea->id,
        'name' => 'Home fragrance',
        'slug' => 'home-fragrance',
    ]);
    $faceCreamType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'product_category_id' => $skinCategory->id,
        'name' => 'Face cream',
        'slug' => 'face-cream',
    ]);
    $shampooType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'product_category_id' => $hairCategory->id,
        'name' => 'Shampoo',
        'slug' => 'shampoo',
    ]);
    $candleType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'product_category_id' => $homeFragranceCategory->id,
        'name' => 'Candle / wax melt',
        'slug' => 'candle-wax-melt',
    ]);

    foreach ([
        [$faceCreamType, 'Daily Moisturizer', 'daily-moisturizer'],
        [$shampooType, 'Clarifying Shampoo', 'clarifying-shampoo'],
        [$candleType, 'Winter Candle', 'winter-candle'],
    ] as [$productType, $name, $slug]) {
        Recipe::factory()->create([
            'product_family_id' => $cosmeticFamily->id,
            'product_type_id' => $productType->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'visibility' => Visibility::Private,
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    return [
        'user' => $user,
        'cosmeticFamily' => $cosmeticFamily,
    ];
}
