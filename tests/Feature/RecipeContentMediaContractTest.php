<?php

use App\Livewire\Dashboard\RecipeWorkbench;
use App\MediaAssetStatus;
use App\MediaAssetUsageRole;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MediaAssetUsageService;
use App\Services\RecipeContentUpdater;
use App\Services\RecipeRichContentAttachmentProvider;
use App\Services\RecipeSopSnapshotService;
use App\Support\RichContentAttachmentPaths;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('preserves a legacy featured path when saving content without retired upload fields', function () {
    $recipe = Recipe::factory()->create([
        'featured_image_path' => 'recipes/legacy/featured.webp',
        'featured_image_original_name' => 'legacy.webp',
    ]);

    app(RecipeContentUpdater::class)->update($recipe, [
        'description' => 'Updated description.',
        'manufacturing_instructions' => 'Updated instructions.',
    ]);

    expect($recipe->fresh()->featured_image_path)->toBe('recipes/legacy/featured.webp')
        ->and($recipe->fresh()->featured_image_original_name)->toBe('legacy.webp');
});

it('allows unrelated edits with unchanged legacy inline images but rejects added inline images', function () {
    $legacyPath = 'recipes/legacy/rich-content/legacy.webp';
    $recipe = Recipe::factory()->create([
        'description' => '<p><img src="/'.$legacyPath.'"></p><p>Legacy description.</p>',
    ]);

    app(RecipeContentUpdater::class)->update($recipe, [
        'description' => '<p><img src="/'.$legacyPath.'"></p><p>Edited description.</p>',
        'manufacturing_instructions' => null,
    ]);

    expect($recipe->fresh()->description)->toContain('Edited description.');

    expect(fn () => app(RecipeContentUpdater::class)->update($recipe, [
        'description' => '<p><img src="/'.$legacyPath.'"></p><img src="/recipes/legacy/rich-content/new.webp">',
        'manufacturing_instructions' => null,
    ]))->toThrow(ValidationException::class);
});

it('rejects a legacy media path belonging to another formula namespace', function () {
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

it('keeps recipe content unchanged when an inline description image fails validation', function () {
    $recipe = Recipe::factory()->create([
        'description' => '<p>Original presentation.</p>',
    ]);

    $exception = null;

    try {
        app(RecipeContentUpdater::class)->update($recipe, [
            'description' => recipeMediaContractTipTapContent([
                '018fa7f2-91aa-74a5-a665-18f8f3bf42d1',
            ]),
            'manufacturing_instructions' => null,
            'featured_image_path' => null,
        ]);
    } catch (ValidationException $validationException) {
        $exception = $validationException;
    }

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception->errors())->toHaveKey('description')
        ->and($exception->errors()['description'][0])
        ->toBe('The product description is text-only. Choose images from the Media Library.')
        ->and($recipe->fresh()->description)->toBe('<p>Original presentation.</p>');
});

it('does not persist an inline image submitted through the live description field', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace, [
        'description' => '<p>Original presentation.</p>',
    ]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', '<p><img data-id="018fa7f2-91aa-74a5-a665-18f8f3bf42d1"></p>')
        ->call('saveRecipeContent')
        ->assertHasErrors(['description']);

    expect($recipe->fresh()->description)->toBe('<p>Original presentation.</p>');
});

it('rejects ready Media Library images in the text-only description', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);

    expect(fn () => app(RecipeContentUpdater::class)->update($recipe, [
        'description' => recipeMediaContractAssetHtml($asset),
        'manufacturing_instructions' => null,
    ]))->toThrow(ValidationException::class);

    expect($recipe->fresh()->description)->toBeNull();
});

it('allows only ready same-workspace Media Library identities in manufacturing instructions', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace);
    $ready = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $processing = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);
    $outside = MediaAsset::factory()->ready()->create();

    app(RecipeContentUpdater::class)->update($recipe, [
        'description' => null,
        'manufacturing_instructions' => recipeMediaContractAssetHtml($ready),
    ]);

    expect($recipe->fresh()->manufacturing_instructions)
        ->toContain('media-asset:'.$ready->public_id);

    foreach ([
        recipeMediaContractAssetHtml($processing),
        recipeMediaContractAssetHtml($outside),
        '<p><img data-id="media-asset:tampered" src="https://example.com/image.jpg"></p>',
        '<p><img data-id="media-asset:'.$ready->public_id.'" src="https://example.com/image.jpg"></p>',
        '<p><img src="https://example.com/image.jpg"></p>',
    ] as $invalidInstructions) {
        expect(fn () => app(RecipeContentUpdater::class)->update($recipe, [
            'description' => null,
            'manufacturing_instructions' => $invalidInstructions,
        ]))->toThrow(ValidationException::class);
    }

    expect($processing->status)->toBe(MediaAssetStatus::Processing);
});

