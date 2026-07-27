<?php

use App\Jobs\RegenerateMediaAssetConversionsJob;
use App\Livewire\Dashboard\MediaLibraryIndex;
use App\MediaAssetStatus;
use App\MediaAssetType;
use App\MediaAssetUsageRole;
use App\Models\Ingredient;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\MediaLabel;
use App\Models\Plan;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaAssetLibraryService;
use App\WorkspaceMemberRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows only the active workspace media and its quota', function () {
    [$user, $workspace] = mediaLibraryWorkspace(limit: 3);
    $visible = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'Amber bottle.jpg',
    ]);
    MediaAsset::factory()->ready()->create([
        'original_filename' => 'Other workspace.jpg',
    ]);

    $this->withoutVite()
        ->actingAs($user)
        ->get(route('media.index'))
        ->assertOk()
        ->assertSeeLivewire(MediaLibraryIndex::class);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertSee($visible->original_filename)
        ->assertDontSee('Other workspace.jpg')
        ->assertSee('1 of 3 media assets used');
});

it('searches filenames and filters used and unused assets', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $used = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'Lavender soap.jpg',
    ]);
    $unused = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'Citrus bottle.jpg',
    ]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    MediaAssetUsage::factory()->create([
        'media_asset_id' => $used->id,
        'usable_type' => Recipe::class,
        'usable_id' => $recipe->id,
        'role' => MediaAssetUsageRole::RecipeFeatured,
    ]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->set('search', 'lavender')
        ->assertSee('Lavender soap.jpg')
        ->assertDontSee('Citrus bottle.jpg')
        ->set('search', '')
        ->set('usageFilter', 'unused')
        ->assertDontSee('Lavender soap.jpg')
        ->assertSee('Citrus bottle.jpg')
        ->set('usageFilter', 'used')
        ->assertSee('Lavender soap.jpg')
        ->assertDontSee('Citrus bottle.jpg');
});

it('keeps label controls hidden until the workspace creates its first label', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertDontSeeHtml('data-media-label-filter');

    MediaLabel::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Certificates',
        'normalized_name' => 'certificates',
    ]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertSeeHtml('data-media-label-filter');
});

it('filters the library by media type and workspace labels', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $certificate = MediaLabel::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Certificates',
        'normalized_name' => 'certificates',
    ]);
    $image = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'product.jpg',
    ]);
    $pdf = MediaAsset::factory()->pdf()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'coa.pdf',
    ]);
    $pdf->labels()->attach($certificate);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->set('typeFilter', MediaAssetType::Pdf->value)
        ->assertSee('coa.pdf')
        ->assertDontSee('product.jpg')
        ->set('typeFilter', 'all')
        ->set('labelFilter', [$certificate->id])
        ->assertSee('coa.pdf')
        ->assertDontSee('product.jpg')
        ->assertDontSeeHtml('data-media-card-labels')
        ->assertSeeHtml('data-media-pdf-placeholder');

    expect($image->labels)->toBeEmpty();
});

it('creates and assigns labels from the asset inspector', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->call('openAssetPanel', $asset->id, 'settings')
        ->set('newLabelName', '  COA  ')
        ->call('createLabel')
        ->assertHasNoErrors()
        ->assertSet('newLabelName', '');

    $label = MediaLabel::query()->where('workspace_id', $workspace->id)->sole();

    expect($label->name)->toBe('COA')
        ->and($asset->fresh()->labels->sole()->is($label))->toBeTrue();
});

it('assigns several labels from a persistent asset inspector popover', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $assignedLabel = MediaLabel::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Ingredients',
        'normalized_name' => 'ingredients',
    ]);
    $availableLabel = MediaLabel::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Certificates',
        'normalized_name' => 'certificates',
    ]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $asset->labels()->attach($assignedLabel);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->call('openAssetPanel', $asset->id, 'settings')
        ->assertSeeHtml('data-media-assigned-labels')
        ->assertSeeHtml('data-media-label-popover')
        ->assertSeeHtml('data-media-label-trigger')
        ->assertSeeHtml('data-media-label-options')
        ->assertSeeHtml('x-show="open"')
        ->assertSeeHtml('wire:click="assignLabel('.$availableLabel->id.')"')
        ->call('assignLabel', $availableLabel->id)
        ->assertSet('selectedLabelIds', [$assignedLabel->id, $availableLabel->id])
        ->call('removeSelectedLabel', $assignedLabel->id)
        ->assertSet('selectedLabelIds', [$availableLabel->id])
        ->assertHasNoErrors();

    expect($asset->fresh()->labels()->pluck('media_labels.id')->all())
        ->toBe([$availableLabel->id]);
});

