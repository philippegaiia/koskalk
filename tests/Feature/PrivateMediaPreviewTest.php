<?php

use App\Forms\Components\MediaAssetPicker;
use App\Livewire\Dashboard\IngredientEditor;
use App\Livewire\Dashboard\PackagingItemEditor;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\MediaAssetUsageRole;
use App\Models\Ingredient;
use App\Models\MediaAsset;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\OwnerType;
use App\Services\MediaAssetUsageService;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('hydrates a recipe featured image from the private media library', function () {
    [$owner, $workspace] = privateMediaWorkspace();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'Lavender soap.webp',
    ]);
    $recipe = Recipe::factory()->create([
        'workspace_id' => $workspace->id,
        'product_family_id' => ProductFamily::factory()->create()->id,
        'owner_id' => $owner->id,
    ]);

    app(MediaAssetUsageService::class)->syncSingle(
        $owner,
        $recipe,
        MediaAssetUsageRole::RecipeFeatured,
        $asset->id,
    );

    $this->actingAs($owner);

    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe]);
    $field = $component->instance()->form->getComponent('featured_media_asset_id');

    expect($field)->toBeInstanceOf(MediaAssetPicker::class)
        ->and((int) $field->getState())->toBe($asset->id);

    $component->assertSee('Lavender soap.webp');
});

it('hydrates ingredient main and icon override images from the private media library', function () {
    [$owner, $workspace] = privateMediaWorkspace();
    $main = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'Sodium citrate.webp',
    ]);
    $icon = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'Sodium citrate icon.webp',
    ]);
    $ingredient = Ingredient::factory()->create([
        'workspace_id' => $workspace->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);

    $usages = app(MediaAssetUsageService::class);
    $usages->syncSingle($owner, $ingredient, MediaAssetUsageRole::IngredientMain, $main->id);
    $usages->syncSingle($owner, $ingredient, MediaAssetUsageRole::IngredientIconOverride, $icon->id);

    $this->actingAs($owner);

    $form = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->instance()
        ->form;

    expect($form->getComponent('featured_media_asset_id'))->toBeInstanceOf(MediaAssetPicker::class)
        ->and((int) $form->getComponent('featured_media_asset_id')->getState())->toBe($main->id)
        ->and($form->getComponent('icon_media_asset_id'))->toBeInstanceOf(MediaAssetPicker::class)
        ->and((int) $form->getComponent('icon_media_asset_id')->getState())->toBe($icon->id);
});

it('hydrates a packaging image from the private media library', function () {
    [$owner, $workspace] = privateMediaWorkspace();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'Amber carton.webp',
    ]);
    $packagingItem = createPackagingItemForWorkspace([
        'user_id' => $owner->id,
        'name' => 'Private carton',
        'unit_cost' => 1.25,
        'currency' => 'EUR',
    ]);

    app(MediaAssetUsageService::class)->syncSingle(
        $owner,
        $packagingItem,
        MediaAssetUsageRole::PackagingMain,
        $asset->id,
    );

    $this->actingAs($owner);

    $field = Livewire::test(PackagingItemEditor::class, ['packagingItem' => $packagingItem])
        ->instance()
        ->form
        ->getComponent('featured_media_asset_id');

    expect($field)->toBeInstanceOf(MediaAssetPicker::class)
        ->and((int) $field->getState())->toBe($asset->id);
});

it('keeps original upload names visible in media picker state', function () {
    [$owner, $workspace] = privateMediaWorkspace();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'Sérum visage.png',
    ]);
    $recipe = Recipe::factory()->create([
        'workspace_id' => $workspace->id,
        'product_family_id' => ProductFamily::factory()->create()->id,
        'owner_id' => $owner->id,
    ]);

    app(MediaAssetUsageService::class)->syncSingle(
        $owner,
        $recipe,
        MediaAssetUsageRole::RecipeFeatured,
        $asset->id,
    );

    $this->actingAs($owner);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->assertSee('Sérum visage.png');
});

it('does not expose opaque storage names as media picker labels', function () {
    [$owner, $workspace] = privateMediaWorkspace();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'Rose soap.png',
        'pending_path' => 'media-pending/01JQ0RANDOM.webp',
    ]);
    $recipe = Recipe::factory()->create([
        'workspace_id' => $workspace->id,
        'product_family_id' => ProductFamily::factory()->create()->id,
        'owner_id' => $owner->id,
    ]);

    app(MediaAssetUsageService::class)->syncSingle(
        $owner,
        $recipe,
        MediaAssetUsageRole::RecipeFeatured,
        $asset->id,
    );

    $this->actingAs($owner);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->assertSee('Rose soap.png')
        ->assertDontSee('01JQ0RANDOM.webp');
});

/**
 * @return array{User, Workspace}
 */
function privateMediaWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);

    return [$owner, $workspace];
}
