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

    $response = $this->actingAs($user)
        ->get(route('recipes.index'))
        ->assertSuccessful()
        ->assertSee('Olive Coconut Bar')
        ->assertSee('Open')
        ->assertSee('Duplicate')
        ->assertSee('Lock product')
        ->assertDontSee('Open draft')
        ->assertDontSee('Edit formula')
        ->assertDontSee('Use recipe');

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $link = $xpath->query('//article/a[@data-product-card-link]')->item(0);

    expect($link)->not->toBeNull();
    expect($link->getAttribute('href'))->toBe(route('recipes.edit', $recipe))
        ->and($link->getAttribute('aria-label'))->toBe('Open workbench: Olive Coconut Bar')
        ->and($link->hasAttribute('wire:navigate'))->toBeTrue()
        ->and($link->getAttribute('class'))->toContain('absolute inset-0 z-10')
        ->and($xpath->query('//article/a[@data-product-card-link]//button')->length)->toBe(0)
        ->and($xpath->query('//article//*[@data-product-card-actions]')->item(0)->getAttribute('class'))->toContain('z-20');

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

it('switches product status live and clears status-only filters', function () {
    $fixture = recipesIndexTaxonomyFixture();
    $this->actingAs($fixture['user']);
    Recipe::query()->where('name', 'Winter Candle')->update(['archived_at' => now()]);

    Livewire::actingAs($fixture['user'])->test(RecipesIndex::class)
        ->assertSee('Daily Moisturizer')
        ->assertDontSee('Winter Candle')
        ->assertDontSeeHtml('data-product-clear-filters')
        ->set('archivedFilter', 'archived')
        ->assertSee('Winter Candle')
        ->assertDontSee('Daily Moisturizer')
        ->assertSee('1 matching product')
        ->assertSeeHtml('data-product-clear-filters')
        ->set('archivedFilter', '')
        ->assertSee('Winter Candle')
        ->assertSee('Daily Moisturizer')
        ->assertSee('3 matching products')
        ->call('clearFilters')
        ->assertSet('archivedFilter', 'active')
        ->assertSee('Daily Moisturizer')
        ->assertDontSee('Winter Candle')
        ->assertDontSeeHtml('data-product-clear-filters');
});

it('explains an empty archived result as a filter result rather than a new account', function () {
    $fixture = recipesIndexTaxonomyFixture();

    Livewire::actingAs($fixture['user'])->test(RecipesIndex::class)
        ->set('archivedFilter', 'archived')
        ->assertSee('0 matching products')
        ->assertSee('No products match these filters')
        ->assertSee('Try another search or clear your filters.')
        ->assertDontSee('No products yet')
        ->assertSeeHtml('data-product-clear-filters');
});

it('summarizes classification filters and resets search classification and status together', function () {
    $fixture = recipesIndexTaxonomyFixture();

    Livewire::actingAs($fixture['user'])->test(RecipesIndex::class)
        ->set('search', 'Daily')
        ->set('productAreaFilter', 'personal-care')
        ->set('productCategoryFilter', 'skin-care')
        ->set('productTypeFilter', 'face-cream')
        ->assertSee('Personal care · Skin care · Face cream')
        ->assertSeeHtml('data-product-filter-count')
        ->assertSee('1 matching product')
        ->set('archivedFilter', '')
        ->assertSee('Personal care · Skin care · Face cream · All statuses')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('productAreaFilter', '')
        ->assertSet('productCategoryFilter', '')
        ->assertSet('productTypeFilter', '')
        ->assertSet('archivedFilter', 'active')
        ->assertDontSeeHtml('data-product-active-filters')
        ->assertDontSeeHtml('data-product-filter-count')
        ->assertSee('3 products');
});

