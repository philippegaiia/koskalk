<?php

use App\Actions\Ingredients\CreateManufacturedIngredient;
use App\Enums\IngredientCategory;
use App\Enums\MediaAssetUsageRole;
use App\Enums\OwnerType;
use App\Enums\ProductionOutputType;
use App\Enums\Visibility;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\FattyAcid;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use App\Models\MediaAsset;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\ProductTypeIfraCategory;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Services\MediaAssetUsageService;
use App\Services\MediaStorage;
use App\Services\RecipeContentUpdater;
use App\Services\RecipeVersionStructureSynchronizer;
use App\Services\RecipeVersionViewDataBuilder;
use App\Services\RecipeWorkbenchService;
use App\Services\RecipeWorkbenchViewDataBuilder;
use App\Support\RichContentAttachmentPaths;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('persists lye liquid substitutions as a share of the calculated dilution liquid', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $hydrosol = Ingredient::factory()->create([
        'display_name' => 'Rose hydrosol',
        'inci_name' => 'ROSA DAMASCENA FLOWER WATER',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_active' => true,
    ]);
    $payload = workbenchSoapDraftPayload(makeCarrierOilIngredient());
    $payload['phase_items']['lye_water'] = [[
        'ingredient_id' => $hydrosol->id,
        'percentage' => 25,
        'weight' => 0,
        'note' => 'Add chilled',
    ]];

    $version = app(RecipeWorkbenchService::class)->save($user, $soapFamily, $payload);
    $phase = RecipePhase::withoutGlobalScopes()
        ->where('recipe_version_id', $version->id)
        ->where('slug', 'lye_water')
        ->firstOrFail();
    $item = RecipeItem::withoutGlobalScopes()
        ->where('recipe_phase_id', $phase->id)
        ->first();

    expect($item)->not->toBeNull()
        ->and((float) $item->percentage)->toBe(25.0)
        ->and((float) $item->weight)->toBe(95.0)
        ->and($item->note)->toBe('Add chilled');

    $snapshot = app(RecipeWorkbenchService::class)->currentVersionSnapshot(
        Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id),
    );

    expect($snapshot['calculation']['lye']['liquid_composition']['water_weight'])->toBe(285.0)
        ->and($snapshot['calculation']['lye']['liquid_composition']['substitutions'][0]['weight'])->toBe(95.0);

    $ingredientRows = collect($snapshot['labeling']['ingredient_rows'])->keyBy('label');

    expect($ingredientRows['AQUA']['weight'])->toBe(285.0)
        ->and($ingredientRows['ROSA DAMASCENA FLOWER WATER']['weight'])->toBe(95.0)
        ->and($ingredientRows['ROSA DAMASCENA FLOWER WATER']['kind'])->toBe('lye_liquid');
});

it('rejects lye liquid substitutions above one hundred percent', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $hydrosol = Ingredient::factory()->create(['is_active' => true]);
    $milk = Ingredient::factory()->create(['is_active' => true]);
    $payload = workbenchSoapDraftPayload(makeCarrierOilIngredient());
    $payload['phase_items']['lye_water'] = [
        ['ingredient_id' => $hydrosol->id, 'percentage' => 60, 'weight' => 0],
        ['ingredient_id' => $milk->id, 'percentage' => 41, 'weight' => 0],
    ];

    app(RecipeWorkbenchService::class)->save($user, $soapFamily, $payload);
})->throws(ValidationException::class);

it('rejects soapmaking alkalis as lye liquid replacements in preview and persistence', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $alkali = Ingredient::factory()->create([
        'category' => IngredientCategory::SoapmakingAlkalis,
        'display_name' => 'Sodium hydroxide',
        'is_active' => true,
    ]);
    $payload = workbenchSoapDraftPayload(makeCarrierOilIngredient());
    $payload['phase_items']['lye_water'] = [[
        'ingredient_id' => $alkali->id,
        'percentage' => 25,
        'weight' => 0,
    ]];
    $message = 'Soapmaking alkalis cannot be used as lye liquid replacements.';

    expect(fn () => app(RecipeWorkbenchService::class)->previewSoapCalculation($payload, $user))
        ->toThrow(ValidationException::class, $message)
        ->and(fn () => app(RecipeWorkbenchService::class)->save($user, $soapFamily, $payload))
        ->toThrow(ValidationException::class, $message);
});

it('rejects positive lye liquid replacements without an active accessible ingredient', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $inaccessibleIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'is_active' => true,
    ]);
    $message = 'Select an active, accessible ingredient for each lye liquid replacement.';

    foreach ([null, 999999, $inaccessibleIngredient->id] as $ingredientId) {
        $payload = workbenchSoapDraftPayload(makeCarrierOilIngredient());
        $payload['phase_items']['lye_water'] = [[
            'ingredient_id' => $ingredientId,
            'percentage' => 25,
            'weight' => 0,
        ]];

        expect(fn () => app(RecipeWorkbenchService::class)->previewSoapCalculation($payload, $user))
            ->toThrow(ValidationException::class, $message)
            ->and(fn () => app(RecipeWorkbenchService::class)->save($user, $soapFamily, $payload))
            ->toThrow(ValidationException::class, $message);
    }
});

it('ignores blank zero percentage lye liquid placeholders in preview and persistence', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $payload = workbenchSoapDraftPayload(makeCarrierOilIngredient());
    $payload['phase_items']['lye_water'] = [[
        'ingredient_id' => null,
        'percentage' => '',
        'weight' => 0,
    ]];

    $preview = app(RecipeWorkbenchService::class)->previewSoapCalculation($payload, $user);
    $version = app(RecipeWorkbenchService::class)->save($user, $soapFamily, $payload);
    $lyeLiquidPhase = RecipePhase::withoutGlobalScopes()
        ->where('recipe_version_id', $version->id)
        ->where('slug', 'lye_water')
        ->firstOrFail();

    expect($preview['lye']['liquid_composition']['substitutions'])->toBe([])
        ->and($lyeLiquidPhase->items)->toHaveCount(0);
});

it('limits null user lye liquid previews to public ingredients', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    $privateIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_active' => true,
    ]);
    $publicIngredient = Ingredient::factory()->create([
        'visibility' => Visibility::Public,
        'is_active' => true,
    ]);
    $message = 'Select an active, accessible ingredient for each lye liquid replacement.';

    $privatePayload = workbenchSoapDraftPayload(makeCarrierOilIngredient());
    $privatePayload['phase_items']['lye_water'] = [[
        'ingredient_id' => $privateIngredient->id,
        'percentage' => 25,
    ]];
    $publicPayload = workbenchSoapDraftPayload(makeCarrierOilIngredient());
    $publicPayload['phase_items']['lye_water'] = [[
        'ingredient_id' => $publicIngredient->id,
        'percentage' => 25,
    ]];

    expect(fn () => app(RecipeWorkbenchService::class)->previewSoapCalculation($privatePayload))
        ->toThrow(ValidationException::class, $message)
        ->and(app(RecipeWorkbenchService::class)->previewSoapCalculation($privatePayload, $owner))
        ->toHaveKey('lye.liquid_composition.substitutions.0.ingredient_id', $privateIngredient->id)
        ->and(app(RecipeWorkbenchService::class)->previewSoapCalculation($publicPayload))
        ->toHaveKey('lye.liquid_composition.substitutions.0.ingredient_id', $publicIngredient->id);
});

it('does not repeat lye ingredient validation queries while saving', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $hydrosol = Ingredient::factory()->create(['is_active' => true]);
    $payload = workbenchSoapDraftPayload(makeCarrierOilIngredient());
    $payload['phase_items']['lye_water'] = [[
        'ingredient_id' => $hydrosol->id,
        'percentage' => 25,
    ]];
    $ingredientQueries = [];

    DB::listen(function ($query) use (&$ingredientQueries): void {
        if (str_contains($query->sql, 'from "ingredients"')) {
            $ingredientQueries[] = $query->sql;
        }
    });

    app(RecipeWorkbenchService::class)->save($user, $soapFamily, $payload);

    expect($ingredientQueries)->toHaveCount(4);
});

it('creates an active workspace manufactured ingredient for inline recipe output setup', function () {
    $user = User::factory()->create();

    $ingredient = app(CreateManufacturedIngredient::class)->handle($user, 'Turmeric oil macerate');

    expect($ingredient->fresh())
        ->display_name->toBe('Turmeric oil macerate')
        ->is_manufactured->toBeTrue()
        ->is_active->toBeTrue()
        ->owner_type->toBe(OwnerType::Workspace)
        ->owner_id->toBe($user->company()->id)
        ->workspace_id->toBe($user->company()->id)
        ->visibility->toBe(Visibility::Private)
        ->and(SupplierListing::query()->where('ingredient_id', $ingredient->id)->count())->toBe(0);
});

it('exposes product or ingredient identity at the start of formula settings', function (): void {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $productType = ProductType::factory()->create(['product_family_id' => $soapFamily->id]);
    app(CreateManufacturedIngredient::class)->handle($user, 'Turmeric oil macerate');

    $this->actingAs($user)
        ->get(route('recipes.create', ['family' => 'soap', 'type' => $productType->slug]))
        ->assertSuccessful()
        ->assertSee('data-formula-output-type', false)
        ->assertSee('id="setting-formula-output-type"', false)
        ->assertSee('This formula produces')
        ->assertSee('Product')
        ->assertSee('Ingredient')
        ->assertSee('Turmeric oil macerate');
});

it('keeps product identity in formula settings and composition output in its own tab', function (): void {
    $formulaSettings = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));
    $formulaOutputType = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-output-type.blade.php'));
    $outputTab = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php'));
    $publicFormulaSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings', [
        'isPublicCalculator' => true,
    ])->render();

    expect($formulaSettings)
        ->toContain('recipe-workbench.formula-output-type')
        ->and($formulaOutputType)
        ->toContain('data-formula-output-type')
        ->toContain('role="radiogroup"')
        ->toContain(':aria-checked="productionOutputType === \'finished_product\'"')
        ->toContain(':aria-checked="productionOutputType === \'manufactured_ingredient\'"')
        ->not->toContain('readyDelayDays')
        ->not->toContain('productReference')
        ->not->toContain('nominalContentValue')
        ->not->toContain('duplicateFormula()')
        ->and($outputTab)
        ->not->toContain('production-output-settings')
        ->not->toContain('formula-output-type')
        ->and($publicFormulaSettings)
        ->not->toContain('data-formula-output-type');
});

it('persists manufactured ingredient output configuration through recipe save, reload, and duplication', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $carrierOil = makeCarrierOilIngredient();
    $outputIngredient = app(CreateManufacturedIngredient::class)->handle($user, 'Turmeric oil macerate');
    $payload = workbenchSoapDraftPayload($carrierOil, name: 'Turmeric macerate');
    $payload['production_output_type'] = ProductionOutputType::ManufacturedIngredient->value;
    $payload['output_ingredient_id'] = $outputIngredient->id;
    $payload['ready_delay_days'] = 12;

    $service = app(RecipeWorkbenchService::class);
    $savedVersion = $service->save($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($savedVersion->recipe_id);
    $workbenchPayload = $service->currentVersionPayload($recipe);
    $duplicateVersion = $service->duplicateRecipe($user, $recipe);
    $duplicate = Recipe::withoutGlobalScopes()->findOrFail($duplicateVersion->recipe_id);

    expect($recipe->production_output_type)->toBe(ProductionOutputType::ManufacturedIngredient)
        ->and($recipe->output_ingredient_id)->toBe($outputIngredient->id)
        ->and($recipe->ready_delay_days)->toBe(12)
        ->and($workbenchPayload['productionOutputType'])->toBe(ProductionOutputType::ManufacturedIngredient->value)
        ->and($workbenchPayload['outputIngredientId'])->toBe($outputIngredient->id)
        ->and($workbenchPayload['readyDelayDays'])->toBe(12)
        ->and($duplicate->production_output_type)->toBe(ProductionOutputType::ManufacturedIngredient)
        ->and($duplicate->output_ingredient_id)->toBe($outputIngredient->id)
        ->and($duplicate->ready_delay_days)->toBe(12);
});

it('persists optional finished product reference and nominal content through save and reload', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $payload = workbenchSoapDraftPayload(makeCarrierOilIngredient(), name: 'Lavender soap 100 g');
    $payload['product_reference'] = ' lav-100 ';
    $payload['nominal_content_value'] = 100;
    $payload['nominal_content_unit'] = 'g';

    $service = app(RecipeWorkbenchService::class);
    $savedVersion = $service->save($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($savedVersion->recipe_id);
    $workbenchPayload = $service->currentVersionPayload($recipe);

    expect($recipe->product_reference)->toBe('LAV-100')
        ->and((float) $recipe->nominal_content_value)->toBe(100.0)
        ->and($recipe->nominal_content_unit?->value)->toBe('g')
        ->and($workbenchPayload['productReference'])->toBe('LAV-100')
        ->and($workbenchPayload['nominalContentValue'])->toBe(100.0)
        ->and($workbenchPayload['nominalContentUnit'])->toBe('g');
});

it('allows finished product reference and nominal content to be omitted', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $savedVersion = app(RecipeWorkbenchService::class)->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload(makeCarrierOilIngredient()),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($savedVersion->recipe_id);

    expect($recipe->product_reference)->toBeNull()
        ->and($recipe->nominal_content_value)->toBeNull()
        ->and($recipe->nominal_content_unit)->toBeNull();
});

it('duplicates a product for another size while clearing its unique reference', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $payload = workbenchSoapDraftPayload(makeCarrierOilIngredient(), name: 'Lavender soap 100 g');
    $payload['product_reference'] = 'LAV-100';
    $payload['nominal_content_value'] = 100;
    $payload['nominal_content_unit'] = 'g';
    $payload['ready_delay_days'] = 42;
    $payload['packaging_items'] = [[
        'packaging_item_id' => null,
        'name' => '100 g soap box',
        'components_per_unit' => 1,
        'notes' => 'Primary packaging',
    ]];

    $service = app(RecipeWorkbenchService::class);
    $savedVersion = $service->save($user, $soapFamily, $payload);
    $source = Recipe::withoutGlobalScopes()->findOrFail($savedVersion->recipe_id);
    $duplicateVersion = $service->duplicateRecipe($user, $source);
    $duplicate = Recipe::withoutGlobalScopes()->findOrFail($duplicateVersion->recipe_id);

    expect($duplicate->name)->toBe('Copy of Lavender soap 100 g')
        ->and($duplicate->product_reference)->toBeNull()
        ->and((float) $duplicate->nominal_content_value)->toBe(100.0)
        ->and($duplicate->nominal_content_unit?->value)->toBe('g')
        ->and($duplicate->ready_delay_days)->toBe(42)
        ->and($duplicateVersion->packagingItems)->toHaveCount(1)
        ->and($duplicateVersion->packagingItems->first()?->name)->toBe('100 g soap box')
        ->and((float) $duplicateVersion->packagingItems->first()?->components_per_unit)->toBe(1.0);
});

it('requires a finished product reference to be unique within its workspace', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $service = app(RecipeWorkbenchService::class);
    $firstPayload = workbenchSoapDraftPayload(makeCarrierOilIngredient(), name: 'Lavender soap');
    $firstPayload['product_reference'] = 'LAV-100';
    $service->save($user, $soapFamily, $firstPayload);

    $secondPayload = workbenchSoapDraftPayload(makeCarrierOilIngredient(), name: 'Another lavender soap');
    $secondPayload['product_reference'] = ' lav-100 ';

    expect(fn () => $service->save($user, $soapFamily, $secondPayload))
        ->toThrow(ValidationException::class, 'product reference');
});

it('requires a valid manufactured ingredient when a recipe output is configured as manufactured', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $payload = workbenchSoapDraftPayload(makeCarrierOilIngredient());
    $payload['production_output_type'] = ProductionOutputType::ManufacturedIngredient->value;

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $soapFamily, $payload))
        ->toThrow(ValidationException::class);
});

it('does not allow a finished product recipe to point at an output ingredient', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $outputIngredient = app(CreateManufacturedIngredient::class)->handle($user, 'Turmeric oil macerate');
    $payload = workbenchSoapDraftPayload(makeCarrierOilIngredient());
    $payload['production_output_type'] = ProductionOutputType::FinishedProduct->value;
    $payload['output_ingredient_id'] = $outputIngredient->id;

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $soapFamily, $payload))
        ->toThrow(ValidationException::class);
});

beforeEach(function () {
    Storage::fake(MediaStorage::recipeDisk());
});

