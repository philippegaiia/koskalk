<?php

use App\Enums\MediaAssetStatus;
use App\Enums\MediaAssetType;
use App\Enums\MediaAssetUsageRole;
use App\Forms\Components\MediaAssetPicker;
use App\Forms\RichEditor\Plugins\MediaLibraryRichContentPlugin;
use App\Jobs\NormalizeMediaAssetJob;
use App\Livewire\Dashboard\IngredientEditor;
use App\Livewire\Dashboard\IngredientsIndex;
use App\Livewire\Dashboard\PackagingItemEditor;
use App\Livewire\Dashboard\PackagingItemsIndex;
use App\Livewire\Dashboard\RecipesIndex;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MediaAssetUsageService;
use App\Services\PackagingItemAuthoringService;
use App\Services\RecipeSopSnapshotService;
use App\Services\UserIngredientAuthoringService;
use Filament\Forms\Components\RichEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('uses the shared media picker instead of record-owned image uploads', function () {
    $user = User::factory()->create();
    Workspace::factory()->create(['owner_user_id' => $user->id]);
    $productFamily = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create([
        'owner_id' => $user->id,
        'product_family_id' => $productFamily->id,
    ]);

    $this->actingAs($user);

    $ingredientForm = Livewire::test(IngredientEditor::class)->instance()->form;
    $packagingForm = Livewire::test(PackagingItemEditor::class)->instance()->form;
    $recipeForm = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])->instance()->form;

    expect($ingredientForm->getComponent('featured_media_asset_id'))
        ->toBeInstanceOf(MediaAssetPicker::class)
        ->and($ingredientForm->getComponent('icon_media_asset_id'))
        ->toBeInstanceOf(MediaAssetPicker::class)
        ->and($packagingForm->getComponent('featured_media_asset_id'))
        ->toBeInstanceOf(MediaAssetPicker::class)
        ->and($recipeForm->getComponent('featured_media_asset_id'))
        ->toBeInstanceOf(MediaAssetPicker::class)
        ->and($ingredientForm->getComponent('document_media_asset_ids'))
        ->toBeInstanceOf(MediaAssetPicker::class)
        ->and($ingredientForm->getComponent('document_media_asset_ids')->getAcceptedMediaAssetTypeValues())
        ->toBe([MediaAssetType::Pdf->value])
        ->and($recipeForm->getComponent('sop_document_media_asset_ids'))
        ->toBeInstanceOf(MediaAssetPicker::class)
        ->and($recipeForm->getComponent('sop_document_media_asset_ids')->getMaximumItems())
        ->toBe(8)
        ->and($recipeForm->getComponent('featured_media_asset_id')->shouldPreserveAspectRatio())
        ->toBeFalse()
        ->and($recipeForm->getComponent('featured_media_asset_id')->isLive())
        ->toBeTrue()
        ->and($recipeForm->getComponent('manufacturing_media_asset_ids'))
        ->toBeNull()
        ->and($ingredientForm->getComponent('featured_image_path'))
        ->toBeNull()
        ->and($packagingForm->getComponent('featured_image_path'))
        ->toBeNull()
        ->and($recipeForm->getComponent('featured_image_path'))
        ->toBeNull();

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->assertSee('Upload image')
        ->assertSee('Choose from Media Library')
        ->assertSeeHtml('data-media-picker-upload-form');

    Livewire::test(IngredientEditor::class)
        ->assertSeeText('Choose documents')
        ->assertSeeText('No library documents selected.')
        ->assertSeeText('Select up to 8 ready PDF documents.')
        ->assertSeeText('Upload a PDF document to the library, then return here to select it.')
        ->assertSeeText('Upload PDF')
        ->assertSeeText('Choose PDF')
        ->assertSeeHtml('No PDF selected')
        ->assertSeeText('Processing uploaded PDF')
        ->assertSeeText('PDF processing failed');
});

it('filters picker results by the component media type', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'product.jpg',
    ]);
    MediaAsset::factory()->pdf()->ready()->create([
        'workspace_id' => $workspace->id,
        'original_filename' => 'certificate.pdf',
    ]);

    $this->actingAs($user)
        ->getJson(route('media.picker-assets', ['types' => 'pdf']))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.original_filename', 'certificate.pdf')
        ->assertJsonPath('data.0.type', 'pdf');

    $this->actingAs($user)
        ->getJson(route('media.picker-assets', ['types' => 'image']))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.original_filename', 'product.jpg');
});

