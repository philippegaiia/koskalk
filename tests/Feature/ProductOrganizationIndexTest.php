<?php

use App\Enums\MediaAssetUsageRole;
use App\Livewire\Dashboard\RecipesIndex;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\ProductCollection;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MediaAssetUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the product photo or type illustration in the list view', function (bool $hasPhoto) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $this->actingAs($user);
    $type = ProductType::factory()->create(['slug' => 'bar-soap-cleansing-bar']);
    $product = Recipe::factory()->create(['workspace_id' => $workspace->id, 'owner_id' => $user->id, 'product_type_id' => $type->id, 'name' => 'Pictured bar']);
    if ($hasPhoto) {
        $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
        app(MediaAssetUsageService::class)->syncSingle($user, $product, MediaAssetUsageRole::RecipeFeatured, $asset->id);
    }

    $component = Livewire::test(RecipesIndex::class)->call('setViewMode', 'list');
    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $image = (new DOMXPath($document))->query('//*[@data-product-list]//img[@data-product-list-thumbnail]')->item(0);

    expect($image)->not->toBeNull();
    expect($image->getAttribute('src'))->toBe($hasPhoto ? route('media.show', [$asset, 'recipe-index']) : $type->illustration()->url())
        ->and($image->getAttribute('loading'))->toBe('lazy');
})->with(['uploaded photo' => true, 'type illustration' => false]);

it('filters product cards and list rows by brand and collection within the workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $this->actingAs($user);
    $brand = Brand::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Umbrella A']);
    $otherBrand = Brand::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Umbrella B']);
    $collection = ProductCollection::factory()->create(['workspace_id' => $workspace->id, 'brand_id' => $brand->id, 'name' => 'Botanical range']);
    ProductCollection::factory()->create(['workspace_id' => $workspace->id, 'brand_id' => $otherBrand->id, 'name' => 'Botanical range']);
    Recipe::factory()->create(['workspace_id' => $workspace->id, 'owner_id' => $user->id, 'brand_id' => $brand->id, 'product_collection_id' => $collection->id, 'name' => 'Botanical bar']);
    Recipe::factory()->create(['workspace_id' => $workspace->id, 'owner_id' => $user->id, 'brand_id' => $otherBrand->id, 'name' => 'Other bar']);

    $component = Livewire::test(RecipesIndex::class)
        ->assertSee('Umbrella A · Botanical range')->assertSee('Umbrella B · Botanical range')
        ->set('brandFilter', (string) $brand->id)
        ->assertDontSee('Umbrella B · Botanical range')
        ->set('collectionFilter', (string) $collection->id)
        ->assertSee('Botanical bar')->assertDontSee('Other bar');

    $component->call('setViewMode', 'list')->assertSee('Botanical bar')->assertDontSee('Other bar')
        ->assertSeeHtml('data-product-list')
        ->assertSee('Botanical range');
    $component->set('brandFilter', (string) $otherBrand->id)
        ->assertSet('collectionFilter', '')
        ->assertSee('Other bar')->assertDontSee('Botanical bar');
});

it('does not expose another workspace organization in product filter choices', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $otherWorkspace = Workspace::factory()->create();
    Brand::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Visible brand']);
    $hidden = Brand::factory()->create(['workspace_id' => $otherWorkspace->id, 'name' => 'Hidden brand']);
    ProductCollection::factory()->create(['workspace_id' => $otherWorkspace->id, 'brand_id' => $hidden->id, 'name' => 'Hidden collection']);

    $this->actingAs($user);
    Livewire::test(RecipesIndex::class)->assertSee('Visible brand')->assertDontSee('Hidden brand')->assertDontSee('Hidden collection');
});

it('remembers the product view and resets organization filters with the other filters', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test(RecipesIndex::class)->call('setViewMode', 'list')->assertSet('viewMode', 'list');
    Livewire::test(RecipesIndex::class)->assertSet('viewMode', 'list')
        ->set('brandFilter', '123')->set('collectionFilter', '456')->call('clearFilters')
        ->assertSet('brandFilter', '')->assertSet('collectionFilter', '')->assertSet('viewMode', 'list');
    Livewire::test(RecipesIndex::class)->call('setViewMode', 'invalid')->assertSet('viewMode', 'list');
});

it('offers bounded page sizes for the product list', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Recipe::factory()->count(11)->create(['owner_id' => $user->id]);

    Livewire::test(RecipesIndex::class)->call('setViewMode', 'list')->set('perPage', 10)
        ->assertViewHas('recipes', fn ($recipes): bool => $recipes->count() === 10 && $recipes->perPage() === 10)
        ->assertSeeHtml('value="10"')
        ->set('perPage', 99999)
        ->assertViewHas('recipes', fn ($recipes): bool => $recipes->perPage() === 25);
});
