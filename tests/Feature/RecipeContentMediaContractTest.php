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

it('does not mutate recipe content or delete media when image limits fail validation', function () {
    Storage::fake('local');
    config(['media.recipe_disk' => 'local']);

    $recipe = Recipe::factory()->create();
    $previousPath = 'recipes/'.$recipe->public_id.'/rich-content/previous.webp';
    $submittedPaths = [
        'recipes/'.$recipe->public_id.'/rich-content/presentation-1.webp',
        'recipes/'.$recipe->public_id.'/rich-content/presentation-2.webp',
        'recipes/'.$recipe->public_id.'/rich-content/presentation-3.webp',
    ];
    $previousDescription = '<p><img data-id="'.$previousPath.'"></p>';

    $recipe->update(['description' => $previousDescription]);
    Storage::disk('local')->put($previousPath, 'previous-image');

    foreach ($submittedPaths as $submittedPath) {
        Storage::disk('local')->put($submittedPath, 'submitted-image');
    }

    $exception = null;

    try {
        app(RecipeContentUpdater::class)->update($recipe, [
            'description' => '<p>'.collect($submittedPaths)
                ->map(fn (string $path): string => '<img data-id="'.$path.'">')
                ->implode('').'</p>',
            'manufacturing_instructions' => null,
            'featured_image_path' => null,
        ]);
    } catch (ValidationException $validationException) {
        $exception = $validationException;
    }

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception->errors())->toHaveKey('description')
        ->and($exception->errors()['description'][0])->toBe('The product description may contain up to 2 images.');

    expect($recipe->fresh()->description)->toBe($previousDescription)
        ->and(Storage::disk('local')->exists($previousPath))->toBeTrue();

    foreach ($submittedPaths as $submittedPath) {
        expect(Storage::disk('local')->exists($submittedPath))->toBeTrue();
    }
});

it('binds a live TipTap image limit failure to the description field', function () {
    Storage::fake('local');
    config(['media.recipe_disk' => 'local']);

    $user = User::factory()->create();
    $recipe = Recipe::factory()->create([
        'owner_id' => $user->id,
        'description' => '<p>Original presentation.</p>',
    ]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', recipeTipTapContent([
            '018fa7f2-91aa-74a5-a665-18f8f3bf42d1',
            '018fa7f2-91aa-74a5-a665-18f8f3bf42d2',
            '018fa7f2-91aa-74a5-a665-18f8f3bf42d3',
        ]))
        ->call('saveRecipeContent')
        ->assertHasErrors(['data.description'])
        ->assertSet('recipeContentStatus', 'idle');

    expect($recipe->fresh()->description)->toBe('<p>Original presentation.</p>');
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
    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_id' => $user->id,
        'manufacturing_instructions' => '<p>Step 1: Warm the oils.</p>',
    ]);
    $sharedAttachment = 'recipes/'.$recipe->public_id.'/rich-content/shared.webp';
    $sharedHtml = '<p><img data-id="'.$sharedAttachment.'" src="/dashboard/recipes/'.$recipe->public_id.'/media/'.$sharedAttachment.'"></p>';
    $recipe->update(['description' => $sharedHtml]);

    Storage::disk('local')->put($sharedAttachment, 'shared-image');

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', '<p>Presentation intro.</p>')
        ->set('data.manufacturing_instructions', $sharedHtml)
        ->call('saveRecipeContent')
        ->assertSet('recipeContentStatus', 'success');

    expect(Storage::disk('local')->exists($sharedAttachment))->toBeTrue()
        ->and($recipe->fresh()->description)->not->toContain($sharedAttachment)
        ->and($recipe->fresh()->manufacturing_instructions)->toContain($sharedAttachment);
});

/**
 * @param  array<int, string>  $temporaryIds
 * @return array{type: string, content: array<int, array<string, mixed>>}
 */
function recipeTipTapContent(array $temporaryIds): array
{
    return [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => collect($temporaryIds)
                ->map(fn (string $temporaryId): array => [
                    'type' => 'image',
                    'attrs' => [
                        'src' => '/livewire/preview-file/'.$temporaryId,
                        'alt' => null,
                        'title' => null,
                        'id' => $temporaryId,
                        'width' => null,
                        'height' => null,
                    ],
                ])
                ->all(),
        ]],
    ];
}