it('syncs the parent recipe name when a saved draft is renamed', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Recipe A'),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Recipe B'),
        $recipe,
    );

    $recipe = $recipe->fresh();
    $workspace = $user->company();

    expect($recipe->name)->toBe('Recipe B')
        ->and($workspace)->not->toBeNull()
        ->and($workspace->owner_user_id)->toBe($user->id)
        ->and($recipe->workspace_id)->toBe($workspace->id)
        ->and($recipe->owner_type)->toBe(OwnerType::Workspace)
        ->and($recipe->owner_id)->toBe($workspace->id)
        ->and($recipe->created_by)->toBe($user->id)
        ->and(RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $draftVersion->recipe_id)
            ->where('is_current', true)
            ->count())->toBe(1);
});

it('returns a structured error instead of throwing when oil weight is invalid', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->save(
        workbenchSoapDraftPayload($ingredient, oilWeight: 0),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toHaveKey('oil_weight')
        ->and($result['errors']['oil_weight'][0])->toContain('oil weight');
});

it('does not save a draft from a mounted component after the auth session is gone', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    auth()->logout();

    $result = $component->save(
        workbenchSoapDraftPayload($ingredient, name: 'Fallback Draft'),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('signed in')
        ->and(Recipe::withoutGlobalScopes()->where('name', 'Fallback Draft')->exists())->toBeFalse()
        ->and($soapFamily->exists)->toBeTrue();
});

it('stores instructions entered before the first draft on the new current version', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();
    $component->data['description'] = '<p>Presentation ready before the first save.</p>';
    $component->data['manufacturing_instructions'] = '<p>Step 1: Prepare the mould.</p>';

    $result = $component->save(
        workbenchSoapDraftPayload($ingredient, name: 'Draft With Content'),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    expect($result['ok'])->toBeTrue();

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($result['snapshot']['draft']['recipe']['id']);
    $currentVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();

    expect($recipe->description)->toContain('Presentation ready before the first save')
        ->and($recipe->manufacturing_instructions)->toContain('Prepare the mould')
        ->and($currentVersion->manufacturing_instructions)->toBe('<p>Step 1: Prepare the mould.</p>')
        ->and($recipe->featured_image_path)->toBeNull();
});

it('keeps an existing recipe aligned with its current version after a formula save and remount', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Existing Formula'),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount($recipe);
    $component->data['manufacturing_instructions'] = '<p>Saved from the formula action.</p>';
    $result = $component->save(
        workbenchSoapDraftPayload($ingredient, name: 'Existing Formula'),
        $service,
        app(RecipeContentUpdater::class),
    );

    $remountedComponent = app(RecipeWorkbench::class);
    $remountedComponent->mount($recipe->fresh());

    expect($result['ok'])->toBeTrue()
        ->and($recipe->fresh()->manufacturing_instructions)->toBe('<p>Saved from the formula action.</p>')
        ->and($draftVersion->fresh()->manufacturing_instructions)->toBe('<p>Saved from the formula action.</p>')
        ->and($remountedComponent->data['manufacturing_instructions'])->toBeArray()
        ->and(json_encode($remountedComponent->data['manufacturing_instructions'], JSON_THROW_ON_ERROR))
        ->toContain('Saved from the formula action.');
});

it('synchronizes inline SOP media before an immediate formula save snapshots the current version', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);
    $currentVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Inline SOP Save'),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($currentVersion->recipe_id);
    $workspace = $user->company();
    $staleAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $inlineAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $usages = app(MediaAssetUsageService::class);
    $usages->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeSop, $staleAsset->id);
    $usages->syncSingle($user, $currentVersion, MediaAssetUsageRole::RecipeSop, $staleAsset->id);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.manufacturing_instructions', recipeWorkbenchTipTapMediaAssets($inlineAsset))
        ->call('save', workbenchSoapDraftPayload($ingredient, name: 'Inline SOP Save'))
        ->assertReturned(fn (array $response): bool => $response['ok'] === true);

    expect($usages->idsFor($recipe, MediaAssetUsageRole::RecipeSop))->toBe([$inlineAsset->id])
        ->and($usages->idsFor($currentVersion, MediaAssetUsageRole::RecipeSop))->toBe([$inlineAsset->id])
        ->and(MediaAsset::query()->whereKey($staleAsset->id)->exists())->toBeTrue();
});

it('rolls back inline SOP usage synchronization when the formula snapshot fails', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $currentVersion = app(RecipeWorkbenchService::class)->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'SOP Rollback'),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($currentVersion->recipe_id);
    $workspace = $user->company();
    $staleAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $inlineAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $originalInstructions = recipeWorkbenchInlineMediaHtml($staleAsset);
    $recipe->update(['manufacturing_instructions' => $originalInstructions]);
    $currentVersion->update(['manufacturing_instructions' => $originalInstructions]);
    $usages = app(MediaAssetUsageService::class);
    $usages->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeSop, $staleAsset->id);
    $usages->syncSingle($user, $currentVersion, MediaAssetUsageRole::RecipeSop, $staleAsset->id);
    mock(RecipeVersionStructureSynchronizer::class)
        ->shouldReceive('sync')
        ->once()
        ->andThrow(new RuntimeException('Snapshot failed.'));
    $payload = workbenchSoapDraftPayload($ingredient, name: 'SOP Rollback');
    $payload['manufacturing_instructions'] = recipeWorkbenchInlineMediaHtml($inlineAsset);

    expect(fn () => app(RecipeWorkbenchService::class)->save(
        $user,
        $soapFamily,
        $payload,
        $recipe,
    ))->toThrow(RuntimeException::class, 'Snapshot failed.');

    expect($usages->idsFor($recipe, MediaAssetUsageRole::RecipeSop))->toBe([$staleAsset->id])
        ->and($usages->idsFor($currentVersion, MediaAssetUsageRole::RecipeSop))->toBe([$staleAsset->id])
        ->and($recipe->fresh()->manufacturing_instructions)->toBe($originalInstructions)
        ->and($currentVersion->fresh()->manufacturing_instructions)->toBe($originalInstructions);
});

it('persists a pending procedure image before an immediate formula save', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Immediate Media Save'),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $temporaryId = '018fa7f2-91aa-74a5-a665-18f8f3bf42d1';

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set(
            'componentFileAttachments.data.manufacturing_instructions.'.$temporaryId,
            UploadedFile::fake()->image('procedure.jpg', 1200, 600),
        )
        ->set('data.manufacturing_instructions', recipeWorkbenchTipTapProcedure($temporaryId))
        ->call('save', workbenchSoapDraftPayload($ingredient, name: 'Immediate Media Save'))
        ->assertReturned(fn (array $response): bool => $response['ok'] === true);

    $recipe = $recipe->fresh();
    $currentVersion = $draftVersion->fresh();
    $storedPath = $recipe->richContentAttachmentPaths('manufacturing_instructions')->sole();

    expect($storedPath)->not->toBe($temporaryId)
        ->and(MediaStorage::isRecipePath($recipe, $storedPath))->toBeTrue()
        ->and(Storage::disk(MediaStorage::recipeDisk())->exists($storedPath))->toBeTrue()
        ->and($recipe->manufacturing_instructions)->toContain($storedPath)
        ->and($currentVersion->manufacturing_instructions)->toContain($storedPath)
        ->and($recipe->manufacturing_instructions)->not->toContain($temporaryId)
        ->and($currentVersion->manufacturing_instructions)->not->toContain($temporaryId);
})->skip('Direct rich-content uploads were replaced by reusable Media Library selections.');

it('persists a pending procedure image atomically for an unsaved formula action', function (
    string $action,
    string $expectedName,
    int $expectedVersionCount,
) {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $temporaryId = '018fa7f2-91aa-74a5-a665-18f8f3bf4301';

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['productFamilySlug' => 'soap'])
        ->set(
            'componentFileAttachments.data.manufacturing_instructions.'.$temporaryId,
            UploadedFile::fake()->image('first-procedure.jpg', 1200, 600),
        )
        ->set('data.manufacturing_instructions', recipeWorkbenchTipTapProcedure($temporaryId))
        ->call($action, workbenchSoapDraftPayload($ingredient, name: 'First Media Formula'))
        ->assertReturned(fn (array $response): bool => $response['ok'] === true);

    $recipe = Recipe::withoutGlobalScopes()->where('name', $expectedName)->sole();
    $versions = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->get();
    $storedPath = $recipe->richContentAttachmentPaths('manufacturing_instructions')->sole();

    expect(Recipe::withoutGlobalScopes()->count())->toBe(1)
        ->and($versions)->toHaveCount($expectedVersionCount)
        ->and($versions->pluck('manufacturing_instructions')->filter()->count())->toBe($expectedVersionCount)
        ->and($versions->pluck('manufacturing_instructions')->implode(' '))->toContain($storedPath)
        ->and($storedPath)->not->toBe($temporaryId)
        ->and(MediaStorage::isRecipePath($recipe, $storedPath))->toBeTrue()
        ->and(Storage::disk(MediaStorage::recipeDisk())->exists($storedPath))->toBeTrue()
        ->and($recipe->manufacturing_instructions)->not->toContain($temporaryId);
})->with([
    'first save' => ['save', 'First Media Formula', 1],
    'first publish' => ['publish', 'First Media Formula', 2],
    'unsaved duplicate' => ['duplicateFormula', 'Copy of First Media Formula', 1],
])->skip('Direct rich-content uploads were replaced by reusable Media Library selections.');

it('rolls back an unsaved formula action when the procedure exceeds eight images', function (string $action) {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $temporaryIds = collect(range(1, 9))
        ->map(fn (int $index): string => '018fa7f2-91aa-74a5-a665-'.str_pad((string) $index, 12, '0', STR_PAD_LEFT))
        ->all();

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['productFamilySlug' => 'soap'])
        ->set('data.manufacturing_instructions', recipeWorkbenchTipTapProcedure($temporaryIds))
        ->call($action, workbenchSoapDraftPayload($ingredient, name: 'Invalid First Formula'))
        ->assertReturned(fn (array $response): bool => $response['ok'] === false
            && str_contains($response['message'], 'up to 8 images'));

    expect(Recipe::withoutGlobalScopes()->count())->toBe(0)
        ->and(RecipeVersion::withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk(MediaStorage::recipeDisk())->allFiles('recipes'))->toBe([]);
})->with([
    'first save' => ['save'],
    'first publish' => ['publish'],
    'unsaved duplicate' => ['duplicateFormula'],
])->skip('Direct rich-content uploads were replaced by reusable Media Library selections.');

it('rolls back an unsaved formula action containing a cross-recipe procedure image', function (string $action) {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $otherRecipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $otherPath = 'recipes/'.$otherRecipe->public_id.'/rich-content/private.webp';
    $temporaryId = '018fa7f2-91aa-74a5-a665-18f8f3bf4302';
    Storage::disk(MediaStorage::recipeDisk())->put($otherPath, 'other-recipe-image');

    $this->actingAs($user);

    $component = Livewire::test(RecipeWorkbench::class, ['productFamilySlug' => 'soap'])
        ->set(
            'componentFileAttachments.data.manufacturing_instructions.'.$temporaryId,
            UploadedFile::fake()->image('rolled-back-procedure.jpg', 1200, 600),
        )
        ->set('data.manufacturing_instructions', recipeWorkbenchTipTapProcedure([$temporaryId, $otherPath]))
        ->call($action, workbenchSoapDraftPayload($ingredient, name: 'Cross Reference Formula'))
        ->assertReturned(fn (array $response): bool => $response['ok'] === false);

    $manufacturingInstructions = $component->instance()->form->getComponent('manufacturing_instructions');

    expect(Recipe::withoutGlobalScopes()->count())->toBe(1)
        ->and(RecipeVersion::withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk(MediaStorage::recipeDisk())->allFiles('recipes'))->toBe([$otherPath])
        ->and($component->instance()->form->getRecord())->not->toBeInstanceOf(Recipe::class)
        ->and(collect($manufacturingInstructions->getToolbarButtons())->flatten()->all())
        ->not->toContain('attachFiles');
})->with([
    'first save' => ['save'],
    'first publish' => ['publish'],
    'unsaved duplicate' => ['duplicateFormula'],
])->skip('Direct rich-content uploads were replaced by reusable Media Library selections.');

it('persists a pending procedure image before an immediate formula publish', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Immediate Media Publish'),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $temporaryId = '018fa7f2-91aa-74a5-a665-18f8f3bf42d2';

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set(
            'componentFileAttachments.data.manufacturing_instructions.'.$temporaryId,
            UploadedFile::fake()->image('published-procedure.jpg', 1200, 600),
        )
        ->set('data.manufacturing_instructions', recipeWorkbenchTipTapProcedure($temporaryId))
        ->call('publish', workbenchSoapDraftPayload($ingredient, name: 'Immediate Media Publish'))
        ->assertReturned(fn (array $response): bool => $response['ok'] === true);

    $recipe = $recipe->fresh();
    $versions = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->orderBy('version_number')
        ->get();
    $storedPath = $recipe->richContentAttachmentPaths('manufacturing_instructions')->sole();

    expect($versions)->toHaveCount(2)
        ->and($storedPath)->not->toBe($temporaryId)
        ->and(MediaStorage::isRecipePath($recipe, $storedPath))->toBeTrue()
        ->and(Storage::disk(MediaStorage::recipeDisk())->exists($storedPath))->toBeTrue()
        ->and($versions->first()->manufacturing_instructions)->toContain($storedPath)
        ->and($versions->last()->manufacturing_instructions)->toContain($storedPath)
        ->and($versions->pluck('manufacturing_instructions')->implode(' '))->not->toContain($temporaryId);
})->skip('Direct rich-content uploads were replaced by reusable Media Library selections.');

it('clears inline SOP media before an immediate formula publish snapshots both versions', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);
    $currentVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Inline SOP Publish'),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($currentVersion->recipe_id);
    $workspace = $user->company();
    $removedAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $instructions = recipeWorkbenchInlineMediaHtml($removedAsset);
    $recipe->update(['manufacturing_instructions' => $instructions]);
    $currentVersion->update(['manufacturing_instructions' => $instructions]);
    $usages = app(MediaAssetUsageService::class);
    $usages->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeSop, $removedAsset->id);
    $usages->syncSingle($user, $currentVersion, MediaAssetUsageRole::RecipeSop, $removedAsset->id);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.manufacturing_instructions', '<p>Procedure image removed.</p>')
        ->call('publish', workbenchSoapDraftPayload($ingredient, name: 'Inline SOP Publish'))
        ->assertReturned(fn (array $response): bool => $response['ok'] === true);

    $versions = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->orderBy('version_number')
        ->get();

    expect($versions)->toHaveCount(2)
        ->and($usages->idsFor($recipe, MediaAssetUsageRole::RecipeSop))->toBe([])
        ->and($usages->idsFor($versions->first(), MediaAssetUsageRole::RecipeSop))->toBe([])
        ->and($usages->idsFor($versions->last(), MediaAssetUsageRole::RecipeSop))->toBe([])
        ->and(MediaAsset::query()->whereKey($removedAsset->id)->exists())->toBeTrue();
});

it('cleans first-action media when the outer quota transaction fails', function (string $action) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $events = [];
    $entitlementService = mock(EntitlementService::class);

    $entitlementService->shouldReceive('withinCompanyQuotaLock')
        ->once()
        ->andReturnUsing(function (User $lockedUser, Closure $callback, int $attempts) use (&$events, $workspace): mixed {
            expect($attempts)->toBe(1);

            return DB::transaction(function () use ($callback, &$events, $workspace): never {
                $events[] = 'quota-started';
                $callback($workspace);
                $events[] = 'formula-saved';

                throw new RuntimeException('Outer quota transaction failed.');
            });
        });
    $entitlementService->shouldReceive('assertCanCreateRecipeInWorkspace')->once();
    $entitlementService->shouldReceive('savedFormulaHistoryLimitFor')
        ->zeroOrMoreTimes()
        ->andReturn(0);

    $this->actingAs($user);
    $temporaryId = '018fa7f2-91aa-74a5-a665-18f8f3bf42d6';
    $component = Livewire::test(RecipeWorkbench::class, ['productFamilySlug' => 'soap'])
        ->set(
            'componentFileAttachments.data.manufacturing_instructions.'.$temporaryId,
            UploadedFile::fake()->image('outer-rollback.jpg', 1200, 600),
        )
        ->set('data.manufacturing_instructions', recipeWorkbenchTipTapProcedure($temporaryId));

    expect(fn () => $component->call(
        $action,
        workbenchSoapDraftPayload($ingredient, name: 'Rolled Back Formula'),
    ))->toThrow(RuntimeException::class, 'Outer quota transaction failed.')
        ->and(Recipe::withoutGlobalScopes()->where('name', 'Rolled Back Formula')->exists())->toBeFalse()
        ->and($events)->toBe(['quota-started', 'formula-saved'])
        ->and(Storage::disk(MediaStorage::recipeDisk())->allFiles('recipes'))->toBe([]);
})->with([
    'first save' => ['save'],
    'first publish' => ['publish'],
])->skip('Direct rich-content uploads were replaced by reusable Media Library selections.');