it('resolves stable Media Library identities only within the recipe workspace', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace);
    $ready = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $processing = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);
    $outside = MediaAsset::factory()->ready()->create();
    $provider = $recipe
        ->getRichContentAttribute('manufacturing_instructions')
        ->getFileAttachmentProvider();

    expect($provider?->getFileAttachmentUrl('media-asset:'.$ready->public_id))
        ->toBe(route('media.show', [$ready, 'master']))
        ->and($provider?->getFileAttachmentUrl('media-asset:'.$processing->public_id))
        ->toBeNull()
        ->and($provider?->getFileAttachmentUrl('media-asset:'.$outside->public_id))
        ->toBeNull()
        ->and($provider?->getFileAttachmentUrl('https://example.com/image.jpg'))
        ->toBeNull();

    $rendered = RichContentRenderer::make(
        '<p><img data-id="media-asset:'.$ready->public_id.'" src="https://example.com/tampered.jpg"></p>',
    )
        ->fileAttachmentProvider($provider)
        ->toHtml();

    expect($rendered)
        ->toContain(route('media.show', [$ready, 'master']))
        ->not->toContain('https://example.com/tampered.jpg');
});

it('resolves eight inline Media Library assets with one provider query and no cross-recipe leakage', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace);
    $assets = MediaAsset::factory()->ready()->count(8)->create([
        'workspace_id' => $workspace->id,
    ]);
    $recipe->update([
        'manufacturing_instructions' => $assets
            ->map(fn (MediaAsset $asset): string => recipeMediaContractAssetHtml($asset))
            ->implode(''),
    ]);
    $provider = app(RecipeRichContentAttachmentProvider::class)
        ->attribute(RichContentAttribute::make($recipe, 'manufacturing_instructions'));

    DB::flushQueryLog();
    DB::enableQueryLog();

    foreach ($assets as $asset) {
        expect($provider->getFileAttachmentUrl(RichContentAttachmentPaths::mediaAssetIdentity($asset->public_id)))
            ->toBe(route('media.show', [$asset, 'master']));
    }

    $providerQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'media_assets'))
        ->count();
    DB::disableQueryLog();

    $otherWorkspace = Workspace::factory()->create();
    $otherRecipe = Recipe::factory()->create(['workspace_id' => $otherWorkspace->id]);
    $provider->attribute(RichContentAttribute::make($otherRecipe, 'manufacturing_instructions'));

    expect($providerQueries)->toBeLessThanOrEqual(1)
        ->and($provider->getFileAttachmentUrl(
            RichContentAttachmentPaths::mediaAssetIdentity($assets->first()->public_id),
        ))->toBeNull();
});

it('extracts Media Library public IDs from saved HTML and TipTap state without changing legacy paths', function () {
    $firstId = '01JZ0SOPMEDIA0000000000001';
    $secondId = '01JZ0SOPMEDIA0000000000002';
    $legacyPath = 'recipes/legacy/rich-content/legacy.webp';
    $html = '<p><img data-id="media-asset:'.$firstId.'"><img data-id="'.$legacyPath.'"></p>';
    $tipTap = recipeMediaContractTipTapContent([
        'media-asset:'.$secondId,
    ]);

    expect(RichContentAttachmentPaths::extractMediaAssetPublicIds($html)->all())
        ->toBe([$firstId])
        ->and(RichContentAttachmentPaths::extractMediaAssetPublicIds($tipTap)->all())
        ->toBe([$secondId])
        ->and(RichContentAttachmentPaths::extract($html)->all())
        ->toBe([$legacyPath]);
});