it('enforces media types and limits for ingredient and sop document roles', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $ingredient = Ingredient::factory()->create(['workspace_id' => $workspace->id]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $documents = MediaAsset::factory()->pdf()->ready()->count(8)->create(['workspace_id' => $workspace->id]);
    $image = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $service = app(MediaAssetUsageService::class);

    $service->syncMany(
        $user,
        $ingredient,
        MediaAssetUsageRole::IngredientDocument,
        $documents->pluck('id')->all(),
        maximum: 8,
        expectedType: MediaAssetType::Pdf,
    );
    $service->syncMany(
        $user,
        $recipe,
        MediaAssetUsageRole::RecipeSopDocument,
        [$documents->first()->id],
        maximum: 8,
        expectedType: MediaAssetType::Pdf,
    );

    expect($service->idsFor($ingredient, MediaAssetUsageRole::IngredientDocument))
        ->toHaveCount(8)
        ->and($service->idsFor($recipe, MediaAssetUsageRole::RecipeSopDocument))
        ->toBe([$documents->first()->id]);

    expect(fn () => $service->syncMany(
        $user,
        $ingredient,
        MediaAssetUsageRole::IngredientDocument,
        [$image->id],
        maximum: 8,
        expectedType: MediaAssetType::Pdf,
    ))->toThrow(ValidationException::class);

    expect(fn () => $service->syncMany(
        $user,
        $recipe,
        MediaAssetUsageRole::RecipeSopDocument,
        [...$documents->pluck('id')->all(), $image->id],
        maximum: 8,
        expectedType: MediaAssetType::Pdf,
    ))->toThrow(ValidationException::class, '8 PDF documents');
});

it('keeps product descriptions text-only and removes direct rich editor uploads', function () {
    $user = User::factory()->create();
    Workspace::factory()->create(['owner_user_id' => $user->id]);
    $productFamily = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create([
        'owner_id' => $user->id,
        'product_family_id' => $productFamily->id,
    ]);
    $this->actingAs($user);

    $form = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])->instance()->form;
    $description = $form->getComponent('description');
    $instructions = $form->getComponent('manufacturing_instructions');

    expect($description)->toBeInstanceOf(RichEditor::class)
        ->and($instructions)->toBeInstanceOf(RichEditor::class)
        ->and($description->hasResizableImages())->toBeFalse()
        ->and($instructions->hasResizableImages())->toBeTrue()
        ->and(collect($description->getToolbarButtons())->flatten()->all())
        ->not->toContain('attachFiles')
        ->and(collect($instructions->getToolbarButtons())->flatten()->all())
        ->not->toContain('attachFiles')
        ->toContain('insertFromMediaLibrary');
});

it('provides one embedded Media Library action that inserts a stable uncropped image node', function () {
    $plugin = MediaLibraryRichContentPlugin::make();
    $tool = $plugin->getEditorTools()[0] ?? null;
    $action = $plugin->getEditorActions()[0] ?? null;

    expect($tool?->getName())->toBe('insertFromMediaLibrary')
        ->and($tool?->getLabel())->toBe('Insert from Media Library')
        ->and($action?->getName())->toBe('insertFromMediaLibrary')
        ->and($action?->getLabel())->toBe('Insert from Media Library');

    $pluginSource = file_get_contents(app_path('Forms/RichEditor/Plugins/MediaLibraryRichContentPlugin.php'));

    expect($pluginSource)
        ->toContain("EditorCommand::make('insertContent'")
        ->toContain("MediaAssetPicker::make('media_asset_id')")
        ->toContain('->embedded()')
        ->toContain("'id' => MediaLibraryRichContentPlugin::identityFor(\$asset)")
        ->toContain("route('media.show', [\$asset, 'master'])")
        ->not->toContain("'alt' => \$asset->")
        ->not->toContain("'caption'");
});

it('renders the embedded picker inside the editor action without a second dialog', function () {
    $pickerView = file_get_contents(resource_path('views/forms/components/media-asset-picker.blade.php'));

    expect($pickerView)
        ->toContain('data-media-picker-embedded')
        ->toContain('@if ($isEmbedded())')
        ->toContain('@else')
        ->and(substr_count($pickerView, 'role="dialog"'))->toBe(1)
        ->and(substr_count($pickerView, 'aria-modal="true"'))->toBe(1)
        ->and($pickerView)->toContain('draggable="false"');
});