it('copies a pending procedure image into the destination namespace on immediate duplication', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);
    $sourceVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Immediate Media Duplicate'),
    );
    $sourceRecipe = Recipe::withoutGlobalScopes()->findOrFail($sourceVersion->recipe_id);
    $temporaryId = '018fa7f2-91aa-74a5-a665-18f8f3bf42d3';

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $sourceRecipe])
        ->set(
            'componentFileAttachments.data.manufacturing_instructions.'.$temporaryId,
            UploadedFile::fake()->image('duplicated-procedure.jpg', 1200, 600),
        )
        ->set('data.manufacturing_instructions', recipeWorkbenchTipTapProcedure($temporaryId))
        ->call('duplicateFormula', workbenchSoapDraftPayload($ingredient, name: 'Immediate Media Duplicate'))
        ->assertReturned(fn (array $response): bool => $response['ok'] === true);

    $destinationRecipe = Recipe::withoutGlobalScopes()
        ->where('name', 'Copy of Immediate Media Duplicate')
        ->firstOrFail();
    $destinationVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $destinationRecipe->id)
        ->where('is_current', true)
        ->firstOrFail();
    $destinationPath = $destinationRecipe
        ->richContentAttachmentPaths('manufacturing_instructions')
        ->sole();

    expect($destinationPath)->not->toBe($temporaryId)
        ->and(MediaStorage::isRecipePath($destinationRecipe, $destinationPath))->toBeTrue()
        ->and(MediaStorage::isRecipePath($sourceRecipe, $destinationPath))->toBeFalse()
        ->and(Storage::disk(MediaStorage::recipeDisk())->exists($destinationPath))->toBeTrue()
        ->and($destinationRecipe->manufacturing_instructions)->toContain($destinationPath)
        ->and($destinationVersion->manufacturing_instructions)->toContain($destinationPath)
        ->and($destinationRecipe->manufacturing_instructions)->not->toContain($temporaryId)
        ->and(Storage::disk(MediaStorage::recipeDisk())->allFiles('recipes/'.$sourceRecipe->public_id))
        ->toBe([]);
})->skip('Direct rich-content uploads were replaced by reusable Media Library selections.');

it('copies saved and pending procedure images together without source orphans', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);
    $sourceVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Mixed Media Duplicate'),
    );
    $sourceRecipe = Recipe::withoutGlobalScopes()->findOrFail($sourceVersion->recipe_id);
    $sourcePath = MediaStorage::recipeDirectory($sourceRecipe, 'rich-content').'/saved.webp';
    $temporaryId = '018fa7f2-91aa-74a5-a665-18f8f3bf42d5';
    Storage::disk(MediaStorage::recipeDisk())->put($sourcePath, 'saved-image');
    app(RecipeContentUpdater::class)->update($sourceRecipe, [
        'description' => null,
        'manufacturing_instructions' => '<p><img data-id="'.$sourcePath.'"></p>',
        'featured_image_path' => null,
        'featured_image_original_name' => null,
    ]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $sourceRecipe->fresh()])
        ->set(
            'componentFileAttachments.data.manufacturing_instructions.'.$temporaryId,
            UploadedFile::fake()->image('pending.jpg', 1200, 600),
        )
        ->set('data.manufacturing_instructions', recipeWorkbenchTipTapProcedure([$sourcePath, $temporaryId]))
        ->call('duplicateFormula', workbenchSoapDraftPayload($ingredient, name: 'Mixed Media Duplicate'))
        ->assertReturned(fn (array $response): bool => $response['ok'] === true);

    $destinationRecipe = Recipe::withoutGlobalScopes()
        ->where('name', 'Copy of Mixed Media Duplicate')
        ->firstOrFail();
    $destinationPaths = $destinationRecipe->richContentAttachmentPaths('manufacturing_instructions');

    expect($destinationPaths)->toHaveCount(2)
        ->and($destinationPaths->every(
            fn (string $path): bool => MediaStorage::isRecipePath($destinationRecipe, $path)
                && Storage::disk(MediaStorage::recipeDisk())->exists($path),
        ))->toBeTrue()
        ->and(Storage::disk(MediaStorage::recipeDisk())->allFiles('recipes/'.$sourceRecipe->public_id))
        ->toBe([$sourcePath]);
})->skip('Direct rich-content uploads were replaced by reusable Media Library selections.');

it('cleans pending source media when an existing formula duplicate is rejected', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);
    $sourceVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Rejected Media Duplicate'),
    );
    $sourceRecipe = Recipe::withoutGlobalScopes()->findOrFail($sourceVersion->recipe_id);
    $otherRecipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $otherPath = MediaStorage::recipeDirectory($otherRecipe, 'rich-content').'/private.webp';
    $temporaryId = '018fa7f2-91aa-74a5-a665-18f8f3bf42d4';
    Storage::disk(MediaStorage::recipeDisk())->put($otherPath, 'other-recipe-image');

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $sourceRecipe])
        ->set(
            'componentFileAttachments.data.manufacturing_instructions.'.$temporaryId,
            UploadedFile::fake()->image('rejected-duplicate.jpg', 1200, 600),
        )
        ->set('data.manufacturing_instructions', recipeWorkbenchTipTapProcedure([$temporaryId, $otherPath]))
        ->call('duplicateFormula', workbenchSoapDraftPayload($ingredient, name: 'Rejected Media Duplicate'))
        ->assertReturned(fn (array $response): bool => $response['ok'] === false);

    expect(Recipe::withoutGlobalScopes()->where('name', 'Copy of Rejected Media Duplicate')->exists())->toBeFalse()
        ->and(Storage::disk(MediaStorage::recipeDisk())->allFiles('recipes/'.$sourceRecipe->public_id))
        ->toBe([])
        ->and(Storage::disk(MediaStorage::recipeDisk())->allFiles('recipes'))
        ->toBe([$otherPath]);
})->skip('Direct rich-content uploads were replaced by reusable Media Library selections.');

it('rejects more than eight pending procedure images during a formula action', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, workbenchSoapDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $temporaryIds = collect(range(1, 9))
        ->map(fn (int $index): string => '018fa7f2-91aa-74a5-a665-'.str_pad((string) $index, 12, '0', STR_PAD_LEFT))
        ->all();

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.manufacturing_instructions', recipeWorkbenchTipTapProcedure($temporaryIds))
        ->call('save', workbenchSoapDraftPayload($ingredient))
        ->assertReturned(fn (array $response): bool => $response['ok'] === false
            && str_contains($response['message'], 'up to 8 images'));

    expect($recipe->fresh()->manufacturing_instructions)->toBeNull()
        ->and($draftVersion->fresh()->manufacturing_instructions)->toBeNull();
})->skip('Direct rich-content uploads were replaced by reusable Media Library selections.');

it('rejects a procedure image from another recipe during a formula action', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, workbenchSoapDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $otherRecipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $otherPath = 'recipes/'.$otherRecipe->public_id.'/rich-content/private.webp';
    Storage::disk(MediaStorage::recipeDisk())->put($otherPath, 'other-recipe-image');

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.manufacturing_instructions', recipeWorkbenchTipTapProcedure($otherPath))
        ->call('save', workbenchSoapDraftPayload($ingredient))
        ->assertReturned(fn (array $response): bool => $response['ok'] === false);

    expect($recipe->fresh()->manufacturing_instructions)->toBeNull()
        ->and($draftVersion->fresh()->manufacturing_instructions)->toBeNull()
        ->and(Storage::disk(MediaStorage::recipeDisk())->exists($otherPath))->toBeTrue();
})->skip('Direct rich-content uploads were replaced by reusable Media Library selections.');

it('copies component-duplicated instruction attachments into the destination recipe namespace', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);
    $sourceVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Media Source'),
    );
    $sourceRecipe = Recipe::withoutGlobalScopes()->findOrFail($sourceVersion->recipe_id);
    $sourceAttachment = 'recipes/'.$sourceRecipe->public_id.'/rich-content/procedure.webp';
    $sourceInstructions = '<p><img data-id="'.$sourceAttachment.'" src="/storage/'.$sourceAttachment.'"></p>';

    Storage::disk(MediaStorage::recipeDisk())->put($sourceAttachment, 'procedure-image');
    app(RecipeContentUpdater::class)->update($sourceRecipe, [
        'description' => null,
        'manufacturing_instructions' => $sourceInstructions,
        'featured_image_path' => null,
        'featured_image_original_name' => null,
    ]);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount($sourceRecipe->fresh());
    $result = $component->duplicateFormula(
        workbenchSoapDraftPayload($ingredient, name: 'Media Source'),
        $service,
    );

    $destinationRecipe = Recipe::withoutGlobalScopes()
        ->where('name', 'Copy of Media Source')
        ->firstOrFail();
    $destinationVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $destinationRecipe->id)
        ->where('is_current', true)
        ->firstOrFail();

    expect($result['ok'])->toBeTrue()
        ->and($destinationRecipe->manufacturing_instructions)->not->toBeNull()
        ->and($destinationVersion->manufacturing_instructions)->toBe($destinationRecipe->manufacturing_instructions);

    $destinationAttachment = $destinationRecipe
        ->richContentAttachmentPaths('manufacturing_instructions')
        ->sole();
    $remountedComponent = app(RecipeWorkbench::class);
    $remountedComponent->mount($destinationRecipe);

    expect($destinationAttachment)->not->toBe($sourceAttachment)
        ->and(MediaStorage::isRecipePath($destinationRecipe, $destinationAttachment))->toBeTrue()
        ->and(Storage::disk(MediaStorage::recipeDisk())->exists($destinationAttachment))->toBeTrue()
        ->and(Storage::disk(MediaStorage::recipeDisk())->get($destinationAttachment))->toBe('procedure-image')
        ->and($remountedComponent->data['manufacturing_instructions'])->toBeArray()
        ->and(json_encode(
            $remountedComponent->data['manufacturing_instructions'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ))
        ->toContain($destinationAttachment);
})->skip('Inline recipe images were replaced by reusable Media Library selections.');

it('returns backend soap calculation preview data for the workbench', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $oleic = FattyAcid::factory()->create([
        'key' => 'oleic',
        'name' => 'Oleic',
    ]);
    $palmitic = FattyAcid::factory()->create([
        'key' => 'palmitic',
        'name' => 'Palmitic',
    ]);

    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 71,
    ]);
    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $palmitic->id,
        'percentage' => 13,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $payload = workbenchSoapDraftPayload($ingredient, oilWeight: 1000);
    $payload['lye_type'] = 'koh';

    $result = $component->previewCalculation($payload, app(RecipeWorkbenchService::class));

    expect($result['ok'])->toBeTrue()
        ->and($result['calculation'])->not->toBeNull()
        ->and($result['calculation']['properties']['fatty_acid_profile']['oleic'])->toBe(71.0)
        ->and($result['calculation']['properties']['qualities'])->toHaveKey('unmolding_firmness')
        ->and($result['calculation']['properties']['warnings'])->toContain('high_koh_context_process_dependent');
});

it('reuses one preview bundle when calculation and labeling refresh together', function () {
    $draft = ['name' => 'Shared preview draft'];
    $calculation = ['lye' => ['total' => 142.0]];
    $labeling = ['inci' => ['OLEA EUROPAEA FRUIT OIL']];
    $restrictions = ['warnings' => []];

    $service = mock(RecipeWorkbenchService::class);
    $service->shouldReceive('previewSoapCalculation')
        ->once()
        ->with($draft, null)
        ->andReturn($calculation);
    $service->shouldReceive('previewInci')
        ->once()
        ->with($draft, $calculation)
        ->andReturn($labeling);
    $service->shouldReceive('previewRestrictions')
        ->once()
        ->with($draft, $calculation)
        ->andReturn($restrictions);

    $component = app(RecipeWorkbench::class);

    $calculationResponse = $component->previewCalculation($draft, $service);
    $labelingResponse = $component->previewLabeling($draft, $service);

    expect($calculationResponse)->toMatchArray([
        'ok' => true,
        'calculation' => $calculation,
        'labeling' => $labeling,
        'restrictions' => $restrictions,
    ])->and($labelingResponse)->toMatchArray([
        'ok' => true,
        'labeling' => $labeling,
        'restrictions' => $restrictions,
    ]);
});

it('returns a visible validation response for NaOH negative superfat previews', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $payload = workbenchSoapDraftPayload($ingredient, oilWeight: 1000);
    $payload['lye_type'] = 'naoh';
    $payload['superfat'] = -5;

    $calculationResult = $component->previewCalculation($payload, app(RecipeWorkbenchService::class));
    $labelingResult = $component->previewLabeling($payload, app(RecipeWorkbenchService::class));

    expect($calculationResult['ok'])->toBeFalse()
        ->and($calculationResult['message'])->toBe('Negative superfat is only supported for liquid or high-KOH soap workflows.')
        ->and($calculationResult['calculation'])->toBeNull()
        ->and($calculationResult['labeling'])->toBeNull()
        ->and($labelingResult['ok'])->toBeFalse()
        ->and($labelingResult['message'])->toBe('Negative superfat is only supported for liquid or high-KOH soap workflows.')
        ->and($labelingResult['labeling'])->toBeNull();
});

it('does not re-render the workbench when refreshing the calculation preview', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    mock(RecipeWorkbenchViewDataBuilder::class, function ($mock): void {
        $mock->shouldReceive('build')
            ->once()
            ->andReturn([
                'productFamily' => [
                    'id' => 1,
                    'name' => 'Soap',
                    'slug' => 'soap',
                    'calculation_basis' => null,
                ],
                'recipe' => null,
                'savedSnapshot' => null,
                'phases' => [],
                'ingredients' => [],
                'ifraProductCategories' => [],
                'defaultIfraProductCategoryId' => null,
                'costing' => [],
            ]);
    });

    Livewire::test(RecipeWorkbench::class)
        ->call('previewCalculation', workbenchSoapDraftPayload($ingredient, oilWeight: 1000));
});

it('stores formula context on recipe versions and returns it in the draft payload', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, exposureMode: 'leave_on'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $draft = $service->currentVersionPayload($recipe);
    $freshDraftVersion = $draftVersion->fresh();

    expect($freshDraftVersion)->not->toBeNull()
        ->and($freshDraftVersion?->manufacturing_mode)->toBe('saponify_in_formula')
        ->and($freshDraftVersion?->exposure_mode)->toBe('leave_on')
        ->and($freshDraftVersion?->regulatory_regime)->toBe('eu')
        ->and($freshDraftVersion?->catalog_reviewed_at)->not->toBeNull()
        ->and($draft['manufacturingMode'])->toBe('saponify_in_formula')
        ->and($draft['exposureMode'])->toBe('leave_on')
        ->and($draft['regulatoryRegime'])->toBe('eu')
        ->and($draft['catalogReview']['needs_review'])->toBeFalse();
});

it('preserves ingredient order across draft and saved versions', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeCarrierOilIngredient();
    $coconutOil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Coconut Oil',
        'inci_name' => 'COCOS NUCIFERA OIL',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    $spirulina = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'display_name' => 'Spirulina',
        'inci_name' => 'SPIRULINA PLATENSIS POWDER',
        'is_active' => true,
    ]);
    $oatMilk = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'display_name' => 'Oat Milk Powder',
        'inci_name' => 'AVENA SATIVA KERNEL FLOUR',
        'is_active' => true,
    ]);

    $payload = workbenchSoapDraftPayload($oliveOil, name: 'Ordered Formula');
    $payload['phase_items']['saponified_oils'] = [
        [
            'ingredient_id' => $coconutOil->id,
            'percentage' => 70,
            'weight' => 700,
            'note' => null,
        ],
        [
            'ingredient_id' => $oliveOil->id,
            'percentage' => 30,
            'weight' => 300,
            'note' => null,
        ],
    ];
    $payload['phase_items']['additives'] = [
        [
            'ingredient_id' => $oatMilk->id,
            'percentage' => 2,
            'weight' => 20,
            'note' => null,
        ],
        [
            'ingredient_id' => $spirulina->id,
            'percentage' => 1,
            'weight' => 10,
            'note' => null,
        ],
    ];

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion($user, $soapFamily, $payload, $recipe);

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();
    $publishedVersion->load([
        'phases' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order'),
        'phases.items' => fn ($query) => $query->withoutGlobalScopes()->orderBy('position'),
    ]);

    $currentVersionPayload = $service->currentVersionPayload($recipe);
    $publishedPayload = $service->versionPayload($recipe, $publishedVersion->id);

    expect(collect($currentVersionPayload['phaseItems']['saponified_oils'])->pluck('ingredient_id')->all())
        ->toBe([$coconutOil->id, $oliveOil->id])
        ->and(collect($currentVersionPayload['phaseItems']['additives'])->pluck('ingredient_id')->all())
        ->toBe([$oatMilk->id, $spirulina->id])
        ->and(collect($publishedPayload['phaseItems']['saponified_oils'])->pluck('ingredient_id')->all())
        ->toBe([$coconutOil->id, $oliveOil->id])
        ->and(collect($publishedPayload['phaseItems']['additives'])->pluck('ingredient_id')->all())
        ->toBe([$oatMilk->id, $spirulina->id])
        ->and($publishedVersion->phases->firstWhere('slug', 'saponified_oils')?->items->pluck('ingredient_id')->all())
        ->toBe([$coconutOil->id, $oliveOil->id])
        ->and($publishedVersion->phases->firstWhere('slug', 'additives')?->items->pluck('ingredient_id')->all())
        ->toBe([$oatMilk->id, $spirulina->id]);
});