it('saves featured and inline manufacturing images as reusable media usages', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace);
    $featured = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $manufacturing = MediaAsset::factory()->ready()->count(2)->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', '<p>Gentle soap.</p>')
        ->set(
            'data.manufacturing_instructions',
            '<p>Blend until trace.</p>'.$manufacturing
                ->map(fn (MediaAsset $asset): string => recipeMediaContractAssetHtml($asset))
                ->implode(''),
        )
        ->set('data.featured_media_asset_id', $featured->id)
        ->call('saveRecipeContent')
        ->assertHasNoErrors()
        ->assertSet('recipeContentStatus', 'success');

    expect(app(MediaAssetUsageService::class)->idsFor(
        $recipe,
        MediaAssetUsageRole::RecipeFeatured,
    ))->toBe([$featured->id])
        ->and(app(MediaAssetUsageService::class)->idsFor(
            $recipe,
            MediaAssetUsageRole::RecipeSop,
        ))->toBe($manufacturing->pluck('id')->all())
        ->and($recipe->fresh()->description)->toContain('Gentle soap')
        ->and($recipe->fresh()->manufacturing_instructions)->toContain('Blend until trace');
});

it('coordinates recipe content usages and the current snapshot exactly once', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace);
    $snapshotService = mock(RecipeSopSnapshotService::class);
    $snapshotService->shouldReceive('syncCurrentVersion')
        ->once()
        ->withArgs(fn (Recipe $syncedRecipe, ?string $instructions): bool => $syncedRecipe->is($recipe)
            && str_contains((string) $instructions, 'One coordinated snapshot'));

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.manufacturing_instructions', '<p>One coordinated snapshot.</p>')
        ->call('saveRecipeContent')
        ->assertHasNoErrors()
        ->assertSet('recipeContentStatus', 'success');
});

it('leaves current snapshot ownership to the coordinated content persistence path', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace);
    $currentVersion = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'workspace_id' => $workspace->id,
        'is_current' => true,
        'manufacturing_instructions' => '<p>Original snapshot.</p>',
    ]);

    app(RecipeContentUpdater::class)->update($recipe, [
        'description' => null,
        'manufacturing_instructions' => '<p>Recipe-only mutation.</p>',
    ]);

    expect($recipe->fresh()->manufacturing_instructions)->toBe('<p>Recipe-only mutation.</p>')
        ->and($currentVersion->fresh()->manufacturing_instructions)->toBe('<p>Original snapshot.</p>');
});

it('clears a recipe usage without deleting its reusable library asset', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);

    app(MediaAssetUsageService::class)->syncSingle(
        $user,
        $recipe,
        MediaAssetUsageRole::RecipeFeatured,
        $asset->id,
    );

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.featured_media_asset_id', null)
        ->call('saveRecipeContent')
        ->assertHasNoErrors()
        ->assertSet('recipeContentStatus', 'success');

    expect(MediaAssetUsage::query()->where('media_asset_id', $asset->id)->exists())->toBeFalse()
        ->and($asset->fresh())->not->toBeNull();
});

it('syncs SOP usages from inline manufacturing content and keeps removed library assets', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $currentVersion = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'is_current' => true,
    ]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.manufacturing_instructions', recipeMediaContractAssetHtml($asset))
        ->call('saveRecipeContent')
        ->assertHasNoErrors();

    expect(app(MediaAssetUsageService::class)->idsFor(
        $recipe,
        MediaAssetUsageRole::RecipeSop,
    ))->toBe([$asset->id])
        ->and(app(MediaAssetUsageService::class)->idsFor(
            $currentVersion,
            MediaAssetUsageRole::RecipeSop,
        ))->toBe([$asset->id]);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe->fresh()])
        ->set('data.manufacturing_instructions', '<p>No image remains.</p>')
        ->call('saveRecipeContent')
        ->assertHasNoErrors();

    expect(app(MediaAssetUsageService::class)->idsFor(
        $recipe,
        MediaAssetUsageRole::RecipeSop,
    ))->toBe([])
        ->and($asset->fresh())->not->toBeNull();
});

it('rolls back content when a selected asset is unavailable', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace, [
        'description' => '<p>Original presentation.</p>',
    ]);
    $processing = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', '<p>Should roll back.</p>')
        ->set('data.featured_media_asset_id', $processing->id)
        ->call('saveRecipeContent')
        ->assertHasErrors();

    expect($recipe->fresh()->description)->toBe('<p>Original presentation.</p>')
        ->and(MediaAssetUsage::query()->where('media_asset_id', $processing->id)->exists())->toBeFalse();
});