it('mounts the embedded picker action and inserts at the saved editor selection', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $recipe = Recipe::factory()->create([
        'workspace_id' => $workspace->id,
        'owner_id' => $user->id,
    ]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $selection = [
        'type' => 'text',
        'anchor' => 3,
        'head' => 3,
    ];
    $component = Livewire::actingAs($user)
        ->test(RecipeWorkbench::class, ['recipe' => $recipe]);
    $editorKey = $component->instance()->form
        ->getComponent('manufacturing_instructions')
        ->getKey();

    $component
        ->call('mountAction', 'insertFromMediaLibrary', [
            'editorSelection' => $selection,
        ], [
            'schemaComponent' => $editorKey,
        ])
        ->assertMountedActionModalSeeHtml('data-media-picker-embedded')
        ->fillForm([
            'media_asset_id' => $asset->id,
        ])
        ->callMountedAction()
        ->assertDispatched('run-rich-editor-commands', function (string $name, array $parameters) use ($asset, $selection): bool {
            $image = $parameters['commands'][0]['arguments'][0] ?? [];

            return $name === 'run-rich-editor-commands'
                && ($parameters['editorSelection'] ?? null) === $selection
                && ($image['type'] ?? null) === 'image'
                && ($image['attrs']['id'] ?? null) === MediaLibraryRichContentPlugin::identityFor($asset)
                && ($image['attrs']['src'] ?? null) === route('media.show', [$asset, 'master']);
        });
});

it('uploads from a picker with Livewire into the shared library and queues processing', function () {
    Queue::fake();
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);

    $component = Livewire::actingAs($user)
        ->test(IngredientEditor::class);

    expect($component->instance()->isFileUploadForSchemaComponent(
        'componentFileAttachments.data.featured_media_asset_id.mediaPickerUpload',
    ))->toBeTrue()
        ->and($component->instance()->isFileUploadForSchemaComponent(
            'componentFileAttachments.data.not_a_picker.mediaPickerUpload',
        ))->toBeFalse();

    $component
        ->set(
            'componentFileAttachments.data.featured_media_asset_id.mediaPickerUpload',
            UploadedFile::fake()->image('Reusable soap.jpg'),
        )
        ->call('startMediaAssetPickerUpload', 'data.featured_media_asset_id')
        ->assertHasNoErrors();

    $asset = MediaAsset::query()->sole();

    expect($asset->workspace_id)->toBe($workspace->id)
        ->and($asset->original_filename)->toBe('Reusable soap.jpg')
        ->and($asset->status)->toBe(MediaAssetStatus::Processing);

    Queue::assertPushedOn('media', NormalizeMediaAssetJob::class);
});