it('renames an asset without changing its upload metadata or physical media filename', function () {
    Storage::fake('local');
    config()->set('media.asset_disk', 'local');
    config()->set('media-library.disk_name', 'local');
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'supplier-photo.jpg',
    ]);
    $media = $asset->addMedia(UploadedFile::fake()->image('source.webp'))
        ->usingFileName('opaque-storage-name.webp')
        ->toMediaCollection('master', 'local');

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->call('rename', $asset->id, 'Amber jar')
        ->assertHasNoErrors()
        ->assertDispatched(
            'app-notification',
            message: __('media_library.messages.renamed', ['name' => 'Amber jar']),
            type: 'success',
        );

    expect($asset->refresh())
        ->display_name->toBe('Amber jar')
        ->original_filename->toBe('supplier-photo.jpg')
        ->and($media->refresh()->file_name)->toBe('opaque-storage-name.webp');
});

it('initializes rename state once and submits it through the real form action', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'display_name' => 'Current library name',
        'original_filename' => 'supplier-photo.jpg',
    ]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->call('openAssetPanel', $asset->id, 'settings')
        ->assertSeeHtml('data-media-asset-panel')
        ->assertSeeHtml('data-media-display-name')
        ->call('beginRename', $asset->id)
        ->assertSet("displayNames.{$asset->id}", 'Current library name')
        ->assertDontSeeHtml('aria-describedby="display-name-error-'.$asset->id.'"')
        ->set("displayNames.{$asset->id}", 'In-progress edit')
        ->call('$refresh')
        ->assertSet("displayNames.{$asset->id}", 'In-progress edit')
        ->call('renameFromInput', $asset->id)
        ->assertHasNoErrors();

    expect($asset->refresh()->display_name)->toBe('In-progress edit');
});

it('shows rename validation errors beside the asset display name input', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'display_name' => 'Current library name',
    ]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->call('openAssetPanel', $asset->id, 'settings')
        ->call('beginRename', $asset->id)
        ->set("displayNames.{$asset->id}", '')
        ->call('renameFromInput', $asset->id)
        ->assertHasErrors("displayNames.{$asset->id}")
        ->assertSeeHtml('aria-invalid="true"')
        ->assertSeeHtml('aria-describedby="display-name-error-'.$asset->id.'"')
        ->assertSee('Enter a display name.');

    expect($asset->refresh()->display_name)->toBe('Current library name');
});

