<?php

use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipeContentUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('rejects a media path belonging to another formula namespace', function () {
    Storage::fake('local');
    config(['media.recipe_disk' => 'local']);

    $firstRecipe = Recipe::factory()->create();
    $otherRecipe = Recipe::factory()->create();
    $otherPath = 'recipes/'.$otherRecipe->public_id.'/featured-images/private.webp';
    Storage::disk('local')->put($otherPath, 'other-formula-image');

    expect(fn () => app(RecipeContentUpdater::class)->update($firstRecipe, [
        'description' => null,
        'manufacturing_instructions' => null,
        'featured_image_path' => $otherPath,
    ]))->toThrow(ValidationException::class, 'does not belong to this formula');

    expect($firstRecipe->fresh()->featured_image_path)->toBeNull()
        ->and(Storage::disk('local')->exists($otherPath))->toBeTrue();
});

it('deletes the previous featured image when the recipe image is cleared', function () {
    Storage::fake('local');

    config([
        'media.recipe_disk' => 'local',
        'media.recipe_visibility' => 'private',
    ]);

    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_id' => $user->id,
        'featured_image_path' => 'recipes/featured-images/original.webp',
    ]);

    Storage::disk('local')->put('recipes/featured-images/original.webp', 'old-image');

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', '<p>Presentation only.</p>')
        ->set('data.manufacturing_instructions', '<p>Manufacturing only.</p>')
        ->set('data.featured_image_path', null)
        ->call('saveRecipeContent')
        ->assertSet('recipeContentStatus', 'success');

    expect(Storage::disk('local')->exists('recipes/featured-images/original.webp'))->toBeFalse()
        ->and($recipe->fresh()->featured_image_path)->toBeNull();
});

it('keeps a shared rich content attachment when it moves between recipe editors in one save', function () {
    Storage::fake('local');

    config([
        'media.recipe_disk' => 'local',
        'media.recipe_visibility' => 'private',
    ]);

    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $sharedAttachment = 'recipes/rich-content/shared.webp';
    $sharedHtml = '<p><img data-id="'.$sharedAttachment.'" src="/storage/'.$sharedAttachment.'"></p>';

    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_id' => $user->id,
        'description' => '<p>Presentation intro.</p>',
        'manufacturing_instructions' => $sharedHtml,
    ]);

    Storage::disk('local')->put($sharedAttachment, 'shared-image');

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', $sharedHtml)
        ->set('data.manufacturing_instructions', '<p>Step 1: Warm the oils.</p>')
        ->call('saveRecipeContent')
        ->assertSet('recipeContentStatus', 'success');

    expect(Storage::disk('local')->exists($sharedAttachment))->toBeTrue()
        ->and($recipe->fresh()->description)->toContain($sharedAttachment)
        ->and($recipe->fresh()->manufacturing_instructions)->not->toContain($sharedAttachment);
});