it('returns a clear picker error when pending storage is unavailable', function () {
    Queue::fake();
    config()->set('media.asset_pending_disk', 'unconfigured-pending-disk');

    $user = User::factory()->create();
    Workspace::factory()->create(['owner_user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(IngredientEditor::class)
        ->set(
            'componentFileAttachments.data.featured_media_asset_id.mediaPickerUpload',
            UploadedFile::fake()->image('Unavailable storage.jpg'),
        )
        ->call('startMediaAssetPickerUpload', 'data.featured_media_asset_id')
        ->assertReturned(fn (array $response): bool => ($response['error'] ?? null) === __('media_library.validation.upload_store_failed'));

    expect(MediaAsset::query()->count())->toBe(0);
});

it('renders a lazy paginated picker library contract without eager asset cards', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    MediaAsset::factory()->ready()->count(100)->create(['workspace_id' => $workspace->id]);

    Livewire::actingAs($user)
        ->test(IngredientEditor::class)
        ->assertSee('Library')
        ->assertSee('Upload new')
        ->assertSeeHtml('role="tablist"')
        ->assertSeeHtml('data-media-picker-library-tab')
        ->assertSeeHtml('aria-controls="media-picker-')
        ->assertSeeHtml('aria-labelledby="media-picker-')
        ->assertSeeHtml("x-bind:tabindex=\"activeTab === 'library' ? 0 : -1\"")
        ->assertSeeHtml('x-on:keydown.arrow-right.prevent="moveTabFocus(1)"')
        ->assertSeeHtml("x-on:keydown.home.prevent=\"focusTab('library')\"")
        ->assertSeeHtml('for="media-picker-')
        ->assertSeeHtml('x-model="search"')
        ->assertSeeHtml('data-media-picker-assets-url')
        ->assertSeeHtml('data-media-picker-upload-form')
        ->assertSeeHtml('data-media-picker-file-input')
        ->assertSeeHtml('data-media-picker-file-trigger')
        ->assertSeeHtml('x-on:change="selectUploadFile($event)"')
        ->assertSeeHtml('x-on:click="uploadNew()"')
        ->assertSeeHtml('x-for="asset in assets"')
        ->assertSeeHtml('loadMoreAssets()')
        ->assertDontSeeHtml('data-media-picker-file-input multiple')
        ->assertDontSeeHtml('data-media-picker-server-asset');
});

it('does not render a nested form inside consumer save forms', function () {
    $pickerView = file_get_contents(resource_path('views/forms/components/media-asset-picker.blade.php'));

    expect($pickerView)
        ->not->toContain('<form')
        ->not->toContain('</form>')
        ->toContain('data-media-picker-upload-form')
        ->toContain('x-on:click="uploadNew()"');
});

it('does not let the picker upload input block consumer form submission', function () {
    $user = User::factory()->create();
    Workspace::factory()->create(['owner_user_id' => $user->id]);
    $pickerView = file_get_contents(resource_path('views/forms/components/media-asset-picker.blade.php'));
    $packagingHtml = Livewire::actingAs($user)
        ->test(PackagingItemEditor::class)
        ->html();

    expect($pickerView)
        ->toMatch('/x-ref="uploadInput"/')
        ->not->toMatch('/x-ref="uploadInput"[^>]*\srequired(?:\s|>)/')
        ->and(requiredFileInputCountInOwningForm($packagingHtml, '//button[@type="submit"]'))
        ->toBe(0);
});

it('keeps recipe autosave and packaging save controls inside their owning forms', function () {
    $user = User::factory()->create();
    Workspace::factory()->create(['owner_user_id' => $user->id]);
    $productFamily = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create([
        'owner_id' => $user->id,
        'product_family_id' => $productFamily->id,
    ]);

    $recipeHtml = Livewire::actingAs($user)
        ->test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->html();
    $packagingHtml = Livewire::actingAs($user)
        ->test(PackagingItemEditor::class)
        ->html();
    $ingredientHtml = Livewire::actingAs($user)
        ->test(IngredientEditor::class)
        ->html();

    expect(editorControlOwningForm($recipeHtml, '//*[@data-instructions-save-bar]'))
        ->toContain('recipeContentAutosave(')
        ->and(editorControlOwningForm($packagingHtml, '//button[@type="submit"]'))
        ->toBe('save')
        ->and(editorControlOwningForm($ingredientHtml, '//*[@data-ingredient-save-bar]//button[@type="submit"]'))
        ->toBe('save');
});

it('renders explicit picker lifecycle and status contracts', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id, 'original_filename' => 'ready.jpg']);
    MediaAsset::factory()->create(['workspace_id' => $workspace->id, 'original_filename' => 'processing.jpg']);
    MediaAsset::factory()->failed()->create(['workspace_id' => $workspace->id, 'original_filename' => 'failed.jpg']);

    $component = Livewire::actingAs($user)
        ->test(IngredientEditor::class)
        ->assertSeeHtml('x-data="mediaAssetPicker({')
        ->assertSeeHtml('x-bind:data-media-picker-status="asset.status"')
        ->assertSeeHtml("x-bind:data-media-picker-selectable=\"asset.status === 'ready'")
        ->assertSeeHtml("x-bind:disabled=\"asset.status !== 'ready'\"")
        ->assertSeeHtml('x-bind:class="selected(asset.id)')
        ->assertSeeHtml('data-media-picker-pending-status')
        ->assertSeeHtml('data-media-picker-retry')
        ->assertSeeHtml('data-media-picker-remove')
        ->assertSeeHtml('x-trap.inert.noscroll="open"')
        ->assertSeeHtml('openPicker()')
        ->assertSeeHtml('closePicker()')
        ->assertSeeHtml('data-media-picker-upload-form')
        ->assertSeeHtml('x-on:click="uploadNew()"')
        ->assertSeeHtml('data-media-picker-assets-error')
        ->assertSeeHtml('role="alert"')
        ->assertSeeHtml('retryAssets()');

    $controllerSource = file_get_contents(resource_path('js/media-asset-picker.js'));

    expect($controllerSource)
        ->toContain('this.open = true;')
        ->toContain('this.activeTab = \'library\';')
        ->toContain('this.pollUpload();')
        ->toContain('this.select(this.pendingUpload.id, false);')
        ->toContain('destroy()')
        ->toContain('window.clearTimeout(this.pollTimer);')
        ->toContain('[401, 403, 404, 419].includes(error.status)')
        ->toContain('this.pollFailures >= 3')
        ->toContain('if (closeSingle && !this.embedded)')
        ->toContain('assetsGeneration: 0')
        ->toContain('const generation = reset ? ++this.assetsGeneration : this.assetsGeneration;')
        ->toContain('generation !== this.assetsGeneration')
        ->toContain('assetsError: null')
        ->toContain('this.livewire.upload(')
        ->toContain('event.detail.progress')
        ->not->toContain('fetch(this.uploadUrl');
});