it('rejects more than eight inline manufacturing images without changing saved content', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace, [
        'manufacturing_instructions' => '<p>Original procedure.</p>',
    ]);
    $assets = MediaAsset::factory()->ready()->count(9)->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set(
            'data.manufacturing_instructions',
            '<p>Should roll back.</p>'.$assets
                ->map(fn (MediaAsset $asset): string => recipeMediaContractAssetHtml($asset))
                ->implode(''),
        )
        ->call('saveRecipeContent')
        ->assertHasErrors();

    expect($recipe->fresh()->manufacturing_instructions)->toBe('<p>Original procedure.</p>')
        ->and(MediaAssetUsage::query()->where('usable_id', $recipe->id)->exists())->toBeFalse();
});

it('rejects nine occurrences of the same inline Media Library image', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $recipe = recipeMediaContractRecipe($user, $workspace, [
        'manufacturing_instructions' => '<p>Original procedure.</p>',
    ]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $instructions = str_repeat(recipeMediaContractAssetHtml($asset), 9);

    expect(RichContentAttachmentPaths::countMediaAssetImageOccurrences($instructions))->toBe(9)
        ->and(fn () => app(RecipeContentUpdater::class)->update($recipe, [
            'description' => null,
            'manufacturing_instructions' => $instructions,
        ]))->toThrow(ValidationException::class);

    expect($recipe->fresh()->manufacturing_instructions)->toBe('<p>Original procedure.</p>')
        ->and(MediaAssetUsage::query()->where('usable_id', $recipe->id)->exists())->toBeFalse();
});

it('counts mixed legacy and Media Library images toward the same procedure limit', function () {
    [$user, $workspace] = recipeMediaContractWorkspace();
    $legacyPath = 'recipes/legacy/rich-content/legacy.webp';
    $legacyImage = '<img data-id="'.$legacyPath.'">';
    $recipe = recipeMediaContractRecipe($user, $workspace, [
        'manufacturing_instructions' => $legacyImage,
    ]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $instructions = $legacyImage.str_repeat(recipeMediaContractAssetHtml($asset), 8);

    expect(RichContentAttachmentPaths::countImageOccurrences($instructions))->toBe(9)
        ->and(fn () => app(RecipeContentUpdater::class)->update($recipe, [
            'description' => null,
            'manufacturing_instructions' => $instructions,
        ]))->toThrow(ValidationException::class);
});

it('rejects more than eight repeated existing legacy images but supports them under the limit', function () {
    $legacyPath = 'recipes/legacy/rich-content/repeated.webp';
    $legacyImage = '<img data-id="'.$legacyPath.'">';
    $recipe = Recipe::factory()->create([
        'manufacturing_instructions' => str_repeat($legacyImage, 8),
    ]);

    app(RecipeContentUpdater::class)->update($recipe, [
        'description' => '<p>Text only.</p>',
        'manufacturing_instructions' => str_repeat($legacyImage, 8).'<p>Edited.</p>',
    ]);

    expect($recipe->fresh()->manufacturing_instructions)->toContain('Edited.');

    $recipe->update(['manufacturing_instructions' => str_repeat($legacyImage, 9)]);

    expect(fn () => app(RecipeContentUpdater::class)->update($recipe, [
        'description' => '<p>Still text only.</p>',
        'manufacturing_instructions' => str_repeat($legacyImage, 9),
    ]))->toThrow(ValidationException::class);
});

/**
 * @return array{User, Workspace}
 */
function recipeMediaContractWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);

    return [$user, $workspace];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function recipeMediaContractRecipe(User $user, Workspace $workspace, array $attributes = []): Recipe
{
    $productFamily = ProductFamily::factory()->create([
        'slug' => fake()->unique()->slug(),
    ]);

    return Recipe::factory()->create([
        'workspace_id' => $workspace->id,
        'owner_id' => $user->id,
        'product_family_id' => $productFamily->id,
        ...$attributes,
    ]);
}

/**
 * @param  array<int, string>  $temporaryIds
 * @return array{type: string, content: array<int, array<string, mixed>>}
 */
function recipeMediaContractTipTapContent(array $temporaryIds): array
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
                        'id' => $temporaryId,
                    ],
                ])
                ->all(),
        ]],
    ];
}

function recipeMediaContractAssetHtml(MediaAsset $asset): string
{
    return '<p><img data-id="media-asset:'.$asset->public_id.'" src="'
        .route('media.show', [$asset, 'master'])
        .'"></p>';
}