it('keeps selected zero-quantity ingredients across draft and saved soap versions', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeCarrierOilIngredient();
    $coconutOil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Coconut Oil',
        'inci_name' => 'COCOS NUCIFERA OIL',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    $clay = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'display_name' => 'Green Clay',
        'inci_name' => 'ILLITE',
        'is_active' => true,
    ]);

    $payload = workbenchSoapDraftPayload($oliveOil, name: 'Zero Placeholder Formula');
    $payload['phase_items']['saponified_oils'] = [
        [
            'ingredient_id' => $oliveOil->id,
            'percentage' => 100,
            'weight' => 1000,
            'note' => null,
        ],
        [
            'ingredient_id' => $coconutOil->id,
            'percentage' => 0,
            'weight' => 0,
            'note' => null,
        ],
    ];
    $payload['phase_items']['additives'] = [
        [
            'ingredient_id' => $clay->id,
            'percentage' => 0,
            'weight' => 0,
            'note' => null,
        ],
    ];

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $currentVersionPayload = $service->currentVersionPayload($recipe);

    $savedVersion = $service->publish($user, $soapFamily, $payload, $recipe);
    $savedPayload = $service->versionPayload($recipe, $savedVersion->id);
    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();
    $phaseSections = app(RecipeVersionViewDataBuilder::class)
        ->build($recipe, $publishedVersion)['phaseSections'];

    $draftZeroOil = collect($currentVersionPayload['phaseItems']['saponified_oils'])
        ->firstWhere('ingredient_id', $coconutOil->id);
    $savedZeroAdditive = collect($savedPayload['phaseItems']['additives'])
        ->firstWhere('ingredient_id', $clay->id);
    $visibleSoapRows = collect($phaseSections)
        ->flatMap(fn (array $section): array => $section['rows'])
        ->pluck('name')
        ->all();

    expect(collect($currentVersionPayload['phaseItems']['saponified_oils'])->pluck('ingredient_id')->all())
        ->toBe([$oliveOil->id, $coconutOil->id])
        ->and(collect($currentVersionPayload['phaseItems']['additives'])->pluck('ingredient_id')->all())
        ->toBe([$clay->id])
        ->and(collect($savedPayload['phaseItems']['saponified_oils'])->pluck('ingredient_id')->all())
        ->toBe([$oliveOil->id, $coconutOil->id])
        ->and(collect($savedPayload['phaseItems']['additives'])->pluck('ingredient_id')->all())
        ->toBe([$clay->id])
        ->and($draftZeroOil['percentage'])->toBe(0.0)
        ->and($draftZeroOil['weight'])->toBe(0.0)
        ->and($savedZeroAdditive['percentage'])->toBe(0.0)
        ->and($savedZeroAdditive['weight'])->toBe(0.0)
        ->and($visibleSoapRows)->toBe(['Olive Oil']);
});

it('rejects inaccessible zero-quantity ingredients before saving soap formulas', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeCarrierOilIngredient();
    $privateOil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Other User Oil',
        'inci_name' => 'PRIVATE OIL',
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);

    $payload = workbenchSoapDraftPayload($oliveOil, name: 'Private Ingredient Probe');
    $payload['phase_items']['saponified_oils'][] = [
        'ingredient_id' => $privateOil->id,
        'percentage' => 0,
        'weight' => 0,
        'note' => null,
    ];

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $soapFamily, $payload))
        ->toThrow(ValidationException::class, 'One or more selected ingredients are no longer available.');
});

it('rejects negative soap formula row values before saving', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeCarrierOilIngredient();
    $coconutOil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Coconut Oil',
        'inci_name' => 'COCOS NUCIFERA OIL',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);

    $payload = workbenchSoapDraftPayload($oliveOil, name: 'Negative Soap Formula');
    $payload['phase_items']['saponified_oils'] = [
        [
            'ingredient_id' => $oliveOil->id,
            'percentage' => 110,
            'weight' => 1100,
            'note' => null,
        ],
        [
            'ingredient_id' => $coconutOil->id,
            'percentage' => -10,
            'weight' => -100,
            'note' => null,
        ],
    ];

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $soapFamily, $payload))
        ->toThrow(ValidationException::class, 'Formula percentages and weights must not be negative.');
});

it('flags a saved formula for review when linked ingredient data changes', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    expect($service->currentVersionPayload($recipe)['catalogReview']['needs_review'])->toBeFalse();

    $this->travel(1)->seconds();

    $ingredient->update([
        'display_name' => 'Updated Oil Name',
    ]);

    $updatedDraft = $service->currentVersionPayload($recipe);

    expect($updatedDraft['catalogReview']['needs_review'])->toBeTrue()
        ->and($updatedDraft['catalogReview']['message'])->toContain('Recheck INCI and compliance');
});

it('flags the catalog-backed initial payload when linked chemistry changes', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $sapProfile = IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    expect($service->currentVersionPayloadUsingCatalog($recipe, [])['catalogReview']['needs_review'])
        ->toBeFalse();

    $this->travel(1)->seconds();
    $sapProfile->update(['koh_sap_value' => 0.19]);

    expect($service->currentVersionPayloadUsingCatalog($recipe, [])['catalogReview']['needs_review'])
        ->toBeTrue();
});

it('loads a saved version for comparison', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Baseline Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $savedDraft = $service->saveAsNewVersion(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Published Formula'),
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->recipeId = $savedDraft->recipe_id;
    $component->mount($recipe);

    $result = $component->comparisonVersion(
        $publishedVersion->id,
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['snapshot']['draft']['formulaName'])->toBe('Published Formula')
        ->and($result['snapshot']['calculation'])->not->toBeNull();
});

it('saves recipe content through the standalone filament form', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_id' => $user->id,
        'workspace_id' => Workspace::factory()->create(['owner_user_id' => $user->id])->id,
    ]);
    $featuredImage = MediaAsset::factory()->ready()->create([
        'workspace_id' => $recipe->workspace_id,
        'original_filename' => 'Olive oil portrait.jpg',
    ]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', '<p>A calming creamy bar for daily cleansing.</p>')
        ->set('data.manufacturing_instructions', '<p>Blend the base gently, then pour into the mould.</p>')
        ->set('data.featured_media_asset_id', $featuredImage->id)
        ->call('saveRecipeContent')
        ->assertReturned(fn (array $response): bool => $response['ok'] === true
            && $response['message'] === __('workbench.instructions.all_saved')
            && is_string($response['saved_at'] ?? null))
        ->assertSet('recipeContentStatus', 'success');

    expect($recipe->fresh())
        ->description->toContain('calming creamy bar')
        ->manufacturing_instructions->toContain('Blend the base gently')
        ->featured_image_path->toBeNull()
        ->and(app(MediaAssetUsageService::class)->idsFor(
            $recipe,
            MediaAssetUsageRole::RecipeFeatured,
        ))->toBe([$featuredImage->id]);
});

it('syncs standalone instruction saves to the current version', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_id' => $user->id,
        'manufacturing_instructions' => '<p>Original procedure.</p>',
    ]);
    $currentVersion = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'is_current' => true,
        'manufacturing_instructions' => '<p>Original procedure.</p>',
    ]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.manufacturing_instructions', '<p>Updated procedure.</p>')
        ->call('saveRecipeContent')
        ->assertSet('recipeContentStatus', 'success');

    expect($recipe->fresh()->manufacturing_instructions)->toBe('<p>Updated procedure.</p>')
        ->and($currentVersion->fresh()->manufacturing_instructions)->toBe('<p>Updated procedure.</p>');
});

it('returns a structured recipe content error when no saved recipe exists', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class)
        ->call('saveRecipeContent')
        ->assertReturned([
            'ok' => false,
            'message' => __('workbench.instructions.draft_text_help'),
        ])
        ->assertSet('recipeContentStatus', 'error')
        ->assertSet('recipeContentMessage', __('workbench.instructions.draft_text_help'));
});

it('reports clipboard success and failure for ingredient list copies', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { copyText } from './resources/js/recipe-workbench/clipboard.js';

let copiedText = null;
Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: { clipboard: { writeText: async (value) => { copiedText = value; } } },
});

assert.equal(await copyText('INCI list'), true);
assert.equal(copiedText, 'INCI list');

Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: { clipboard: { writeText: async () => { throw new Error('blocked'); } } },
});

assert.equal(await copyText('plain list'), false);

let selectedText = null;
const fallbackTextArea = {
    value: '',
    style: {},
    setAttribute: () => {},
    select() { selectedText = this.value; },
    remove: () => {},
};

Object.defineProperty(globalThis, 'document', {
    configurable: true,
    value: {
        body: { appendChild: () => {} },
        createElement: () => fallbackTextArea,
        execCommand: (command) => command === 'copy',
    },
});

Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: {},
});

assert.equal(await copyText('HTTP fallback list'), true);
assert.equal(selectedText, 'HTTP fallback list');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('loads the shared classification prompt clipboard component in the admin panel', function (): void {
    $path = resource_path('js/filament/admin/classification-prompt.js');
    $source = file_exists($path) ? file_get_contents($path) : '';
    $view = file_get_contents(resource_path('views/filament/resources/ingredients/classification-prompt.blade.php'));

    expect(file_exists($path))->toBeTrue()
        ->and($source)->toContain("import { copyText } from '../../recipe-workbench/clipboard.js';")
        ->and($source)->toContain("document.addEventListener('click'")
        ->and($source)->toContain("closest('[data-ingredient-classification-copy]')")
        ->and($source)->not->toContain("document.addEventListener('alpine:init'")
        ->and($source)->not->toContain('window.Alpine.data')
        ->and($view)->toContain('data-ingredient-classification-helper')
        ->and($view)->toContain('data-ingredient-classification-copy')
        ->and($view)->toContain('data-ingredient-classification-prompt')
        ->and($view)->not->toContain('x-data="classificationPrompt"');
});

it('delegates admin classification prompt copy clicks to the shared clipboard utility', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs
    .readFileSync('resources/js/filament/admin/classification-prompt.js', 'utf8')
    .replace(
        "import { copyText } from '../../recipe-workbench/clipboard.js';",
        "const copyText = async (value) => { copiedValues.push(value); return true; };",
    );

const listeners = {};
const copiedValues = [];

class Element {}
class HTMLButtonElement extends Element {}
class HTMLTextAreaElement extends Element {}

globalThis.Element = Element;
globalThis.HTMLButtonElement = HTMLButtonElement;
globalThis.HTMLTextAreaElement = HTMLTextAreaElement;
globalThis.window = { setTimeout: (callback) => callback() };
globalThis.document = {
    addEventListener(eventName, callback) {
        listeners[eventName] = callback;
    },
};

eval(source);

assert.equal(typeof listeners.click, 'function');

const failure = {
    classList: {
        toggle(className, shouldHide) {
            assert.equal(className, 'hidden');
            assert.equal(shouldHide, true);
        },
    },
};
const prompt = new HTMLTextAreaElement();
prompt.value = 'Generated ingredient prompt';
const button = new HTMLButtonElement();
button.disabled = false;
button.dataset = {
    copyLabel: 'Copy prompt',
    copiedLabel: 'Copied',
};
button.textContent = 'Copy prompt';
button.isConnected = true;
button.closest = (selector) => {
    if (selector === '[data-ingredient-classification-copy]') {
        return button;
    }

    return {
        querySelector(query) {
            return query === '[data-ingredient-classification-prompt]' ? prompt : failure;
        },
    };
};

assert.equal(button instanceof Element, true);
assert.equal(button instanceof HTMLButtonElement, true);
assert.equal(prompt instanceof HTMLTextAreaElement, true);

await listeners.click({ target: button });

assert.deepEqual(copiedValues, ['Generated ingredient prompt']);
assert.equal(button.textContent, 'Copy prompt');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('composes one translated root navigation guard from formula and nested dirty state', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs
    .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
    .replace(/^import[\s\S]*?;\n/gm, '')
    .replace(/export function /g, 'function ');

eval(`${source}\nglobalThis.createPersistenceSection = createPersistenceSection;`);

const windowListeners = new Map();
const documentListeners = new Map();
let translatedKey = null;
let confirmationMessage = null;

globalThis.window = {
    addEventListener(eventName, callback) {
        windowListeners.set(eventName, [...(windowListeners.get(eventName) ?? []), callback]);
    },
    removeEventListener(eventName, callback) {
        windowListeners.set(eventName, (windowListeners.get(eventName) ?? []).filter((listener) => listener !== callback));
    },
    confirm(message) {
        confirmationMessage = message;

        return false;
    },
};

globalThis.document = {
    addEventListener(eventName, callback) {
        documentListeners.set(eventName, [...(documentListeners.get(eventName) ?? []), callback]);
    },
    removeEventListener(eventName, callback) {
        documentListeners.set(eventName, (documentListeners.get(eventName) ?? []).filter((listener) => listener !== callback));
    },
};

const section = globalThis.createPersistenceSection();
const registry = {
    blocked: false,
    blocksNavigation() {
        return this.blocked;
    },
};
const workbench = {
    ...section,
    formulaDirty: false,
    isSaving: false,
    saveStatus: null,
    dirtyStateRegistry: registry,
    unsavedBeforeUnloadHandler: null,
    unsavedNavigateHandler: null,
    hasUnsavedWorkbenchChanges() {
        return this.formulaDirty;
    },
    t(key) {
        translatedKey = key;

        return 'Translated leave warning';
    },
};

assert.equal(workbench.blocksNavigation(), false);

workbench.formulaDirty = true;
assert.equal(workbench.blocksNavigation(), true);
workbench.formulaDirty = false;

workbench.isSaving = true;
assert.equal(workbench.blocksNavigation(), true);
workbench.isSaving = false;

workbench.saveStatus = 'error';
assert.equal(workbench.blocksNavigation(), true);
workbench.saveStatus = null;

registry.blocked = true;
assert.equal(workbench.blocksNavigation(), true);

workbench.installUnsavedChangesGuard();
workbench.installUnsavedChangesGuard();

assert.equal(windowListeners.get('beforeunload').length, 1);
assert.equal(documentListeners.get('livewire:navigate').length, 1);

let prevented = false;
documentListeners.get('livewire:navigate')[0]({
    preventDefault() {
        prevented = true;
    },
});

assert.equal(prevented, true);
assert.equal(translatedKey, 'instructions.leave_warning');
assert.equal(confirmationMessage, 'Translated leave warning');

workbench.removeUnsavedChangesGuard();
workbench.removeUnsavedChangesGuard();

assert.equal(windowListeners.get('beforeunload').length, 0);
assert.equal(documentListeners.get('livewire:navigate').length, 0);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('clears recipe content blocking after every successful workbench save', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs
    .readFileSync('resources/js/recipe-workbench/bridge.js', 'utf8')
    .replace(/^import[\s\S]*?;\n/gm, '')
    .replace(/export async function /g, 'async function ');
const serializeDraft = () => ({});
const serializeCosting = () => ({});

eval(`${source}\nglobalThis.persistWorkbench = persistWorkbench;`);

const navigations = [];
globalThis.window = {
    Livewire: {
        navigate(target) {
            navigations.push(target);
        },
    },
    location: {
        assign(target) {
            throw new Error(`Unexpected hard navigation to ${target}`);
        },
    },
};

function makeWorkbench(recipeId, activeWorkbenchTab, redirect) {
    const registryWrites = [];

    return {
        recipeId,
        activeWorkbenchTab,
        formulaName: 'Formula',
        oilUnit: 'g',
        oilWeight: 1000,
        phaseItems: {},
        phaseOrder: [],
        packagingPlanRows: [],
        dirtyStateRegistry: {
            set(key, state) {
                registryWrites.push([key, state]);
            },
        },
        registryWrites,
        $wire: {
            save: async () => ({ ok: true, message: 'Saved', redirect }),
        },
        applySnapshot() {},
        refreshDirtyBaseline() {},
    };
}

const firstSave = makeWorkbench(null, 'instructions', '/recipes/first');
await globalThis.persistWorkbench(firstSave, 'save');

assert.deepEqual(firstSave.registryWrites, [['recipe-content', 'saved']]);
assert.equal(firstSave.isSaving, false);
assert.equal(navigations[0], '/recipes/first#instructions');

const existingSave = makeWorkbench(42, 'output', '/recipes/existing');
await globalThis.persistWorkbench(existingSave, 'save');

    assert.deepEqual(existingSave.registryWrites, [['recipe-content', 'saved']]);
assert.equal(existingSave.isSaving, false);
assert.equal(navigations[1], '/recipes/existing#output');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('returns the saved packaging item payload when saving a packaging catalog item', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->savePackagingCatalogItem(
        [
            'name' => 'Amber Jar',
            'unit_cost' => 1.2345,
            'currency' => 'EUR',
            'notes' => 'For 100 g bars',
        ],
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['packaging_catalog'])->toHaveCount(1)
        ->and($result['packaging_item'])->toMatchArray([
            'name' => 'Amber Jar',
            'unit_cost' => 1.2345,
            'currency' => 'EUR',
            'notes' => 'For 100 g bars',
        ])
        ->and($result['packaging_item']['id'])->toBeInt();

    $packagingItem = PackagingItem::query()
        ->where('workspace_id', $user->fresh()->active_workspace_id)
        ->where('name', 'Amber Jar')
        ->firstOrFail();

    expect($packagingItem->unit_cost)->toBe('1.234500000000');
});

it('serializes packaging plan row positions with the draft payload', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/payload.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace(/export function /g, 'function ');

const normalizedIfraProductCategoryId = (value) => value;
const rowWeight = () => 0;
const number = (value) => Number.isFinite(Number(value)) ? Number(value) : 0;
const nonNegativeNumber = (value) => Number.isFinite(Number(value)) && Number(value) > 0 ? Number(value) : 0;

eval(`${source}\nglobalThis.serializeDraft = serializeDraft;`);

const payload = globalThis.serializeDraft({
  formulaName: 'Positioned packaging',
  oilUnit: 'g',
  oilWeight: 1000,
  phaseOrder: [],
  phaseItems: {},
  packagingPlanRows: [
    { id: 'box', packaging_item_id: 11, name: 'Box', components_per_unit: 1, notes: null },
    { id: 'label', packaging_item_id: 12, name: 'Label', components_per_unit: 2, notes: 'Front and back' },
  ],
});

console.log(JSON.stringify(payload.packaging_items));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $rows = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['position'])->toBe(1)
        ->and($rows[1]['position'])->toBe(2);
});