function editorControlOwningForm(string $html, string $controlQuery): ?string
{
    $previousLibxmlSetting = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlSetting);

    $xpath = new DOMXPath($document);
    $control = $xpath->query($controlQuery)->item(0);

    if (! $control instanceof DOMElement) {
        return null;
    }

    $form = $xpath->query('ancestor::form[1]', $control)->item(0);

    if (! $form instanceof DOMElement) {
        return null;
    }

    return $form->getAttribute('x-data') ?: $form->getAttribute('wire:submit');
}

function requiredFileInputCountInOwningForm(string $html, string $controlQuery): ?int
{
    $previousLibxmlSetting = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlSetting);

    $xpath = new DOMXPath($document);
    $control = $xpath->query($controlQuery)->item(0);

    if (! $control instanceof DOMElement) {
        return null;
    }

    $form = $xpath->query('ancestor::form[1]', $control)->item(0);

    if (! $form instanceof DOMElement) {
        return null;
    }

    return $xpath->query('.//input[@type="file" and @required]', $form)->length;
}

it('keeps ready picker assets selectable when uploads are quota blocked', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $plan = Plan::factory()->hasLimit('media_assets', 1)->create(['is_default' => true]);
    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'display_name' => 'Reusable existing asset',
    ]);

    Livewire::actingAs($user)
        ->test(IngredientEditor::class)
        ->assertSee('New uploads are blocked')
        ->assertSeeHtml('data-media-picker-upload-unavailable')
        ->assertSeeHtml('data-media-picker-asset');

    $this->actingAs($user)
        ->getJson(route('media.picker-assets'))
        ->assertJsonPath('data.0.id', $asset->id)
        ->assertJsonPath('data.0.status', MediaAssetStatus::Ready->value);
});

it('synchronizes one reusable asset per consumer role without increasing quota', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $ingredient = Ingredient::factory()->create(['workspace_id' => $workspace->id]);
    $packaging = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Amber jar',
        'unit_cost' => 1,
        'currency' => 'EUR',
    ]);
    $service = app(MediaAssetUsageService::class);

    $service->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeFeatured, $asset->id);
    $service->syncSingle($user, $ingredient, MediaAssetUsageRole::IngredientMain, $asset->id);
    $service->syncSingle($user, $packaging, MediaAssetUsageRole::PackagingMain, $asset->id);

    expect($asset->fresh()->usages)->toHaveCount(3)
        ->and(MediaAsset::query()->count())->toBe(1);

    $service->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeFeatured, null);

    expect($asset->fresh()->usages)->toHaveCount(2)
        ->and($asset->fresh())->not->toBeNull();
});