it('hides rename controls and forbids rename actions for workspace viewers', function () {
    [$owner, $workspace] = mediaLibraryWorkspace();
    $viewer = User::factory()->create([
        'email_verified_at' => now(),
        'active_workspace_id' => $workspace->id,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    MediaAsset::factory()->failed()->create(['workspace_id' => $workspace->id]);

    Livewire::actingAs($viewer)
        ->test(MediaLibraryIndex::class)
        ->assertDontSeeHtml('data-media-library-file-input')
        ->assertDontSee(__('media_library.upload'))
        ->assertDontSee(__('media_library.rename'))
        ->assertDontSee(__('media_library.crop.adjust'))
        ->assertDontSee(__('media_library.actions.retry'))
        ->assertDontSee(__('media_library.actions.remove'))
        ->call('rename', $asset->id, 'Forbidden name')
        ->assertForbidden();

    expect($asset->refresh()->display_name)->toBeNull();
});

it('shows update and retry controls but no remove controls to workspace editors', function () {
    [$owner, $workspace] = mediaLibraryWorkspace();
    $editor = User::factory()->create([
        'email_verified_at' => now(),
        'active_workspace_id' => $workspace->id,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $readyAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    MediaAsset::factory()->failed()->create(['workspace_id' => $workspace->id]);

    Livewire::actingAs($editor)
        ->test(MediaLibraryIndex::class)
        ->assertSeeHtml('data-media-library-file-input')
        ->assertSee(__('media_library.upload_selected'))
        ->assertSeeHtml('data-media-settings-trigger')
        ->call('openAssetPanel', $readyAsset->id, 'settings')
        ->assertSee(__('media_library.crop.adjust'))
        ->assertSee(__('media_library.actions.retry'))
        ->assertDontSeeHtml('wire:click="remove(');
});

it('shows a validation error instead of a server error when pending storage is unavailable', function () {
    Queue::fake();
    config()->set('media.asset_pending_disk', 'unconfigured-pending-disk');
    [$user] = mediaLibraryWorkspace();

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->set('upload', UploadedFile::fake()->image('product.png'))
        ->call('uploadAsset')
        ->assertHasErrors('upload');

    expect(MediaAsset::query()->count())->toBe(0);
});

it('does not authorize rename actions for users outside the workspace', function () {
    [$owner, $workspace] = mediaLibraryWorkspace();
    [$outsider] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);

    Livewire::actingAs($outsider)
        ->test(MediaLibraryIndex::class)
        ->call('rename', $asset->id, 'Forbidden name')
        ->assertNotFound();

    expect($asset->refresh()->display_name)->toBeNull();
});

it('renders a bounded number of queries for a full gallery page', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    MediaAsset::factory()->ready()->count(24)->create(['workspace_id' => $workspace->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($user)->test(MediaLibraryIndex::class);

    expect(count(DB::getQueryLog()))->toBeLessThan(20);

    DB::disableQueryLog();
});

it('searches assets by their display name while preserving original filename search', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'display_name' => 'Amber jar',
        'original_filename' => 'supplier-image.jpg',
    ]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->set('search', 'amber')
        ->assertSee('Amber jar')
        ->call('openAssetPanel', $asset->id)
        ->assertSee('supplier-image.jpg');
});

it('shows usage details for recipes, ingredients, and packaging items', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Honey soap']);
    $ingredient = Ingredient::factory()->create(['workspace_id' => $workspace->id, 'display_name' => 'Beeswax']);
    $packagingItem = new UserPackagingItem;
    $packagingItem->user_id = $user->id;
    $packagingItem->name = 'Amber jar';
    $packagingItem->unit_cost = 1;
    $packagingItem->currency = 'EUR';
    $packagingItem->save();

    MediaAssetUsage::factory()->create([
        'media_asset_id' => $asset->id,
        'usable_type' => Recipe::class,
        'usable_id' => $recipe->id,
        'role' => MediaAssetUsageRole::RecipeFeatured,
    ]);
    MediaAssetUsage::factory()->create([
        'media_asset_id' => $asset->id,
        'usable_type' => Ingredient::class,
        'usable_id' => $ingredient->id,
        'role' => MediaAssetUsageRole::IngredientMain,
    ]);
    MediaAssetUsage::factory()->create([
        'media_asset_id' => $asset->id,
        'usable_type' => UserPackagingItem::class,
        'usable_id' => $packagingItem->id,
        'role' => MediaAssetUsageRole::PackagingMain,
    ]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertSee('3 usages')
        ->assertDontSee('Honey soap')
        ->assertDontSee('Beeswax')
        ->assertDontSee('Amber jar')
        ->call('openAssetPanel', $asset->id, 'usage')
        ->assertSet('selectedAssetId', $asset->id)
        ->assertSet('assetPanelTab', 'usage')
        ->assertSee('Recipe featured')
        ->assertSee('Honey soap')
        ->assertSee('Ingredient main')
        ->assertSee('Beeswax')
        ->assertSee('Packaging main')
        ->assertSee('Amber jar');
});

it('shows recipe history references as one logical recipe usage', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $recipe = Recipe::factory()->create([
        'workspace_id' => $workspace->id,
        'owner_id' => $user->id,
        'name' => 'Savon curcuma',
    ]);
    $currentVersion = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'workspace_id' => $workspace->id,
        'owner_id' => $user->id,
        'version_number' => 2,
        'is_current' => true,
    ]);
    $historicalVersion = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'workspace_id' => $workspace->id,
        'owner_id' => $user->id,
        'version_number' => 1,
        'is_current' => false,
    ]);

    foreach ([$recipe, $currentVersion, $historicalVersion] as $usable) {
        MediaAssetUsage::factory()->create([
            'media_asset_id' => $asset->id,
            'usable_type' => $usable::class,
            'usable_id' => $usable->id,
            'role' => MediaAssetUsageRole::RecipeSop,
        ]);
    }

    $component = Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertSee('1 usage')
        ->assertDontSee('3 usages')
        ->call('openAssetPanel', $asset->id, 'usage')
        ->assertSee('Savon curcuma')
        ->assertDontSee('Deleted item');

    expect(substr_count($component->html(), 'Savon curcuma'))->toBe(1)
        ->and($asset->usages()->count())->toBe(3);
});