it('serializes and restores finished product identity fields in the browser draft', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const payloadSource = fs
  .readFileSync('resources/js/recipe-workbench/payload.js', 'utf8')
  .replace(/^import[^;]+;\n/gm, '')
  .replace(/export function /g, 'function ');
const snapshotSource = fs
  .readFileSync('resources/js/recipe-workbench/snapshot.js', 'utf8')
  .replace(/^import[^;]+;\n/gm, '')
  .replace(/export function /g, 'function ');

const normalizedIfraProductCategoryId = (value) => value;
const rowWeight = () => 0;
const number = (value) => Number.isFinite(Number(value)) ? Number(value) : 0;
const nonNegativeNumber = (value) => Number.isFinite(Number(value)) && Number(value) > 0 ? Number(value) : 0;

eval(`${payloadSource}\n${snapshotSource}\nglobalThis.serializeDraft = serializeDraft; globalThis.draftStateFromDraft = draftStateFromDraft;`);

const serialized = globalThis.serializeDraft({
  formulaName: 'Lavender soap 100 g',
  oilUnit: 'g',
  oilWeight: 1000,
  phaseOrder: [],
  phaseItems: {},
  packagingPlanRows: [],
  productionOutputType: 'finished_product',
  outputIngredientId: '',
  readyDelayDays: '',
  productReference: 'LAV-100',
  nominalContentValue: 100,
  nominalContentUnit: 'g',
});

const restored = globalThis.draftStateFromDraft({
  productReference: 'LAV-100',
  nominalContentValue: 100,
  nominalContentUnit: 'g',
}, {
  phaseOrder: [],
  packagingPlanRows: [],
  productionOutputType: 'finished_product',
  outputIngredientId: '',
  readyDelayDays: '',
  productReference: '',
  nominalContentValue: '',
  nominalContentUnit: '',
});

console.log(JSON.stringify({ serialized, restored }));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['serialized'])->toMatchArray([
        'product_reference' => 'LAV-100',
        'nominal_content_value' => 100,
        'nominal_content_unit' => 'g',
    ])->and($payload['restored'])->toMatchArray([
        'productReference' => 'LAV-100',
        'nominalContentValue' => 100,
        'nominalContentUnit' => 'g',
    ]);
});

it('hydrates saved draft packaging rows into the workbench state', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/snapshot.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace(/export function /g, 'function ');

const number = (value) => Number(value ?? 0);

eval(`${source}\nglobalThis.draftStateFromDraft = draftStateFromDraft;`);

const state = globalThis.draftStateFromDraft({
  phases: [{ key: 'phase_a', name: 'Phase A' }],
  phaseItems: { phase_a: [] },
  packagingItems: [
    {
      id: 'saved-packaging-14',
      packaging_item_id: 14,
      name: 'Amber Jar',
      components_per_unit: 1,
      notes: 'Primary pack',
    },
  ],
}, {
  recipeId: 10,
  currentVersionId: 20,
  currentVersionNumber: null,
  currentVersionIsDraft: true,
  productTypeId: null,
  formulaName: 'Draft',
  oilUnit: 'g',
  oilWeight: 100,
  manufacturingMode: 'blend_only',
  exposureMode: 'leave_on',
  regulatoryRegime: 'eu',
  editMode: 'percentage',
  lyeType: 'naoh',
  kohPurity: 90,
  dualKohPercentage: 40,
  waterMode: 'percent_of_oils',
  waterValue: 38,
  superfat: 5,
  phaseOrder: [{ key: 'phase_a', name: 'Phase A' }],
  packagingPlanRows: [],
  catalogReview: null,
});

console.log(JSON.stringify({
  packagingPlanRows: state.packagingPlanRows ?? [],
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['packagingPlanRows'])->toHaveCount(1)
        ->and($payload['packagingPlanRows'][0])->toMatchArray([
            'id' => 'saved-packaging-14',
            'packaging_item_id' => 14,
            'name' => 'Amber Jar',
            'components_per_unit' => 1,
            'notes' => 'Primary pack',
        ]);
});

it('does not expose legacy packaging costing structure helpers', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const state = {
  costingUnitsProduced: 12,
  costingSaveTimer: null,
  packagingCostRows: [],
  scheduleCostingSave() {},
  makeLocalPackagingRowId() {
    return 'row-1';
  },
};

const source = fs
  .readFileSync('resources/js/recipe-workbench/sections/costing-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createCostingSection', 'function createCostingSection');

globalThis.createCostingSection = undefined;
eval(`${source}\nglobalThis.createCostingSection = createCostingSection;`);

Object.defineProperties(state, Object.getOwnPropertyDescriptors(createCostingSection({})));

console.log(JSON.stringify({
  hasAddPackagingCostRow: typeof state.addPackagingCostRow === 'function',
  hasRemovePackagingCostRow: typeof state.removePackagingCostRow === 'function',
  hasSaveAndAddToCosting: typeof state.savePackagingCatalogItemAndAddToCosting === 'function',
  hasUnusedPackagingCatalogItems: Object.prototype.hasOwnProperty.call(Object.getOwnPropertyDescriptors(createCostingSection({})), 'unusedPackagingCatalogItems'),
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['hasAddPackagingCostRow'])->toBeFalse()
        ->and($payload['hasRemovePackagingCostRow'])->toBeFalse()
        ->and($payload['hasSaveAndAddToCosting'])->toBeFalse()
        ->and($payload['hasUnusedPackagingCatalogItems'])->toBeFalse();
});

it('does not load costing when the packaging tab is opened', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const buildCategoryOptions = () => [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const refreshWorkbenchLabelingPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({
  addIngredient() {},
});
const createPackagingSection = () => ({});
const createCostingSection = () => ({
  initializeCostingState() {},
  ensureCostingLoaded() {
    this.ensureCostingLoadedCalls = (this.ensureCostingLoadedCalls ?? 0) + 1;
  },
  resetPackagingCatalogForm() {},
  reconcileCostingPrices() {},
});
const createPresentationSection = () => ({
  syncIngredientListVariantSelection() {},
});
const createVersionSection = () => ({});
`;

globalThis.window = {
  location: { hash: '' },
  addEventListener() {},
  removeEventListener() {},
};
globalThis.document = {
  addEventListener() {},
  removeEventListener() {},
};

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const watchers = {};
const workbench = globalThis.createRecipeWorkbench({
  phases: [{ key: 'saponified_oils', name: 'Saponified Oils' }],
  ingredients: [],
  recipe: { id: 5, current_version_id: 8 },
});

workbench.$watch = (key, callback) => {
  watchers[key] = callback;
};

workbench.init();
workbench.activeWorkbenchTab = 'packaging';
watchers.activeWorkbenchTab?.('packaging');

console.log(JSON.stringify({
  ensureCostingLoadedCalls: workbench.ensureCostingLoadedCalls ?? 0,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['ensureCostingLoadedCalls'])->toBe(0);
});

it('seeds the packaging catalog from the initial workbench payload', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const buildCategoryOptions = () => [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const refreshWorkbenchLabelingPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({
  initializeCostingState() {},
  ensureCostingLoaded() {},
  resetPackagingCatalogForm() {},
  reconcileCostingPrices() {},
});
const createPresentationSection = () => ({
  syncIngredientListVariantSelection() {},
});
const createVersionSection = () => ({});
`;

globalThis.window = { location: { hash: '' } };

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [],
  packagingCatalog: [
    {
      id: 44,
      name: 'Amber Jar',
      unit_cost: 0.82,
      currency: 'EUR',
      notes: 'Reusable catalog item',
    },
  ],
  defaultCurrency: 'EUR',
});

console.log(JSON.stringify({
  packagingCatalogCount: workbench.packagingCatalog.length,
  firstPackagingItem: workbench.packagingCatalog[0] ?? null,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['packagingCatalogCount'])->toBe(1)
        ->and($payload['firstPackagingItem'])->toMatchArray([
            'id' => 44,
            'name' => 'Amber Jar',
            'unit_cost' => 0.82,
            'currency' => 'EUR',
            'notes' => 'Reusable catalog item',
        ]);
});

it('filters reusable combobox options by labels and descriptions', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/search-combobox.js', 'utf8')
  .replace('export function createSearchCombobox', 'function createSearchCombobox');

globalThis.createSearchCombobox = undefined;
eval(`${source}\nglobalThis.createSearchCombobox = createSearchCombobox;`);

const state = createSearchCombobox({
  id: 'packaging',
  options: [
    { id: 1, label: 'Amber Jar', description: 'Glass' },
    { id: 2, label: 'Wrap Label', description: 'Paper band' },
    { id: 3, label: 'Lid', description: 'Jar closure' },
  ],
});
state.query = 'jar';

console.log(JSON.stringify({
  filteredIds: state.filteredOptions.map((item) => item.id),
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['filteredIds'])->toBe([1, 3]);
});

it('includes the user packaging catalog on the rendered workbench component', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Amber Jar',
        'unit_cost' => 0.82,
        'currency' => 'EUR',
        'notes' => 'Reusable catalog item',
    ]);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $workbench = $component->render(app(RecipeWorkbenchService::class))->getData()['workbench'];

    expect($workbench['packagingCatalog'])->toHaveCount(1)
        ->and($workbench['packagingCatalog'][0])->toMatchArray([
            'name' => 'Amber Jar',
            'unit_cost' => 0.82,
            'currency' => 'EUR',
            'notes' => 'Reusable catalog item',
        ]);
});