it('allows up to eight ready SOP images from the same workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $assets = MediaAsset::factory()->ready()->count(8)->create(['workspace_id' => $workspace->id]);

    app(MediaAssetUsageService::class)->syncMany(
        $user,
        $recipe,
        MediaAssetUsageRole::RecipeSop,
        $assets->pluck('id')->all(),
        maximum: 8,
    );

    expect(MediaAssetUsage::query()
        ->where('usable_type', Recipe::class)
        ->where('usable_id', $recipe->id)
        ->where('role', MediaAssetUsageRole::RecipeSop)
        ->count())->toBe(8);

    $ninth = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);

    expect(fn () => app(MediaAssetUsageService::class)->syncMany(
        $user,
        $recipe,
        MediaAssetUsageRole::RecipeSop,
        [...$assets->pluck('id')->all(), $ninth->id],
        maximum: 8,
    ))->toThrow(ValidationException::class, 'up to 8 images');
});

it('does not render a standalone SOP gallery or filenames below inline procedure images', function () {
    $versionSheet = file_get_contents(resource_path('views/recipes/partials/version-sheet.blade.php'));

    expect($versionSheet)
        ->not->toContain('recipes.partials.sop-media')
        ->not->toContain('sopMediaAssets')
        ->and(resource_path('views/recipes/partials/sop-media.blade.php'))
        ->not->toBeFile();
});

it('rejects processing, failed, and cross-workspace selections', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $processing = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);
    $failed = MediaAsset::factory()->failed()->create(['workspace_id' => $workspace->id]);
    $outside = MediaAsset::factory()->ready()->create();
    $service = app(MediaAssetUsageService::class);

    foreach ([$processing, $failed, $outside] as $asset) {
        expect(fn () => $service->syncSingle(
            $user,
            $recipe,
            MediaAssetUsageRole::RecipeFeatured,
            $asset->id,
        ))->toThrow(ValidationException::class);
    }

    expect($processing->status)->toBe(MediaAssetStatus::Processing);
});

it('uses the intended conversions for catalog, index, and icon consumers', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $ingredient = Ingredient::factory()->create(['workspace_id' => $workspace->id]);
    $packaging = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Tin',
        'unit_cost' => 1,
        'currency' => 'EUR',
    ]);

    $usages = app(MediaAssetUsageService::class);
    $usages->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeFeatured, $asset->id);
    $usages->syncSingle($user, $ingredient, MediaAssetUsageRole::IngredientMain, $asset->id);
    $usages->syncSingle($user, $packaging, MediaAssetUsageRole::PackagingMain, $asset->id);

    expect($recipe->indexImageUrl())->toBe(route('media.show', [$asset, 'recipe-index']))
        ->and($recipe->featuredImageUrl())->toBe(route('media.show', [$asset, 'catalog']))
        ->and($ingredient->iconImageUrl())->toBe(route('media.show', [$asset, 'icon']))
        ->and($packaging->iconImageUrl())->toBe(route('media.show', [$asset, 'icon']));
});

it('copies recipe SOP media to current and historical versions without duplicating assets', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $currentVersion = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'is_current' => true]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);

    app(MediaAssetUsageService::class)->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeSop, $asset->id);
    app(RecipeSopSnapshotService::class)->syncCurrentVersion($recipe, 'Follow the SOP.');

    expect($currentVersion->fresh()->mediaAssetsForRole(MediaAssetUsageRole::RecipeSop)->pluck('id')->all())
        ->toBe([$asset->id])
        ->and(MediaAsset::query()->count())->toBe(1);
});

it('removes usage rows when a consumer is deleted but retains the shared asset', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id]);
    $ingredient = Ingredient::factory()->create(['workspace_id' => $workspace->id]);
    $packaging = createPackagingItemForWorkspace(['user_id' => $user->id, 'name' => 'Jar', 'unit_cost' => 1, 'currency' => 'EUR']);
    $service = app(MediaAssetUsageService::class);

    $service->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeFeatured, $asset->id);
    $service->syncSingle($user, $version, MediaAssetUsageRole::RecipeSop, $asset->id);
    $service->syncSingle($user, $ingredient, MediaAssetUsageRole::IngredientMain, $asset->id);
    $service->syncSingle($user, $packaging, MediaAssetUsageRole::PackagingMain, $asset->id);

    $ingredient->delete();
    $packaging->currentPrice()->delete();
    $packaging->delete();
    $recipe->delete();

    expect(MediaAssetUsage::query()->where('media_asset_id', $asset->id)->exists())->toBeFalse()
        ->and($asset->fresh())->not->toBeNull();
});