it('keeps gallery cards compact and renders the selected asset in an accessible side panel', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'display_name' => 'Lavender process',
        'original_filename' => 'IMG_4831.HEIC',
    ]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertSet('selectedAssetId', null)
        ->assertSeeHtml('data-media-card')
        ->assertSeeHtml('data-media-card-preview')
        ->assertSeeHtml('w-full aspect-square')
        ->assertSeeHtml('data-media-card-name-row')
        ->assertSeeHtml('data-media-card-meta-row')
        ->assertSeeHtml('items-center gap-3')
        ->assertSeeHtml('data-media-usage-link')
        ->assertSeeHtml('data-media-settings-trigger')
        ->assertDontSeeHtml('data-media-inline-details')
        ->assertDontSeeHtml('data-media-asset-panel')
        ->call('openAssetPanel', $asset->id, 'settings')
        ->assertSet('selectedAssetId', $asset->id)
        ->assertSet('assetPanelTab', 'settings')
        ->assertSeeHtml('data-media-asset-panel')
        ->assertSeeHtml('data-media-panel-header')
        ->assertSeeHtml('data-media-panel-section')
        ->assertSeeHtml('border-[var(--color-active)]')
        ->assertSeeHtml('text-[var(--color-danger-strong)]')
        ->assertSeeHtml('role="dialog"')
        ->assertSeeHtml('aria-modal="true"')
        ->assertSeeHtml('x-trap.inert.noscroll')
        ->assertSeeHtml('x-on:keydown.escape.window')
        ->assertSeeHtml('data-media-panel-scroll')
        ->assertSeeHtml('data-media-delete-action')
        ->assertSeeHtml('wire:loading.remove')
        ->assertSeeHtml('wire:loading.flex')
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('wire:target="remove('.$asset->id.')"')
        ->assertSee('Deleting…')
        ->assertSee('Lavender process')
        ->assertSee('IMG_4831.HEIC')
        ->call('closeAssetPanel')
        ->assertSet('selectedAssetId', null)
        ->assertDontSeeHtml('data-media-asset-panel');
});

it('does not disclose media panel details outside the active workspace', function () {
    [$owner, $workspace] = mediaLibraryWorkspace();
    [$outsider] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'display_name' => 'Private workspace image',
    ]);

    Livewire::actingAs($outsider)
        ->test(MediaLibraryIndex::class)
        ->call('openAssetPanel', $asset->id, 'usage')
        ->assertNotFound()
        ->assertDontSee('Private workspace image');
});

it('disables uploads at the media asset quota while retaining existing assets', function () {
    [$user, $workspace] = mediaLibraryWorkspace(limit: 1);
    MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertSee('1 of 1 media assets used')
        ->assertSee('Existing assets remain available')
        ->assertSeeHtml('data-media-upload-disabled');
});

it('shows progress while the selected image is temporarily uploading', function () {
    [$user] = mediaLibraryWorkspace();

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertSeeHtml('x-data="mediaLibraryUploader(')
        ->assertSeeHtml('data-media-library-file-input')
        ->assertSeeHtml('multiple')
        ->assertSeeHtml('data-media-library-selected-files')
        ->assertSeeHtml('data-media-library-selected-filename')
        ->assertSeeHtml('data-media-library-remove-file')
        ->assertSeeHtml('data-media-library-batch-limit')
        ->assertSeeHtml('data-media-library-batch-progress')
        ->assertSeeHtml('x-bind:disabled="! canUpload"')
        ->assertSeeHtml('lg:items-start')
        ->assertSeeHtml('text-xs font-medium leading-5')
        ->assertSeeHtml('bg-[var(--color-accent)]')
        ->assertDontSeeHtml('<progress')
        ->assertSeeHtml('role="status"')
        ->assertSeeHtml('animate-spin');
});