it('can save a packaging catalog item and add it to the packaging plan', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const state = {
  costingCurrency: 'EUR',
  packagingCatalog: [],
  packagingPlanRows: [],
  packagingCostRows: [],
  packagingCatalogStatus: null,
  packagingCatalogMessage: '',
  packagingCatalogForm: {
    id: null,
    name: 'Amber Jar',
    unit_cost: 1.2345,
    currency: 'EUR',
    notes: 'For boxed bars',
  },
  hasSavedRecipe: true,
  costingSaveTimer: null,
  scheduleCostingSave() {},
  makeLocalPackagingRowId() {
    return `row-${this.packagingCostRows.length + 1}`;
  },
  resetPackagingCatalogForm() {
    this.packagingCatalogForm = {
      id: null,
      name: '',
      unit_cost: '',
      currency: this.costingCurrency ?? 'EUR',
      notes: '',
    };
  },
  $wire: {
    async savePackagingCatalogItem(payload) {
      return {
        ok: true,
        message: 'Packaging item saved.',
        packaging_catalog: [
          {
            id: 41,
            name: payload.name,
            unit_cost: payload.unit_cost,
            currency: payload.currency,
            notes: payload.notes,
          },
        ],
        packaging_item: {
          id: 41,
          name: payload.name,
          unit_cost: payload.unit_cost,
          currency: payload.currency,
          notes: payload.notes,
        },
      };
    },
  },
};

const bridgeSource = fs
  .readFileSync('resources/js/recipe-workbench/bridge.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace(/export async function /g, 'async function ');

const costingSource = fs
  .readFileSync('resources/js/recipe-workbench/sections/costing-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createCostingSection', 'function createCostingSection');

const packagingSource = fs
  .readFileSync('resources/js/recipe-workbench/sections/packaging-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createPackagingSection', 'function createPackagingSection');

globalThis.persistPackagingCatalogItem = undefined;
globalThis.persistCosting = async () => {};
globalThis.createCostingSection = undefined;
globalThis.createPackagingSection = undefined;
eval(`${bridgeSource}\nglobalThis.persistPackagingCatalogItem = persistPackagingCatalogItem;`);
eval(`const persistCosting = globalThis.persistCosting;\n${costingSource}\nglobalThis.createCostingSection = createCostingSection;`);
eval(`${packagingSource}\nglobalThis.createPackagingSection = createPackagingSection;`);

Object.defineProperties(state, Object.getOwnPropertyDescriptors(createPackagingSection()));
Object.defineProperties(state, Object.getOwnPropertyDescriptors(createCostingSection({})));

await state.savePackagingCatalogItemAndAdd();

console.log(JSON.stringify({
  packagingCatalogCount: state.packagingCatalog.length,
  packagingCatalogStatus: state.packagingCatalogStatus,
  packagingCatalogMessage: state.packagingCatalogMessage,
  packagingCatalogModalOpen: state.packagingCatalogModalOpen,
  row: state.packagingPlanRows[0] ?? null,
  costingRows: state.packagingCostRows.length,
  form: state.packagingCatalogForm,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['packagingCatalogCount'])->toBe(1)
        ->and($payload['packagingCatalogStatus'])->toBe('success')
        ->and($payload['packagingCatalogMessage'])->toBe('Packaging item saved.')
        ->and($payload['packagingCatalogModalOpen'])->toBeFalse()
        ->and($payload['row'])->toMatchArray([
            'packaging_item_id' => 41,
            'name' => 'Amber Jar',
            'components_per_unit' => 1,
            'notes' => '',
        ])
        ->and($payload['costingRows'])->toBe(0)
        ->and($payload['form'])->toMatchArray([
            'id' => null,
            'name' => '',
            'unit_cost' => '',
            'currency' => 'EUR',
            'notes' => '',
        ]);
});

it('adds a saved catalog item to the packaging plan with one component per unit', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const state = {
  packagingPlanRows: [],
  makeLocalPackagingPlanRowId() {
    return `row-${this.packagingPlanRows.length + 1}`;
  },
};

const source = fs
  .readFileSync('resources/js/recipe-workbench/sections/packaging-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createPackagingSection', 'function createPackagingSection');

globalThis.createPackagingSection = undefined;
eval(`${source}\nglobalThis.createPackagingSection = createPackagingSection;`);

Object.defineProperties(state, Object.getOwnPropertyDescriptors(createPackagingSection()));

state.addPackagingPlanRow({
  id: 91,
  name: 'Soap box',
  unit_cost: 0.42,
});

console.log(JSON.stringify({
  row: state.packagingPlanRows[0] ?? null,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['row'])->toMatchArray([
        'packaging_item_id' => 91,
        'name' => 'Soap box',
        'components_per_unit' => 1,
        'notes' => '',
    ]);
});

it('allows lipids to move between soap oils and additives', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const buildCategoryOptions = () => [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createPresentationSection = () => ({});
const createVersionSection = () => ({});
`;

globalThis.window = { location: { hash: '' } };

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [
    {
      id: 1,
      name: 'Olive Oil',
      inci_name: 'OLEA EUROPAEA FRUIT OIL',
      category: 'lipids',
      available_phases: ['saponified_oils', 'additives'],
      can_add_to_saponified_oils: true,
      can_add_to_additives: true,
    },
  ],
});

workbench.phaseItems = {
  saponified_oils: [
    {
      id: 'oil-1',
      ingredient_id: 1,
      name: 'Olive Oil',
      category: 'lipids',
      available_phases: ['saponified_oils', 'additives'],
      can_add_to_saponified_oils: true,
      koh_sap_value: 0.188,
    },
  ],
  additives: [],
  fragrance: [],
};

const event = {
  preventDefault() {},
  dataTransfer: {
    effectAllowed: '',
    dropEffect: '',
    setData() {},
  },
};

workbench.beginRowDrag('saponified_oils', 'oil-1', event);

const canDropIntoAdditives = workbench.canDropRowInPhase('additives');

workbench.dropDraggedRow('additives', event);

console.log(JSON.stringify({
  canDropIntoAdditives,
  oilCount: workbench.phaseItems.saponified_oils.length,
  additiveCount: workbench.phaseItems.additives.length,
  additiveRowId: workbench.phaseItems.additives[0]?.id ?? null,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['canDropIntoAdditives'])->toBeTrue()
        ->and($payload['oilCount'])->toBe(0)
        ->and($payload['additiveCount'])->toBe(1)
        ->and($payload['additiveRowId'])->toBe('oil-1');
});

it('prevents additive-only carrier oils from moving into saponified oils', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const buildCategoryOptions = () => [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createPresentationSection = () => ({});
const createVersionSection = () => ({});
`;

globalThis.window = { location: { hash: '' } };
globalThis.document = { getElementById: () => null };

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const additiveOnlyOil = {
  id: 2,
  name: 'Unsaponifiable Carrier',
  inci_name: 'UNSAPONIFIABLE CARRIER OIL',
  category: 'lipids',
  available_phases: ['additives'],
  can_add_to_saponified_oils: false,
  can_add_to_additives: true,
  koh_sap_value: null,
  naoh_sap_value: null,
};
const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [additiveOnlyOil],
});

workbench.addIngredient(additiveOnlyOil, 'additives', false);

const additiveRow = workbench.phaseItems.additives[0];
const event = {
  preventDefault() {},
  dataTransfer: {
    effectAllowed: '',
    dropEffect: '',
    setData() {},
  },
};

workbench.beginRowDrag('additives', additiveRow.id, event);

const canDropIntoSaponifiedOils = workbench.canDropRowInPhase('saponified_oils');

workbench.dropDraggedRow('saponified_oils', event);

console.log(JSON.stringify({
  canDropIntoSaponifiedOils,
  copiedAvailablePhases: additiveRow.available_phases,
  copiedCanAddToSaponifiedOils: additiveRow.can_add_to_saponified_oils,
  oilCount: workbench.phaseItems.saponified_oils.length,
  additiveCount: workbench.phaseItems.additives.length,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['canDropIntoSaponifiedOils'])->toBeFalse()
        ->and($payload['copiedAvailablePhases'])->toBe(['additives'])
        ->and($payload['copiedCanAddToSaponifiedOils'])->toBeFalse()
        ->and($payload['oilCount'])->toBe(0)
        ->and($payload['additiveCount'])->toBe(1);
});

it('auto-scrolls the page near viewport edges while dragging formula rows', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const buildCategoryOptions = () => [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createPresentationSection = () => ({});
const createVersionSection = () => ({});
`;

const scrollCalls = [];

globalThis.document = { documentElement: { clientHeight: 600 } };
globalThis.window = {
  innerHeight: 600,
  location: { hash: '' },
  scrollBy(options) {
    scrollCalls.push(options);
  },
};

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [],
});

workbench.autoScrollDuringRowDrag({ clientY: 590 });

workbench.beginRowDrag('saponified_oils', 'oil-1', {
  dataTransfer: {
    effectAllowed: '',
    setData() {},
  },
});

workbench.autoScrollDuringRowDrag({ clientY: 590 });
workbench.autoScrollDuringRowDrag({ clientY: 10 });
workbench.autoScrollDuringRowDrag({ clientY: 300 });
workbench.endRowDrag();
workbench.autoScrollDuringRowDrag({ clientY: 590 });

console.log(JSON.stringify({
  calls: scrollCalls,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['calls'])->toHaveCount(2)
        ->and($payload['calls'][0]['top'])->toBeGreaterThan(0)
        ->and($payload['calls'][0]['behavior'])->toBe('auto')
        ->and($payload['calls'][1]['top'])->toBeLessThan(0)
        ->and($payload['calls'][1]['behavior'])->toBe('auto');
});

it('still allows reordering rows within the same phase', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const buildCategoryOptions = () => [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createPresentationSection = () => ({});
const createVersionSection = () => ({});
`;

globalThis.window = { location: { hash: '' } };

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [
    { id: 1, available_phases: ['saponified_oils'], can_add_to_saponified_oils: true },
    { id: 2, available_phases: ['saponified_oils'], can_add_to_saponified_oils: true },
  ],
});

workbench.phaseItems = {
  saponified_oils: [
    { id: 'oil-1', ingredient_id: 1, name: 'Olive Oil' },
    { id: 'oil-2', ingredient_id: 2, name: 'Coconut Oil' },
  ],
  additives: [],
  fragrance: [],
};

const event = {
  preventDefault() {},
  dataTransfer: {
    effectAllowed: '',
    dropEffect: '',
    setData() {},
  },
};

workbench.beginRowDrag('saponified_oils', 'oil-1', event);
workbench.dropDraggedRow('saponified_oils', event);

console.log(JSON.stringify({
  oilIds: workbench.phaseItems.saponified_oils.map((row) => row.id),
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['oilIds'])->toBe(['oil-2', 'oil-1']);
});

it('only schedules the soap calculation preview when reaction-core rows change', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const buildCategoryOptions = () => [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createPresentationSection = () => ({});
const createVersionSection = () => ({});
`;

globalThis.window = { location: { hash: '' } };

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [],
});

workbench.phaseItems = {
  saponified_oils: [
    { id: 'oil-1', ingredient_id: 1, percentage: 100 },
  ],
  additives: [],
  fragrance: [],
};

let calculationSchedules = 0;
let labelingSchedules = 0;

workbench.scheduleCalculationPreview = () => {
  calculationSchedules += 1;
};

workbench.scheduleLabelingPreview = () => {
  labelingSchedules += 1;
};

workbench.lastCalculationPhaseSignature = workbench.currentCalculationPhaseSignature();
workbench.phaseItems.fragrance.push({ id: 'frag-1', ingredient_id: 9, percentage: 2 });
workbench.schedulePhaseItemPreviews();

console.log(JSON.stringify({
  calculationSchedules,
  labelingSchedules,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['calculationSchedules'])->toBe(0)
        ->and($payload['labelingSchedules'])->toBe(1);
});

it('uses the calculation response for labeling when reaction-core rows change', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const buildCategoryOptions = () => [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createPresentationSection = () => ({});
const createVersionSection = () => ({});
`;

globalThis.window = { location: { hash: '' } };

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [],
});

workbench.phaseItems = {
  saponified_oils: [
    { id: 'oil-1', ingredient_id: 1, percentage: 100 },
  ],
  additives: [],
  fragrance: [],
};

let calculationSchedules = 0;
let labelingSchedules = 0;

workbench.scheduleCalculationPreview = () => {
  calculationSchedules += 1;
};

workbench.scheduleLabelingPreview = () => {
  labelingSchedules += 1;
};

workbench.lastCalculationPhaseSignature = workbench.currentCalculationPhaseSignature();
workbench.phaseItems.saponified_oils[0].percentage = 85;
workbench.schedulePhaseItemPreviews();

console.log(JSON.stringify({
  calculationSchedules,
  labelingSchedules,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['calculationSchedules'])->toBe(1)
        ->and($payload['labelingSchedules'])->toBe(0);
});

it('coalesces overlapping calculation and labeling preview timers', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const buildCategoryOptions = () => [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = () => null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async (workbench) => {
  workbench.calculationRequests += 1;
  workbench.isPreviewingCalculation = false;
  workbench.calculationPreviewTimer = null;
};
const refreshWorkbenchLabelingPreview = async (workbench) => {
  workbench.labelingRequests += 1;
  workbench.labelingPreviewTimer = null;
};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createPresentationSection = () => ({});
const createVersionSection = () => ({});
`;

globalThis.window = { location: { hash: '' } };

let nextTimerId = 1;
const timers = new Map();
globalThis.setTimeout = (callback) => {
  const timerId = nextTimerId++;
  timers.set(timerId, callback);

  return timerId;
};
globalThis.clearTimeout = (timerId) => timers.delete(timerId);

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({ phases: [], ingredients: [] });
workbench.calculationRequests = 0;
workbench.labelingRequests = 0;

workbench.scheduleLabelingPreview();
workbench.scheduleCalculationPreview();
workbench.scheduleLabelingPreview();

const pendingTimerCount = timers.size;
const [runPendingTimer] = timers.values();
await runPendingTimer();
timers.clear();

workbench.isPreviewingCalculation = true;
workbench.scheduleLabelingPreview();
const timersDuringCalculationRequest = timers.size;
const calculationQueuedDuringCalculation = workbench.calculationPreviewPending;

workbench.isPreviewingCalculation = false;
workbench.releasePendingPreview();
const timersAfterCalculationRequest = timers.size;
const [runQueuedCalculationTimer] = timers.values();
await runQueuedCalculationTimer();

console.log(JSON.stringify({
  pendingTimerCount,
  calculationRequests: workbench.calculationRequests,
  labelingRequests: workbench.labelingRequests,
  timersDuringCalculationRequest,
  calculationQueuedDuringCalculation,
  timersAfterCalculationRequest,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'pendingTimerCount' => 1,
        'calculationRequests' => 2,
        'labelingRequests' => 0,
        'timersDuringCalculationRequest' => 0,
        'calculationQueuedDuringCalculation' => true,
        'timersAfterCalculationRequest' => 1,
    ]);
});

it('keeps the save-only packaging success message visible after closing the modal', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const state = {
  costingCurrency: 'EUR',
  packagingCatalog: [],
  packagingCostRows: [],
  packagingCatalogStatus: null,
  packagingCatalogMessage: '',
  packagingCatalogForm: {
    id: null,
    name: 'Amber Jar',
    unit_cost: 1.2345,
    currency: 'EUR',
    notes: 'For boxed bars',
  },
  hasSavedRecipe: true,
  costingSaveTimer: null,
  scheduleCostingSave() {},
  makeLocalPackagingRowId() {
    return `row-${this.packagingCostRows.length + 1}`;
  },
  resetPackagingCatalogForm() {
    this.packagingCatalogForm = {
      id: null,
      name: '',
      unit_cost: '',
      currency: this.costingCurrency ?? 'EUR',
      notes: '',
    };
  },
  $wire: {
    async savePackagingCatalogItem(payload) {
      return {
        ok: true,
        message: 'Packaging item saved.',
        packaging_catalog: [
          {
            id: 41,
            name: payload.name,
            unit_cost: payload.unit_cost,
            currency: payload.currency,
            notes: payload.notes,
          },
        ],
        packaging_item: {
          id: 41,
          name: payload.name,
          unit_cost: payload.unit_cost,
          currency: payload.currency,
          notes: payload.notes,
        },
      };
    },
  },
};

const bridgeSource = fs
  .readFileSync('resources/js/recipe-workbench/bridge.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace(/export async function /g, 'async function ');

const costingSource = fs
  .readFileSync('resources/js/recipe-workbench/sections/costing-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createCostingSection', 'function createCostingSection');

globalThis.persistPackagingCatalogItem = undefined;
globalThis.persistCosting = async () => {};
globalThis.createCostingSection = undefined;
eval(`${bridgeSource}\nglobalThis.persistPackagingCatalogItem = persistPackagingCatalogItem;`);
eval(`const persistCosting = globalThis.persistCosting;\n${costingSource}\nglobalThis.createCostingSection = createCostingSection;`);

Object.defineProperties(state, Object.getOwnPropertyDescriptors(createCostingSection({})));

await state.savePackagingCatalogItemOnly();

console.log(JSON.stringify({
  packagingCatalogCount: state.packagingCatalog.length,
  packagingCatalogStatus: state.packagingCatalogStatus,
  packagingCatalogMessage: state.packagingCatalogMessage,
  packagingCatalogModalOpen: state.packagingCatalogModalOpen,
  form: state.packagingCatalogForm,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['packagingCatalogCount'])->toBe(1)
        ->and($payload['packagingCatalogStatus'])->toBe('success')
        ->and($payload['packagingCatalogMessage'])->toBe('Packaging item saved.')
        ->and($payload['packagingCatalogModalOpen'])->toBeFalse()
        ->and($payload['form'])->toMatchArray([
            'id' => null,
            'name' => '',
            'unit_cost' => '',
            'currency' => 'EUR',
            'notes' => '',
        ]);
});

it('localizes soap costing phase labels while preserving cosmetic phase names', function () {
    $script = <<<'JS'
import fs from 'node:fs';

globalThis.rowWeightForOilWeight = () => 100;
globalThis.nonNegativeNumber = (value) => Number(value) || 0;
globalThis.number = (value) => Number(value) || 0;
globalThis.parseDecimalInput = (value) => Number(value) || 0;
globalThis.roundTo = (value) => value;
globalThis.formatDecimalInput = (value) => String(value);
globalThis.persistCosting = async () => {};
globalThis.persistPackagingCatalogItem = async () => null;

const costingSource = fs
  .readFileSync('resources/js/recipe-workbench/sections/costing-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createCostingSection', 'function createCostingSection');

eval(`${costingSource}\nglobalThis.createCostingSection = createCostingSection;`);

function costingState({ isCosmeticFormula, phaseKey, phaseName }) {
  const state = {
    isCosmeticFormula,
    phaseOrder: [{ key: phaseKey, name: phaseName }],
    phaseItems: {
      [phaseKey]: [{ id: 1, ingredient_id: 2, name: 'Olive Oil', percentage: 100 }],
    },
    oilWeight: 100,
    oilUnit: 'g',
    costingOilWeight: 100,
    costingOilUnit: 'g',
    costingPriceByRowId: {},
    ingredientForRow() {
      return null;
    },
    t(path) {
      return {
        'costing.phases.saponification': 'Localized saponification',
        'costing.phases.additions': 'Localized additions',
        'costing.phases.fragrance': 'Localized fragrance',
      }[path] ?? path;
    },
  };

  Object.defineProperties(state, Object.getOwnPropertyDescriptors(createCostingSection({})));

  return state.costingFormulaRows[0].phaseLabel;
}

console.log(JSON.stringify({
  soap: costingState({
    isCosmeticFormula: false,
    phaseKey: 'saponified_oils',
    phaseName: 'Saponified Oils',
  }),
  cosmetic: costingState({
    isCosmeticFormula: true,
    phaseKey: 'additives',
    phaseName: 'Cool Down Additions',
  }),
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toBe([
        'soap' => 'Localized saponification',
        'cosmetic' => 'Cool Down Additions',
    ]);
});

it('preserves a legacy recipe featured image when saving through the media-library form', function () {
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
        'featured_image_original_name' => 'Original recipe portrait.webp',
    ]);

    Storage::disk('local')->put('recipes/featured-images/original.webp', 'old-image');

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', '<p>Presentation only.</p>')
        ->set('data.manufacturing_instructions', '<p>Manufacturing only.</p>')
        ->call('saveRecipeContent')
        ->assertSet('recipeContentStatus', 'success');

    expect(Storage::disk('local')->exists('recipes/featured-images/original.webp'))->toBeTrue()
        ->and($recipe->fresh()->featured_image_path)->toBe('recipes/featured-images/original.webp')
        ->and((string) $recipe->fresh()->featured_image_original_name)->toBe('Original recipe portrait.webp');
});

it('keeps a shared rich content attachment when it is moved between recipe editors in one save', function () {
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
})->skip('Inline recipe images were replaced by reusable Media Library selections.');

it('keeps comparison snapshots aligned with the version payload and backend calculation', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Comparison Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Comparison Baseline'),
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();

    $expectedSnapshot = $service->versionSnapshot($recipe, $publishedVersion->id);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->recipeId = $recipe->id;
    $component->mount($recipe);

    $result = $component->comparisonVersion(
        $publishedVersion->id,
        $service,
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['snapshot'])->toEqual($expectedSnapshot);
});

it('loads saved versions with the same snapshot contract used for comparison', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Workbench Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion(
        $user,
        $soapFamily,
        workbenchSoapDraftPayload($ingredient, name: 'Opened Baseline'),
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();

    $expectedSnapshot = $service->versionSnapshot($recipe, $publishedVersion->id);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->recipeId = $recipe->id;
    $component->mount($recipe);

    $result = $component->loadVersion(
        $publishedVersion->id,
        $service,
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['snapshot'])->toEqual($expectedSnapshot)
        ->and($result['message'])->toContain('Saved version loaded');
});

it('returns no soap calculation preview for blend-only formulas', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $draft = workbenchSoapDraftPayload($ingredient, oilWeight: 1000);
    $draft['manufacturing_mode'] = 'blend_only';

    $result = $component->previewCalculation(
        $draft,
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['calculation'])->toBeNull();
});

it('exposes workbench phase options for saponifiable oils, additive-only oils, and aromatics', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $trustedCarrierIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    $trustedCarrierIngredient->sapProfile()->create(['koh_sap_value' => 0.188]);

    $customCarrierIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Custom Fig Oil',
        'is_soap_saponification_trusted' => false,
        'is_active' => true,
    ]);

    $fragranceIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Rose Accord',
        'requires_aromatic_compliance' => true,
        'is_active' => true,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $workbench = $component->render(app(RecipeWorkbenchService::class))->getData()['workbench'];
    $ingredients = collect($workbench['ingredients'])->keyBy('id');

    expect($ingredients)->toHaveKeys([
        $trustedCarrierIngredient->id,
        $customCarrierIngredient->id,
        $fragranceIngredient->id,
    ])
        ->and($ingredients[$trustedCarrierIngredient->id]['available_phases'])->toBe(['saponified_oils', 'additives'])
        ->and($ingredients[$trustedCarrierIngredient->id]['default_phase'])->toBe('saponified_oils')
        ->and($ingredients[$customCarrierIngredient->id]['available_phases'])->toBe(['additives'])
        ->and($ingredients[$customCarrierIngredient->id]['default_phase'])->toBe('additives')
        ->and($ingredients[$fragranceIngredient->id]['available_phases'])->toBe(['fragrance'])
        ->and($ingredients[$fragranceIngredient->id]['needs_compliance'])->toBeTrue();
});

it('orders IFRA categories naturally and exposes the Product Type suggestion', function () {
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $category10A = IfraProductCategory::factory()->create([
        'code' => '10A',
        'short_name' => 'Household hand contact',
        'is_active' => true,
    ]);
    $category9 = IfraProductCategory::factory()->create([
        'code' => '9',
        'short_name' => 'Soap / shower gel / rinse-off',
        'is_active' => true,
    ]);
    $category2 = IfraProductCategory::factory()->create([
        'code' => '2',
        'short_name' => 'Deodorants / axillae',
        'is_active' => true,
    ]);
    $productType = ProductType::factory()->create(['product_family_id' => $soapFamily->id]);
    $amendment = IfraAmendment::factory()->create([
        'code' => '51',
        'notification_date' => '2023-06-30',
    ]);
    ProductTypeIfraCategory::factory()->create([
        'product_type_id' => $productType->id,
        'ifra_amendment_id' => $amendment->id,
        'ifra_product_category_id' => $category9->id,
        'is_default' => true,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount(productFamilySlug: $soapFamily->slug, productTypeSlug: $productType->slug);

    $workbench = $component->render(app(RecipeWorkbenchService::class))->getData()['workbench'];

    expect(collect($workbench['ifraProductCategories'])->pluck('code')->all())
        ->toBe(['2', '9', '10A'])
        ->and($workbench['defaultIfraProductCategoryId'])->toBe($category9->id)
        ->and($workbench['ifraGuidance']['amendment']['code'])->toBe('51');
});

it('keeps formula table controls stepped and visually aligned', function () {
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $postReaction = view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render();

    expect($reactionCore)
        ->toContain('grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem]')
        ->toContain('type="text" inputmode="decimal"')
        ->toContain('row.percentage = format(clampPercentage($event.target.value), 2)')
        ->toContain('format(totalOilPercentage(), 2)')
        ->toContain("oilPercentageIsBalanced ? 'bg-[var(--color-field-muted)] text-[var(--color-ink-strong)]'")
        ->toContain('syncFormattedInput($el, row.percentage, 2)')
        ->toContain('oilWeightDecimals(rowWeight(row))')
        ->not->toContain(':value="format(rowWeight(row), 1)"')
        ->and($postReaction)
        ->toContain('grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem]')
        ->toContain('type="text" inputmode="decimal"')
        ->toContain('row.percentage = format(clampPercentage($event.target.value), 2)')
        ->toContain('syncFormattedInput($el, row.percentage, 2)')
        ->not->toContain(':value="format(rowWeight(row), 3)"');
});

it('keeps packaging catalog controls below the intro in a horizontal row', function () {
    $packagingTab = view('livewire.dashboard.partials.recipe-workbench.packaging-tab')->render();

    expect($packagingTab)
        ->toContain('max-w-3xl text-sm text-[var(--color-ink-soft)]')
        ->toContain('flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center')
        ->toContain('sm:w-72')
        ->toContain('sm:min-w-72')
        ->not->toContain('lg:flex-row lg:items-start lg:justify-between');
});

it('keeps formula visual states distinct and softly selected', function () {
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $formulaAnalysis = view('livewire.dashboard.partials.recipe-workbench.formula-analysis')->render();
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();
    $fattyAcidProfile = view('livewire.dashboard.partials.recipe-workbench.fatty-acid-profile')->render();
    $navigation = view('livewire.dashboard.partials.recipe-workbench.navigation')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    expect($reactionCore)
        ->toContain('data-formula-balance-status')
        ->toContain('class="numeric font-semibold" x-text="`${format(totalOilPercentage(), 2)}%`"')
        ->not->toContain('numeric rounded-full bg-white')
        ->and($formulaAnalysis)
        ->toContain('rounded-lg border px-4 py-3 text-sm')
        ->and($ingredientBrowser)
        ->toContain('max-h-[18rem] divide-y divide-[var(--color-line)] overflow-y-auto md:max-h-[22rem] lg:max-h-[24rem] xl:max-h-[600px]')
        ->and($fattyAcidProfile)
        ->toContain('flex min-w-0 items-center justify-between gap-3 rounded-lg bg-[var(--color-field)] px-3 py-2 text-xs')
        ->toContain('min-w-0 flex-1 truncate text-[var(--color-ink-strong)]')
        ->and($navigation)
        ->toContain('sk-workbench-tab')
        ->and($appStylesSource)
        ->toContain('.sk-workbench .sk-workbench-tab.is-active::before')
        ->toContain('background-color: transparent');
});

it('derives formula balance actions from the displayed two decimal total', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs.readFileSync('resources/js/recipe-workbench/sections/formula-section.js', 'utf8');
const sectionSource = source
    .slice(source.indexOf('export function createFormulaSection'))
    .replace('export function createFormulaSection', 'function createFormulaSection');

eval(`${sectionSource}\nglobalThis.createFormulaSection = createFormulaSection;`);

const translations = {
    'status.add_percentage': 'Add :amount%',
    'status.balanced': 'Balanced',
    'status.remove_percentage': 'Remove :amount%',
};

const readoutFor = (total) => {
    const workbench = {
        productFamilySlug: 'soap',
        t(path, replacements = {}) {
            return Object.entries(replacements).reduce(
                (translated, [key, replacement]) => translated.replaceAll(`:${key}`, String(replacement)),
                translations[path] ?? path,
            );
        },
    };

    Object.defineProperties(
        workbench,
        Object.getOwnPropertyDescriptors(globalThis.createFormulaSection()),
    );
    Object.defineProperties(workbench, {
        totalOilPercentage: {
            configurable: true,
            value: () => total,
        },
        format: {
            configurable: true,
            value: (value, decimals = 2) => Number(value).toFixed(decimals),
        },
        number: {
            configurable: true,
            value: (value) => Number.parseFloat(String(value).replace(',', '.')),
        },
    });

    return {
        displayedTotal: workbench.format(total, 2),
        canonicalBalanced: workbench.oilPercentageIsBalanced,
        canSaveDraft: workbench.canSaveDraft,
        canSaveRecipe: workbench.canSaveRecipe,
        label: workbench.oilPercentageStatusLabel,
    };
};

console.log(JSON.stringify([
    readoutFor(99.994),
    readoutFor(99.996),
    readoutFor(100),
    readoutFor(100.004),
    readoutFor(100.006),
]));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    expect(json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR))
        ->toBe([
            ['displayedTotal' => '99.99', 'canonicalBalanced' => false, 'canSaveDraft' => false, 'canSaveRecipe' => false, 'label' => 'Add 0.01%'],
            ['displayedTotal' => '100.00', 'canonicalBalanced' => true, 'canSaveDraft' => true, 'canSaveRecipe' => true, 'label' => 'Balanced'],
            ['displayedTotal' => '100.00', 'canonicalBalanced' => true, 'canSaveDraft' => true, 'canSaveRecipe' => true, 'label' => 'Balanced'],
            ['displayedTotal' => '100.00', 'canonicalBalanced' => true, 'canSaveDraft' => true, 'canSaveRecipe' => true, 'label' => 'Balanced'],
            ['displayedTotal' => '100.01', 'canonicalBalanced' => false, 'canSaveDraft' => false, 'canSaveRecipe' => false, 'label' => 'Remove 0.01%'],
        ]);
});

it('parses localized lye liquid percentages and costs their share of the scaled dilution liquid', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';

const parseDecimalInput = (value) => Number.parseFloat(String(value ?? 0).replace(',', '.')) || 0;
const parseDecimal = parseDecimalInput;
const nonNegativeNumber = (value) => Math.max(0, parseDecimalInput(value));
const number = parseDecimalInput;
const rowWeightForOilWeight = (oilWeight, row) => nonNegativeNumber(oilWeight) * (nonNegativeNumber(row.percentage) / 100);
const convertCostingMass = (value, from, to) => {
  const grams = { g: 1, kg: 1000, oz: 28.349523125, lb: 453.59237 };

  return nonNegativeNumber(value) * grams[from] / grams[to];
};
const convertCostingPrice = (value) => nonNegativeNumber(value);

const formulaSource = fs.readFileSync('resources/js/recipe-workbench/sections/formula-section.js', 'utf8');
const formulaSectionSource = formulaSource
  .slice(formulaSource.indexOf('export function createFormulaSection'))
  .replace('export function createFormulaSection', 'function createFormulaSection');
const costingSource = fs.readFileSync('resources/js/recipe-workbench/sections/costing-section.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createCostingSection', 'function createCostingSection');

eval(`${formulaSectionSource}\nglobalThis.createFormulaSection = createFormulaSection;`);
eval(`${costingSource}\nglobalThis.createCostingSection = createCostingSection;`);

const workbench = {
  productFamilySlug: 'soap',
  isCosmeticFormula: false,
  oilWeight: 1,
  oilUnit: 'kg',
  costingOilWeight: 2000,
  costingOilUnit: 'g',
  phaseOrder: [
    { key: 'saponified_oils', name: 'Saponified Oils' },
    { key: 'lye_water', name: 'Lye Water' },
  ],
  phaseItems: {
    saponified_oils: [{ id: 'oil', ingredient_id: 1, name: 'Olive oil', percentage: 100 }],
    lye_water: [{ id: 'hydrosol', ingredient_id: 2, name: 'Rose hydrosol', percentage: '70,00' }],
  },
  backendCalculation: { lye: { water: { weight: '0,38' } } },
  ingredientForRow: () => null,
  t: (key) => ({
    'costing.phases.saponification': 'Saponification',
    'costing.phases.lye_liquid': 'Lye liquid',
    'costing.ingredients.lye_liquid_percentage': 'Translated % lye liquid',
  })[key] ?? key,
};

Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(globalThis.createFormulaSection()));
Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(globalThis.createCostingSection({})));
Object.defineProperties(workbench, {
  number: { configurable: true, value: number },
  nonNegativeNumber: { configurable: true, value: nonNegativeNumber },
});

assert.equal(workbench.lyeLiquidPercentageTotal(), 70);

const oil = workbench.costingFormulaRows.find((row) => row.phaseKey === 'saponified_oils');
const hydrosol = workbench.costingFormulaRows.find((row) => row.phaseKey === 'lye_water');

assert.equal(oil.weight, 2000);
assert.equal(oil.percentageLabel, '%');
assert.equal(hydrosol.weight, 532);
assert.equal(hydrosol.percentage, 70);
assert.equal(hydrosol.percentageLabel, 'Translated % lye liquid');
assert.equal(hydrosol.phaseLabel, 'Lye liquid');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('keeps fatty acid chemistry compact with grouped profile first and collapsed details', function () {
    $fattyAcidProfile = view('livewire.dashboard.partials.recipe-workbench.fatty-acid-profile')->render();
    $presentationSection = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js'));

    expect($fattyAcidProfile)
        ->not->toContain('Live blend feedback.')
        ->toContain('fattyAcidChemistrySummaryRows()')
        ->toContain('grid grid-cols-3 gap-2')
        ->toContain('class="rounded-lg border border-[var(--color-line)] bg-[var(--color-field)]"', false)
        ->toContain(':aria-expanded="isFattyAcidDetailsOpen.toString()"', false)
        ->toContain(":class=\"isFattyAcidDetailsOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] invisible'\"", false)
        ->toContain('x-text="`${fattyAcidProfileRows.length} acids`"')
        ->toContain('grid-cols-[minmax(0,5.5rem)_minmax(3rem,1fr)_4.25rem]')
        ->toContain('group/fatty-row relative')
        ->toContain('lg:group-hover/fatty-row:opacity-100')
        ->toContain('aria-hidden="true"', false)
        ->toContain('bg-[var(--color-active)]')
        ->toContain('text-[var(--color-on-active)]')
        ->not->toContain(':title="segment.label"', false)
        ->toContain('backgroundColor: segment.softColor')
        ->toContain('color: segment.textColor')
        ->and($presentationSection)
        ->toContain('Sat / Unsat')
        ->toContain('Iodine')
        ->toContain('INS')
        ->toContain('qualityTargetRangeLabel(\'iodine\')')
        ->toContain('qualityTargetRangeLabel(\'ins\')')
        ->toContain('fattyAcidSatUnsatRatio')
        ->toContain("color: 'oklch(0.68 0.15 88)'")
        ->toContain("color: 'oklch(0.60 0.13 28)'")
        ->not->toContain('Unsaturation')
        ->not->toContain('Soap balance');
});

it('presents soap qualities as compact tabbed metric cards', function () {
    $formulaAnalysis = view('livewire.dashboard.partials.recipe-workbench.formula-analysis')->render();
    $formulaTabSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php'));

    expect($formulaAnalysis)
        ->not->toContain('xl:col-span-2')
        ->toContain("soapQualityPanel: 'bar_cure'")
        ->toContain('soapQualitiesExpanded: true')
        ->toContain('localStorage.getItem(this.soapQualitiesStorageKey)')
        ->toContain('localStorage.setItem(this.soapQualitiesStorageKey')
        ->toContain('These values are estimates. Process, additives and cure conditions affect the finished soap.')
        ->not->toContain('3–4% essential oils')
        ->not->toContain('sodium citrate')
        ->not->toContain('experimentation remains')
        ->toContain("soapQualityPanel = 'bar_cure'")
        ->toContain("soapQualityPanel = 'lather_feel'")
        ->toContain('barAndCureQualityRows()')
        ->toContain('latherAndFeelQualityRows()')
        ->not->toContain('defaultQualityRows()')
        ->not->toContain('advancedQualityRows()')
        ->toContain('grid gap-3 sm:grid-cols-2 xl:grid-cols-4')
        ->toContain('sk-eyebrow block min-h-8')
        ->toContain('numeric mt-1.5 text-xl')
        ->toContain('rounded-lg border px-4 py-3 text-sm')
        ->toContain('qualityCardStyle(row.key, row.value)')
        ->toContain('qualityDisplayValue(row)')
        ->toContain('isQualityScored(row.key)')
        ->toContain('border border-[var(--color-line)] bg-transparent shadow-inner')
        ->toContain('qualityTargetLabel(row.key)')
        ->toContain('targetZoneStyle(row.key)')
        ->and($presentationSection = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js')))
        ->toContain('if (numeric <= zone.end) return \'ideal\';')
        ->toContain('return numeric < 85 ? \'high\' : \'excess\';')
        ->toContain('iodine: { start: 41, end: 70 }')
        ->toContain('ins: { start: 136, end: 165 }')
        ->toContain('qualityApplicability(key)')
        ->toContain('isQualityTendency(key)')
        ->toContain('return this.backendCalculation?.properties?.quality_applicability?.[key]')
        ->toContain('Process-dependent tendency')
        ->toContain('Not applicable for this soap context')
        ->and($formulaAnalysis)
        ->not->toContain('sk-quality-pill shrink-0')
        ->not->toContain('Compact interpretation first, deeper chemistry second.')
        ->not->toContain('Deeper structure signals, including iodine and INS.')
        ->not->toContain('Advanced metrics')
        ->and($formulaTabSource)
        ->toContain('@include(\'livewire.dashboard.partials.recipe-workbench.formula-analysis\')')
        ->toContain('@include(\'livewire.dashboard.partials.recipe-workbench.post-reaction\')');
});

it('shows the water remainder and substitute liquids in the workbench lye summary', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';

const parseDecimal = (value) => Number.parseFloat(String(value ?? 0).replace(',', '.')) || 0;

const source = fs.readFileSync('resources/js/recipe-workbench/sections/formula-section.js', 'utf8');
const sectionSource = source
    .slice(source.indexOf('export function createFormulaSection'))
    .replace('export function createFormulaSection', 'function createFormulaSection');

eval(`${sectionSource}\nglobalThis.createFormulaSection = createFormulaSection;`);

const workbench = {
  productFamilySlug: 'soap',
  lyeType: 'naoh',
  kohPurity: 90,
  phaseItems: {
    saponified_oils: [],
    lye_water: [{ id: 'lavender', name: 'Lavender Hydrosol', percentage: 70 }],
    additives: [],
    fragrance: [],
  },
  backendCalculation: {
    lye: {
      selected: { naoh_weight: 145.83 },
      water: { weight: 259.26 },
      liquid_composition: {
        total_weight: 259.26,
        water_percentage: 30,
        water_weight: 77.778,
        substitutions: [{ id: 'lavender', name: 'Lavender Hydrosol', percentage: 70, weight: 181.482 }],
      },
    },
  },
  number: (value) => Number(value ?? 0),
  nonNegativeNumber: (value) => Math.max(0, Number(value ?? 0)),
  t: (key, replacements = {}) => {
    const translation = ({
      'settings.water': 'Water',
      'settings.lye_liquid_selected_singular': 'Water + 1 selected liquid',
      'settings.lye_liquid_water_free_plural': ':count selected liquids · no water',
      'settings.lye_liquid_water_free_singular': '1 selected liquid · no water',
    })[key] ?? key;

    return Object.entries(replacements).reduce(
      (value, [name, replacement]) => value.replaceAll(`:${name}`, String(replacement)),
      translation,
    );
  },
};

Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(globalThis.createFormulaSection()));
Object.defineProperties(workbench, {
  number: {
    configurable: true,
    value: (value) => Number(value ?? 0),
  },
  nonNegativeNumber: {
    configurable: true,
    value: (value) => Math.max(0, Number(value ?? 0)),
  },
});

assert.deepEqual(
  workbench.lyeSummaryCards.map(({ id, label, value }) => ({ id, label, value: Number(value.toFixed(3)) })),
  [
    { id: 'naoh-to-weigh', label: 'Lye (NaOH)', value: 145.83 },
    { id: 'water', label: 'Water', value: 77.778 },
    { id: 'lye-liquid-lavender', label: 'Lavender Hydrosol', value: 181.482 },
  ],
);

assert.equal(workbench.lyeLiquidSelectionSummary(), 'Water + 1 selected liquid');
workbench.phaseItems.lye_water[0].percentage = 100;
assert.equal(workbench.lyeLiquidSelectionSummary(), '1 selected liquid · no water');
assert.equal(workbench.lyeLiquidSelectionSummary().includes('Water +'), false);
workbench.phaseItems.lye_water = [
  { id: 'lavender', name: 'Lavender Hydrosol', percentage: 60 },
  { id: 'milk', name: 'Goat Milk', percentage: 40 },
];
assert.equal(workbench.lyeLiquidSelectionSummary(), '2 selected liquids · no water');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('keeps the viewport on the lye liquid editor when selecting a substitute liquid', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace('export function createRecipeWorkbench', 'function createRecipeWorkbench');

const stubs = `
const buildCategoryOptions = () => [];
const buildFattyAcidLabels = () => [];
const filterIngredientCatalog = (ingredients) => ingredients;
const getIngredientCategoryCode = () => '';
const buildIngredientFattyAcidRows = () => [];
const buildIngredientInspectorRows = () => [];
const getIngredientMonogram = () => '';
const getNormalizedIfraProductCategoryId = (value) => value;
const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase ?? ingredient.available_phases?.[0] ?? null;
const findSelectedIfraProductCategory = () => null;
const getTargetPhaseForCategory = () => null;
const buildSerializedDraft = () => ({});
const buildSerializedRow = () => ({});
const persistWorkbench = async () => {};
const refreshWorkbenchCalculationPreview = async () => {};
const buildDraftStateFromDraft = () => null;
const buildSnapshotStateFromSnapshot = () => null;
const humanizeText = (value) => value;
const createFormulaSection = () => ({});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createPresentationSection = () => ({});
const createVersionSection = () => ({});
`;

let scrollCount = 0;
const postReaction = {
  scrollIntoView() {
    scrollCount += 1;
  },
  classList: { add() {}, remove() {} },
};

globalThis.document = {
  getElementById: (id) => id === 'post-reaction-phases' ? postReaction : null,
};
globalThis.window = {
  location: { hash: '' },
  matchMedia: () => ({ matches: true }),
};

eval(`${stubs}\n${source}\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;`);

const workbench = globalThis.createRecipeWorkbench({
  phases: [],
  ingredients: [{
    id: 7,
    name: 'Lavender Hydrosol',
    inci_name: 'Lavandula Water',
    category: 'hydrosols',
    available_phases: ['additives'],
    can_add_to_additives: true,
  }],
});

workbench.addLyeLiquidIngredient(7);

assert.equal(workbench.phaseItems.lye_water.length, 1);
assert.equal(workbench.phaseItems.additives.length, 0);
assert.equal(scrollCount, 0);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('keeps allergen mass inside its source oil when rendering cured soap output', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/sections/presentation-section.js', 'utf8')
  .replace('export function createPresentationSection', 'function createPresentationSection');

eval(`${source}\nglobalThis.createPresentationSection = createPresentationSection;`);

const workbench = {
  productFamilySlug: 'soap',
  backendLabeling: {
    basis: {
      formula_weight: 1093.62,
      cured_weight: 1000.22,
      residual_water_weight: 110.0242,
    },
    default_variant_key: 'saponified_with_superfat',
    list_variants: [{
      key: 'saponified_with_superfat',
      ingredient_rows: [
        { label: 'SODIUM ALMONDATE', weight: 553.8132, kind: 'saponified_oil' },
        { label: 'SODIUM COCOATE', weight: 186.6521, kind: 'saponified_oil' },
        { label: 'AQUA', weight: 203.43, kind: 'water' },
        { label: 'GLYCERIN', weight: 80.43, kind: 'derived' },
        { label: 'PRUNUS AMYGDALUS DULCIS OIL', weight: 40.425, kind: 'theoretical_superfat' },
        { label: 'COCOS NUCIFERA OIL', weight: 13.475, kind: 'theoretical_superfat' },
        { label: 'LAVANDULA HYBRIDA OIL', weight: 15.4, kind: 'ingredient' },
      ],
      declaration_rows: [{
        label: 'LINALOOL',
        percent_of_formula: 0.14082,
        percent_of_cured_basis: 0.15397,
        included_in_inci: true,
      }],
    }],
  },
  number: (value) => Number(value ?? 0),
};

Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(globalThis.createPresentationSection()));

const ingredientRows = workbench.curedSoapIngredientRows;
const lavender = ingredientRows.find((row) => row.label === 'LAVANDULA HYBRIDA OIL');
const linalool = workbench.curedSoapDeclarationRows[0];

assert.ok(lavender);
assert.ok(linalool);
assert.ok(Math.abs(workbench.curedSoapIngredientTotalWeight - 1000.22) < 0.001);
assert.ok(Math.abs(lavender.adjusted_weight - 15.4) < 0.001);
assert.ok(Math.abs(lavender.percent_of_cured_basis - 1.53966) < 0.001);
assert.ok(Math.abs(linalool.adjusted_weight - 1.54) < 0.001);
assert.ok(Math.abs(linalool.percent_of_cured_basis - 0.15397) < 0.001);

console.log(JSON.stringify({
  totalWeight: workbench.curedSoapIngredientTotalWeight,
  lavenderWeight: lavender.adjusted_weight,
  lavenderPercent: lavender.percent_of_cured_basis,
  linaloolWeight: linalool.adjusted_weight,
  linaloolPercent: linalool.percent_of_cured_basis,
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($result['totalWeight'])->toBeGreaterThan(1000.219)
        ->toBeLessThan(1000.221)
        ->and($result['lavenderWeight'])->toBeGreaterThan(15.399)
        ->toBeLessThan(15.401)
        ->and($result['linaloolPercent'])->toBeGreaterThan(0.153)
        ->toBeLessThan(0.155);
});

it('keeps water and substitute lye liquids inside the eleven percent cured liquid pool', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/sections/presentation-section.js', 'utf8')
  .replace('export function createPresentationSection', 'function createPresentationSection');

eval(`${source}\nglobalThis.createPresentationSection = createPresentationSection;`);

const workbench = {
  productFamilySlug: 'soap',
  backendLabeling: {
    basis: {
      formula_weight: 1270,
      cured_weight: 1000,
      residual_water_weight: 110,
    },
    default_variant_key: 'saponified_with_superfat',
    list_variants: [{
      key: 'saponified_with_superfat',
      ingredient_rows: [
        { label: 'SODIUM OLIVATE', weight: 860, lye_liquid_weight: 0, kind: 'saponified_oil' },
        { label: 'AQUA', weight: 200, lye_liquid_weight: 190, kind: 'water' },
        { label: 'LAVANDULA ANGUSTIFOLIA FLOWER WATER', weight: 210, lye_liquid_weight: 190, kind: 'lye_liquid' },
      ],
      declaration_rows: [],
    }],
  },
  number: (value) => Number(value ?? 0),
};

Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(globalThis.createPresentationSection()));

const rows = workbench.curedSoapIngredientRows;
const water = rows.find((row) => row.label === 'AQUA');
const hydrosol = rows.find((row) => row.label === 'LAVANDULA ANGUSTIFOLIA FLOWER WATER');

assert.ok(water);
assert.ok(hydrosol);
assert.equal(water.adjusted_weight, 65);
assert.equal(hydrosol.adjusted_weight, 75);
assert.equal(water.percent_of_cured_basis, 6.5);
assert.equal(hydrosol.percent_of_cured_basis, 7.5);
assert.equal(workbench.curedSoapIngredientTotalWeight, 1000);
assert.equal(workbench.curedSoapIngredientTotalPercent, 100);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('presents tendency quality metrics without score target treatment', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/sections/presentation-section.js', 'utf8')
  .replace('export function createPresentationSection', 'function createPresentationSection');

eval(`${source}\nglobalThis.createPresentationSection = createPresentationSection;`);

const workbench = {
  backendCalculation: {
    properties: {
      quality_applicability: {
        cleansing_strength: { applies: true, confidence: 0.35, display: 'tendency' },
      },
    },
  },
  number: (value) => Number(value ?? 0),
  format: (value, decimals = 1) => Number(value ?? 0).toFixed(decimals),
};

Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(globalThis.createPresentationSection()));

console.log(JSON.stringify({
  tendency: workbench.isQualityTendency('cleansing_strength'),
  targetLabel: workbench.qualityTargetLabel('cleansing_strength'),
  targetZone: workbench.targetZoneStyle('cleansing_strength'),
  tone: workbench.qualityTone('cleansing_strength', 70),
  displayValue: workbench.qualityDisplayValue({ key: 'cleansing_strength', value: 70 }),
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($result['tendency'])->toBeTrue()
        ->and($result['targetLabel'])->toBe('Process-dependent tendency')
        ->and($result['targetZone'])->toBeNull()
        ->and($result['tone'])->toBe('neutral')
        ->and($result['displayValue'])->toBe('70.0 tendency');
});

it('maps backend calculation warnings into visible quality flags', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/sections/presentation-section.js', 'utf8')
  .replace('export function createPresentationSection', 'function createPresentationSection');

eval(`${source}\nglobalThis.createPresentationSection = createPresentationSection;`);

const workbench = {
  backendCalculation: {
    properties: {
      fatty_acid_groups: {},
      qualities: {},
      warnings: [
        'high_koh_context_process_dependent',
        'negative_superfat_requires_neutralization_and_ph_control',
      ],
    },
  },
  number: (value) => Number(value ?? 0),
};

Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(globalThis.createPresentationSection()));

console.log(JSON.stringify(workbench.qualityFlags()));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $flags = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    $labels = array_column($flags, 'label');

    expect($labels)
        ->toContain('High-KOH process context')
        ->toContain('Negative superfat needs pH control')
        ->and($flags[0]['explanation'])->not->toBe('');
});

it('does not show bar-cure Castile guidance when liquid context makes bar metrics not applicable', function () {
    $script = <<<'JS'
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/sections/presentation-section.js', 'utf8')
  .replace('export function createPresentationSection', 'function createPresentationSection');

eval(`${source}\nglobalThis.createPresentationSection = createPresentationSection;`);

const workbench = {
  backendCalculation: {
    properties: {
      fatty_acid_groups: { mu: 72, vs: 4, hs: 12 },
      qualities: { cure_speed: 0, slime_risk: 0, cleansing_strength: 0, dos_risk: 0 },
      quality_applicability: {
        cure_speed: { applies: false, confidence: 0.2, display: 'hidden' },
        slime_risk: { applies: false, confidence: 0.2, display: 'hidden' },
      },
      warnings: ['high_koh_context_process_dependent'],
    },
  },
  number: (value) => Number(value ?? 0),
};

Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(globalThis.createPresentationSection()));

console.log(JSON.stringify(workbench.qualityFlags().map((flag) => flag.label)));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $labels = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($labels)
        ->toContain('High-KOH process context')
        ->not->toContain('Castile-like');
});

function makeCarrierOilIngredient(): Ingredient
{
    return Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
}

/**
 * @return array{type: string, content: array<int, array<string, mixed>>}
 */
function recipeWorkbenchTipTapProcedure(string|array $temporaryIds): array
{
    $temporaryIds = is_array($temporaryIds) ? $temporaryIds : [$temporaryIds];

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

/**
 * @return array{type: string, content: array<int, array<string, mixed>>}
 */
function recipeWorkbenchTipTapMediaAssets(MediaAsset|array $assets): array
{
    $assets = is_array($assets) ? $assets : [$assets];

    return [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => collect($assets)
                ->map(fn (MediaAsset $asset): array => [
                    'type' => 'image',
                    'attrs' => [
                        'src' => route('media.show', [$asset, 'master']),
                        'alt' => null,
                        'title' => null,
                        'id' => RichContentAttachmentPaths::mediaAssetIdentity($asset->public_id),
                        'width' => null,
                        'height' => null,
                    ],
                ])
                ->all(),
        ]],
    ];
}

function recipeWorkbenchInlineMediaHtml(MediaAsset $asset): string
{
    return sprintf(
        '<img data-id="%s" src="%s">',
        RichContentAttachmentPaths::mediaAssetIdentity($asset->public_id),
        route('media.show', [$asset, 'master']),
    );
}

/**
 * @return array<string, mixed>
 */
function workbenchSoapDraftPayload(
    Ingredient $ingredient,
    string $name = 'Recipe',
    float $oilWeight = 1000,
    string $exposureMode = 'rinse_off',
): array {
    $soapFamily = ProductFamily::query()->where('slug', 'soap')->latest('id')->first();
    $productType = $soapFamily instanceof ProductFamily
        ? ProductType::query()
            ->whereHas('productFamilies', fn (Builder $query): Builder => $query->whereKey($soapFamily->id))
            ->first()
        : null;
    $productType ??= $soapFamily instanceof ProductFamily
        ? ProductType::factory()->create(['product_family_id' => $soapFamily->id])
        : null;

    return [
        'name' => $name,
        'product_type_id' => $productType?->id,
        'oil_unit' => 'g',
        'oil_weight' => $oilWeight,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => $exposureMode,
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'lye_type' => 'naoh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
        'superfat' => 5,
        'ifra_product_category_id' => null,
        'phase_items' => [
            'saponified_oils' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'percentage' => 100,
                    'weight' => $oilWeight,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ];
}