it('rolls back ingredient and packaging edits when a selected asset is outside the workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $validAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $outsideAsset = MediaAsset::factory()->ready()->create();
    $ingredient = Ingredient::factory()->create(['workspace_id' => $workspace->id, 'display_name' => 'Original ingredient']);
    $packaging = createPackagingItemForWorkspace(['user_id' => $user->id, 'name' => 'Original jar', 'unit_cost' => 1, 'currency' => 'EUR']);
    $usages = app(MediaAssetUsageService::class);
    $usages->syncSingle($user, $ingredient, MediaAssetUsageRole::IngredientMain, $validAsset->id);
    $usages->syncSingle($user, $packaging, MediaAssetUsageRole::PackagingMain, $validAsset->id);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.name', 'Changed ingredient')
        ->set('data.featured_media_asset_id', $outsideAsset->id)
        ->call('save');

    expect($ingredient->fresh()->display_name)->toBe('Original ingredient')
        ->and($usages->idsFor($ingredient, MediaAssetUsageRole::IngredientMain))->toBe([$validAsset->id]);

    Livewire::test(PackagingItemEditor::class, ['packagingItem' => $packaging])
        ->set('data.name', 'Changed jar')
        ->set('data.featured_media_asset_id', $outsideAsset->id)
        ->call('save')
        ->assertHasErrors(['media']);

    expect($packaging->fresh()->name)->toBe('Original jar')
        ->and($usages->idsFor($packaging, MediaAssetUsageRole::PackagingMain))->toBe([$validAsset->id]);
});

it('preserves legacy paths when authoring updates do not submit retired upload fields', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $ingredient = Ingredient::factory()->create([
        'workspace_id' => $workspace->id,
        'featured_image_path' => 'ingredients/legacy-feature.webp',
        'icon_image_path' => 'ingredients/legacy-icon.webp',
    ]);
    $packaging = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Legacy tin',
        'unit_cost' => 1,
        'currency' => 'EUR',
        'featured_image_path' => 'packaging/legacy.webp',
    ]);

    $ingredientAuthoring = app(UserIngredientAuthoringService::class);
    $ingredientAuthoring->update($ingredient, [
        ...collect($ingredientAuthoring->formData($ingredient))
            ->except([
                'featured_image_path',
                'featured_image_original_name',
                'icon_image_path',
                'icon_image_original_name',
            ])
            ->all(),
        'name' => 'Updated legacy ingredient',
    ], $user);
    app(PackagingItemAuthoringService::class)->update($packaging, [
        'name' => 'Updated legacy tin',
        'unit_cost' => 1,
        'notes' => null,
    ], $user);

    expect($ingredient->fresh()->featured_image_path)->toBe('ingredients/legacy-feature.webp')
        ->and($ingredient->fresh()->icon_image_path)->toBe('ingredients/legacy-icon.webp')
        ->and($packaging->fresh()->featured_image_path)->toBe('packaging/legacy.webp');
});

it('renders media-backed index lists without query growth per record', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $usages = app(MediaAssetUsageService::class);

    foreach (range(1, 6) as $index) {
        $ingredient = Ingredient::factory()->create(['workspace_id' => $workspace->id, 'display_name' => "Ingredient {$index}"]);
        $packaging = createPackagingItemForWorkspace(['user_id' => $user->id, 'name' => "Packaging {$index}", 'unit_cost' => 1, 'currency' => 'EUR']);
        $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'owner_id' => $user->id, 'product_family_id' => $family->id]);
        $usages->syncSingle($user, $ingredient, MediaAssetUsageRole::IngredientMain, $asset->id);
        $usages->syncSingle($user, $packaging, MediaAssetUsageRole::PackagingMain, $asset->id);
        $usages->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeFeatured, $asset->id);
    }

    $this->actingAs($user);
    $queryCounts = [];

    foreach ([
        IngredientsIndex::class => 'Ingredient 1',
        PackagingItemsIndex::class => 'Packaging 1',
        RecipesIndex::class => $recipe->name,
    ] as $component => $visibleText) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test($component)->assertSee($visibleText);
        $queryCounts[$component] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }

    expect($queryCounts[IngredientsIndex::class])->toBeLessThanOrEqual(30)
        ->and($queryCounts[PackagingItemsIndex::class])->toBeLessThanOrEqual(30)
        ->and($queryCounts[RecipesIndex::class])->toBeLessThanOrEqual(30);
});