it('renders a dense responsive thumbnail grid', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertSeeHtml('data-media-gallery-section')
        ->assertSeeHtml('data-media-filter-toolbar')
        ->assertSeeHtml('data-media-gallery-grid')
        ->assertSeeHtml('grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6')
        ->assertSeeHtml('gap-4 sm:gap-5')
        ->assertDontSeeHtml('gap-4 p-4 sm:gap-5 sm:p-6');
});

it('polls only while the workspace has processing assets', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $processing = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertSeeHtml('wire:poll.5s.visible');

    $processing->update(['status' => MediaAssetStatus::Ready]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertDontSeeHtml('wire:poll');
});

it('exposes usage filters as an accessible pressed-button group', function () {
    [$user] = mediaLibraryWorkspace();

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertSeeHtml('data-media-filter-toolbar')
        ->assertSeeHtml('data-media-usage-filter')
        ->assertSeeHtml('aria-label="Usage"')
        ->assertSee('Any usage')
        ->assertSeeHtml('data-media-status-filter')
        ->assertSeeHtml('data-media-type-filter')
        ->assertSeeHtml('aria-pressed="true"')
        ->set('usageFilter', 'used')
        ->assertSeeHtml('aria-pressed="true"');
});

it('shows processing progress and failed retry and remove actions', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    MediaAsset::factory()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'Processing.jpg',
        'progress' => 45,
        'processing_stage' => 'converting',
    ]);
    MediaAsset::factory()->failed()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'Failed.heic',
        'failure_reason' => 'HEIC is unavailable.',
    ]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->assertSee('Processing.jpg')
        ->assertSee('45%')
        ->assertSee('Failed.heic')
        ->assertSee('HEIC is unavailable.')
        ->assertSee('Retry')
        ->assertSee('Remove');
});

it('deletes an in-use asset everywhere with one explicit confirmation', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $retainedAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $targetIdentity = "media-asset:{$asset->public_id}";
    $retainedIdentity = "media-asset:{$retainedAsset->public_id}";
    $instructions = <<<HTML
    <p>Mix thoroughly.</p>
    <img data-id="{$targetIdentity}" src="/target.webp">
    <img data-id="{$retainedIdentity}" src="/retained.webp">
    HTML;
    $recipe = Recipe::factory()->create([
        'workspace_id' => $workspace->id,
        'manufacturing_instructions' => $instructions,
    ]);
    $versions = collect(range(1, 10))->map(
        fn (int $versionNumber): RecipeVersion => RecipeVersion::factory()->create([
            'recipe_id' => $recipe->id,
            'workspace_id' => $workspace->id,
            'manufacturing_instructions' => $instructions,
            'is_current' => $versionNumber === 10,
            'version_number' => $versionNumber,
        ]),
    );
    $ingredient = Ingredient::factory()->create(['workspace_id' => $workspace->id]);
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Amber jar',
        'unit_cost' => 1,
        'currency' => 'EUR',
    ]);

    $usages = collect([
        [$recipe, MediaAssetUsageRole::RecipeFeatured],
        [$recipe, MediaAssetUsageRole::RecipeSop],
        [$ingredient, MediaAssetUsageRole::IngredientMain],
        [$packagingItem, MediaAssetUsageRole::PackagingMain],
    ])->merge(
        $versions->map(
            fn (RecipeVersion $version): array => [$version, MediaAssetUsageRole::RecipeSop],
        ),
    );

    foreach ($usages as [$usable, $role]) {
        MediaAssetUsage::factory()->create([
            'media_asset_id' => $asset->id,
            'usable_type' => $usable::class,
            'usable_id' => $usable->id,
            'role' => $role,
        ]);
    }

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->call('openAssetPanel', $asset->id, 'settings')
        ->assertSee('Used in 1 recipe and 2 other items.')
        ->assertSee('Deleting removes it everywhere, including recipe history.')
        ->call('remove', $asset->id)
        ->assertSet('selectedAssetId', null);

    expect($asset->fresh())->toBeNull()
        ->and($retainedAsset->fresh())->not->toBeNull()
        ->and(MediaAssetUsage::query()->where('media_asset_id', $asset->id)->exists())->toBeFalse()
        ->and($recipe->fresh()->manufacturing_instructions)->not->toContain($targetIdentity)
        ->and($recipe->fresh()->manufacturing_instructions)->toContain($retainedIdentity)
        ->and($versions->every(
            fn (RecipeVersion $version): bool => ! str_contains(
                $version->fresh()->manufacturing_instructions,
                $targetIdentity,
            ),
        ))->toBeTrue()
        ->and($versions->every(
            fn (RecipeVersion $version): bool => str_contains(
                $version->fresh()->manufacturing_instructions,
                $retainedIdentity,
            ),
        ))->toBeTrue();
});