it('keeps classification fields inside an accessible filter panel outside the toolbar', function () {
    $user = User::factory()->create();
    $component = Livewire::actingAs($user)->test(RecipesIndex::class);
    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $xpath = new DOMXPath($document);
    $toggle = $xpath->query('//button[@data-product-filter-toggle]')->item(0);
    $panel = $xpath->query('//*[@data-product-filter-panel]')->item(0);

    expect($xpath->query('//*[@data-product-filter-toolbar]//select'))->toHaveCount(1);
    expect($xpath->query('//*[@data-product-filter-toolbar]//input[@type="search"]'))->toHaveCount(1);
    expect($xpath->query('//*[@data-product-filter-panel]//select'))->toHaveCount(3);
    expect($toggle->getAttribute('aria-controls'))->toBe($panel->getAttribute('id'));
    expect($panel->getAttribute('role'))->toBe('dialog')
        ->and($panel->getAttribute('aria-modal'))->toBe('true')
        ->and($panel->getAttribute('x-show'))->toBe('filtersOpen');
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

it('paginates products in stable groups of twelve and restores a page from the URL', function () {
    $fixture = recipesIndexTaxonomyFixture();
    $products = Recipe::factory()->count(13)->create([
        'product_family_id' => $fixture['cosmeticFamily']->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $fixture['user']->id,
        'visibility' => Visibility::Private,
        'created_at' => now()->addMinute(),
    ]);
    $this->actingAs($fixture['user']);

    $component = Livewire::test(RecipesIndex::class)
        ->assertViewHas('recipeCount', 16)
        ->assertViewHas('recipes', fn ($recipes): bool => $recipes->modelKeys() === $products->reverse()->take(12)->values()->modelKeys())
        ->assertSeeHtml('aria-label="'.__('table.pagination.label').'"')
        ->assertDontSeeHtml('wire:model.live="perPage"')
        ->call('nextPage')
        ->assertViewHas('recipes', fn ($recipes): bool => $recipes->count() === 4 && $recipes->first()->is($products->first()))
        ->assertViewHas('recipeCount', 16);

    Livewire::withQueryParams(['page' => 2])->test(RecipesIndex::class)
        ->assertViewHas('recipes', fn ($recipes): bool => $recipes->currentPage() === 2 && $recipes->count() === 4);

    $component->call('previousPage')
        ->assertViewHas('recipes', fn ($recipes): bool => $recipes->currentPage() === 1 && $recipes->count() === 12);
});

it('returns to the first page when a product filter changes', function (string $property, string $value) {
    $fixture = recipesIndexTaxonomyFixture();
    Recipe::factory()->count(10)->create([
        'product_family_id' => $fixture['cosmeticFamily']->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $fixture['user']->id,
        'visibility' => Visibility::Private,
    ]);
    $this->actingAs($fixture['user']);

    Livewire::test(RecipesIndex::class)
        ->call('gotoPage', 2)
        ->set($property, $value)
        ->assertSet('paginators.page', 1)
        ->assertViewHas('recipes', fn ($recipes): bool => $recipes->currentPage() === 1);
})->with([
    ['search', 'Daily Moisturizer'],
    ['archivedFilter', 'all'],
    ['productAreaFilter', 'personal-care'],
    ['productCategoryFilter', 'skin-care'],
    ['productTypeFilter', 'face-cream'],
]);

it('clears filters back to page one and hides pagination for a single page', function () {
    $fixture = recipesIndexTaxonomyFixture();
    $this->actingAs($fixture['user']);

    Livewire::test(RecipesIndex::class)
        ->set('archivedFilter', 'all')
        ->call('gotoPage', 2)
        ->call('clearFilters')
        ->assertSet('paginators.page', 1)
        ->assertSee('Daily Moisturizer')
        ->assertDontSeeHtml('aria-label="'.__('table.pagination.label').'"');
});

it('keeps filter options from other pages and excludes other users product types', function () {
    $fixture = recipesIndexTaxonomyFixture();
    $privateType = ProductType::factory()->create(['product_family_id' => $fixture['cosmeticFamily']->id]);
    $otherUser = User::factory()->create();
    Recipe::factory()->create([
        'product_family_id' => $fixture['cosmeticFamily']->id,
        'product_type_id' => $privateType->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
    ]);
    Recipe::factory()->count(12)->create([
        'product_family_id' => $fixture['cosmeticFamily']->id,
        'product_type_id' => null,
        'owner_type' => OwnerType::User,
        'owner_id' => $fixture['user']->id,
        'visibility' => Visibility::Private,
        'created_at' => now()->addMinute(),
    ]);
    $this->actingAs($fixture['user']);

    Livewire::test(RecipesIndex::class)
        ->assertViewHas('recipeCount', 15)
        ->assertViewHas('productTypeOptions', fn ($options): bool => $options->keys()->sort()->values()->all() === ['candle-wax-melt', 'face-cream', 'shampoo'])
        ->set('productTypeFilter', 'candle-wax-melt')
        ->assertSee('Winter Candle')
        ->assertViewHas('recipeCount', 1);
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