it('treats a repeated confirmed panel deletion as already completed', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $stalePanel = new MediaLibraryIndex;
    $stalePanel->selectedAssetId = $asset->id;

    $asset->delete();

    $stalePanel->remove(
        $asset->id,
        app(CurrentAppUserResolver::class),
        app(MediaAssetLibraryService::class),
    );

    expect($stalePanel->selectedAssetId)->toBeNull();
});

it('updates the focal point and queues square conversion regeneration', function () {
    Queue::fake();
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'focal_x' => 50,
        'focal_y' => 50,
    ]);

    Livewire::actingAs($user)
        ->test(MediaLibraryIndex::class)
        ->call('openAssetPanel', $asset->id, 'settings')
        ->assertSee(__('media_library.crop.adjust'))
        ->call('updateFocalPoint', $asset->id, 35, 70)
        ->assertHasNoErrors()
        ->assertDispatched(
            'app-notification',
            message: __('media_library.messages.focal_refreshing'),
            type: 'success',
        );

    expect($asset->fresh())
        ->focal_x->toBe(35.0)
        ->focal_y->toBe(70.0);

    Queue::assertPushedOn('media', RegenerateMediaAssetConversionsJob::class);
});

it('serves only allowlisted conversions to authorized workspace members', function () {
    Storage::fake('local');
    config()->set('media.asset_disk', 'local');
    config()->set('media-library.disk_name', 'local');
    config()->set('media-library.conversions_disk_name', 'local');
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $media = $asset->addMedia(UploadedFile::fake()->image('master.webp'))
        ->usingFileName('opaque.webp')
        ->toMediaCollection('master', 'local');
    $outsider = User::factory()->create();

    $this->actingAs($user)
        ->get(route('media.show', [$asset, 'master']))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs($user)
        ->get(route('media.show', [$asset, 'not-allowed']))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->get(route('media.show', [$asset, 'master']))
        ->assertNotFound();
});

it('streams private remote media through the authorized application route', function () {
    Storage::fake('r2_private');
    config()->set('filesystems.disks.r2_private.driver', 's3');
    config()->set('media.asset_disk', 'r2_private');
    config()->set('media-library.disk_name', 'r2_private');
    config()->set('media-library.conversions_disk_name', 'r2_private');
    Storage::disk('r2_private')->buildTemporaryUrlsUsing(
        fn (string $path): string => 'https://private-media.example.test/'.$path,
    );

    [$owner, $workspace] = mediaLibraryWorkspace();
    $viewer = User::factory()->create([
        'email_verified_at' => now(),
        'active_workspace_id' => $workspace->id,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $asset->addMedia(UploadedFile::fake()->image('master.webp'))
        ->usingFileName('opaque.webp')
        ->toMediaCollection('master', 'r2_private');
    $media = $asset->getFirstMedia('master');
    $expectedThumbnail = Storage::disk('r2_private')->get(
        $media->getPathRelativeToRoot('thumbnail'),
    );

    $this->actingAs($viewer)
        ->get(route('media.show', [$asset, 'thumbnail']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Location')
        ->assertStreamedContent($expectedThumbnail);
});

it('reports processing status only to authorized workspace members', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    $asset = MediaAsset::factory()->create([
        'workspace_id' => $workspace->id,
        'progress' => 45,
        'processing_stage' => 'converting',
    ]);
    $outsider = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('media.status', $asset))
        ->assertOk()
        ->assertExactJson([
            'status' => MediaAssetStatus::Processing->value,
            'progress' => 45,
            'failure_reason' => null,
            'retry_url' => null,
            'remove_url' => null,
        ]);

    $this->actingAs($outsider)
        ->getJson(route('media.status', $asset))
        ->assertNotFound();
});

it('exposes picker lifecycle action urls only for permitted failed assets', function () {
    [$owner, $workspace] = mediaLibraryWorkspace();
    $editor = User::factory()->create([
        'email_verified_at' => now(),
        'active_workspace_id' => $workspace->id,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $viewer = User::factory()->create([
        'email_verified_at' => now(),
        'active_workspace_id' => $workspace->id,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $failed = MediaAsset::factory()->failed()->create(['workspace_id' => $workspace->id]);
    $ready = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($owner)
        ->getJson(route('media.status', $failed))
        ->assertJsonPath('retry_url', route('media.retry', $failed))
        ->assertJsonPath('remove_url', route('media.remove', $failed));

    $this->actingAs($editor)
        ->getJson(route('media.status', $failed))
        ->assertJsonPath('retry_url', route('media.retry', $failed))
        ->assertJsonPath('remove_url', null);

    $this->actingAs($viewer)
        ->getJson(route('media.status', $failed))
        ->assertJsonPath('retry_url', null)
        ->assertJsonPath('remove_url', null);

    $this->actingAs($owner)
        ->getJson(route('media.status', $ready))
        ->assertJsonPath('retry_url', null)
        ->assertJsonPath('remove_url', null);
});

it('retries and removes failed picker uploads through workspace-scoped endpoints', function () {
    Queue::fake();
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    [$owner, $workspace] = mediaLibraryWorkspace();
    $retryable = MediaAsset::factory()->failed()->create([
        'workspace_id' => $workspace->id,
        'pending_disk' => 'local',
        'pending_path' => 'media-assets/pending/retryable.jpg',
    ]);
    Storage::disk('local')->put($retryable->pending_path, 'pending');
    $removable = MediaAsset::factory()->failed()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($owner)
        ->postJson(route('media.retry', $retryable))
        ->assertOk()
        ->assertJsonPath('status', MediaAssetStatus::Processing->value);

    expect($retryable->refresh()->status)->toBe(MediaAssetStatus::Processing);

    $this->actingAs($owner)
        ->deleteJson(route('media.remove', $removable))
        ->assertOk()
        ->assertJsonPath('removed', true);

    expect($removable->fresh())->toBeNull();
});

it('does not disclose or mutate picker uploads outside the active workspace', function () {
    [$owner, $workspace] = mediaLibraryWorkspace();
    [$outsider] = mediaLibraryWorkspace();
    $failed = MediaAsset::factory()->failed()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($outsider)
        ->postJson(route('media.retry', $failed))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->deleteJson(route('media.remove', $failed))
        ->assertNotFound();

    expect($failed->fresh())->not->toBeNull();
});

it('enforces picker retry and remove permissions by workspace role', function () {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    [$owner, $workspace] = mediaLibraryWorkspace();
    $editor = User::factory()->create([
        'email_verified_at' => now(),
        'active_workspace_id' => $workspace->id,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $viewer = User::factory()->create([
        'email_verified_at' => now(),
        'active_workspace_id' => $workspace->id,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $failed = MediaAsset::factory()->failed()->create([
        'workspace_id' => $workspace->id,
        'pending_disk' => 'local',
        'pending_path' => 'media-assets/pending/role-check.jpg',
    ]);
    Storage::disk('local')->put($failed->pending_path, 'pending');

    $this->actingAs($viewer)
        ->postJson(route('media.retry', $failed))
        ->assertForbidden();

    $this->actingAs($editor)
        ->deleteJson(route('media.remove', $failed))
        ->assertForbidden();

    expect($failed->fresh())->not->toBeNull();
});

it('keeps a failed picker upload failed when retry cannot reserve quota', function () {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    [$owner, $workspace] = mediaLibraryWorkspace(limit: 1);
    MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $failed = MediaAsset::factory()->failed()->create([
        'workspace_id' => $workspace->id,
        'pending_disk' => 'local',
        'pending_path' => 'media-assets/pending/quota-retry.jpg',
    ]);
    Storage::disk('local')->put($failed->pending_path, 'pending');

    $this->actingAs($owner)
        ->postJson(route('media.retry', $failed))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plan')
        ->assertJsonPath('errors.plan.0', 'Your current plan allows 1 media assets.');

    expect($failed->refresh()->status)->toBe(MediaAssetStatus::Failed);
});

it('provides localized media picker strings in every supported locale', function (string $locale, array $expected) {
    app()->setLocale($locale);

    expect(__('media_library.picker.library'))->not->toBe('Library')
        ->and(__('media_library.picker.upload_new'))->not->toBe('Upload new')
        ->and(__('media_library.picker.retry'))->not->toBe('Retry')
        ->and(__('media_library.picker.search_label'))->not->toBe('Search media assets')
        ->and(__('media_library.choose_files'))->toBe($expected['choose_files'])
        ->and(__('media_library.upload_selected'))->toBe($expected['upload_selected'])
        ->and(__('media_library.batch_position', ['current' => 2, 'total' => 5]))->toBe($expected['batch_position'])
        ->and(__('media_library.picker.choose_file'))->toBe($expected['choose_file'])
        ->and(__('media_library.picker.no_file_selected'))->toBe($expected['no_file_selected']);
})->with([
    'de' => ['de', [
        'choose_files' => 'Bilder auswählen',
        'upload_selected' => 'Ausgewählte Bilder hochladen',
        'batch_position' => 'Bild 2 von 5 wird hochgeladen',
        'choose_file' => 'Bild auswählen',
        'no_file_selected' => 'Kein Bild ausgewählt',
    ]],
    'es' => ['es', [
        'choose_files' => 'Elegir imágenes',
        'upload_selected' => 'Subir imágenes seleccionadas',
        'batch_position' => 'Subiendo 2 de 5',
        'choose_file' => 'Elegir imagen',
        'no_file_selected' => 'Ninguna imagen seleccionada',
    ]],
    'fr' => ['fr', [
        'choose_files' => 'Choisir des images',
        'upload_selected' => 'Importer les images sélectionnées',
        'batch_position' => 'Importation de 2 sur 5',
        'choose_file' => 'Choisir une image',
        'no_file_selected' => 'Aucune image sélectionnée',
    ]],
    'it' => ['it', [
        'choose_files' => 'Scegli immagini',
        'upload_selected' => 'Carica le immagini selezionate',
        'batch_position' => 'Caricamento 2 di 5',
        'choose_file' => 'Scegli immagine',
        'no_file_selected' => 'Nessuna immagine selezionata',
    ]],
    'nl' => ['nl', [
        'choose_files' => 'Afbeeldingen kiezen',
        'upload_selected' => 'Geselecteerde afbeeldingen uploaden',
        'batch_position' => '2 van 5 uploaden',
        'choose_file' => 'Afbeelding kiezen',
        'no_file_selected' => 'Geen afbeelding geselecteerd',
    ]],
]);

it('paginates and searches picker assets without disclosing other workspaces', function () {
    [$user, $workspace] = mediaLibraryWorkspace();
    MediaAsset::factory()->ready()->count(50)->create(['workspace_id' => $workspace->id]);
    $match = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id, 'display_name' => 'Amber search target', 'original_filename' => 'supplier.jpg']);
    MediaAsset::factory()->ready()->create(['display_name' => 'Other workspace target']);

    $this->actingAs($user)->getJson(route('media.picker-assets'))
        ->assertOk()->assertJsonCount(48, 'data')->assertJsonPath('has_more', true)
        ->assertJsonMissing(['display_name' => 'Other workspace target']);

    $this->actingAs($user)->getJson(route('media.picker-assets', ['search' => 'supplier']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id)
        ->assertJsonPath('data.0.master_url', route('media.show', [$match, 'master']))
        ->assertJsonStructure(['data' => [['id', 'display_name', 'original_filename', 'status', 'progress', 'thumbnail_url', 'master_url']], 'has_more', 'next_page']);
});

/**
 * @return array{User, Workspace}
 */
function mediaLibraryWorkspace(?int $limit = null): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);

    if ($limit !== null) {
        $plan = Plan::factory()
            ->hasLimit('media_assets', $limit)
            ->create(['is_default' => true]);

        $user->entitlements()->create([
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);
    }

    return [$user, $workspace];
}
