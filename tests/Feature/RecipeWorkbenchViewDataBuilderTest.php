<?php

use App\Enums\MassDisplaySystem;
use App\Models\InterfaceTranslation;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use App\Models\RegulatoryRegimeSubstanceRule;
use App\Models\Substance;
use App\Models\User;
use App\Models\Workspace;
use App\Services\RecipeWorkbenchIngredientCatalogBuilder;
use App\Services\RecipeWorkbenchService;
use App\Services\RecipeWorkbenchViewDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('uses the workspace mass system to choose the initial formula unit', function (): void {
    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $user = User::factory()->create();
    $plan = Plan::factory()->hasLimit('formula_items_per_recipe', 30)->create();
    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Workspace::factory()->for($user, 'owner')->create([
        'mass_display_system' => MassDisplaySystem::UsCustomary,
    ]);

    mock(RecipeWorkbenchService::class, function ($mock): void {
        $mock->shouldReceive('currentVersionPayloadUsingCatalog')->andReturn(null);
        $mock->shouldReceive('packagingCatalogPayload')->andReturn([]);
        $mock->shouldReceive('phaseBlueprints')->andReturn([]);
    });
    mock(RecipeWorkbenchIngredientCatalogBuilder::class, fn ($mock) => $mock->shouldReceive('build')->andReturn([]));

    $payload = app(RecipeWorkbenchViewDataBuilder::class)->build($productFamily, null, $user);

    expect($payload['preferredMassUnit'])->toBe('lb')
        ->and($payload['formulaItemLimit'])->toBe(30);
});

it('includes active allergen and substance rule counts for each regime', function () {
    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $regime = RegulatoryRegime::factory()->create([
        'code' => 'eu',
        'name' => 'EU regime',
        'status' => 'active',
        'is_default' => true,
    ]);
    $substance = Substance::factory()->create([
        'name' => 'Linalool',
    ]);

    RegulatoryRegimeAllergen::factory()
        ->count(2)
        ->for($regime, 'regulatoryRegime')
        ->create(['is_active' => true]);
    RegulatoryRegimeAllergen::factory()
        ->for($regime, 'regulatoryRegime')
        ->create(['is_active' => false]);
    RegulatoryRegimeSubstanceRule::factory()
        ->for($regime, 'regulatoryRegime')
        ->for($substance, 'substance')
        ->create(['is_active' => true]);

    mock(RecipeWorkbenchService::class, function ($mock): void {
        $mock->shouldReceive('currentVersionPayloadUsingCatalog')
            ->once()
            ->with(null, [])
            ->andReturn(null);
        $mock->shouldReceive('packagingCatalogPayload')->once()->andReturn([]);
        $mock->shouldReceive('phaseBlueprints')->once()->andReturn([]);
    });

    mock(RecipeWorkbenchIngredientCatalogBuilder::class, function ($mock): void {
        $mock->shouldReceive('build')->once()->andReturn([]);
    });

    $payload = app(RecipeWorkbenchViewDataBuilder::class)->build($productFamily, null, null);
    $regimePayload = collect($payload['regulatoryRegimes'])->firstWhere('code', 'eu');

    expect($regimePayload)->toMatchArray([
        'allergen_rule_count' => 2,
        'substance_rule_count' => 1,
    ]);
});

it('exposes regime milestone dates so the switcher can surface applicability hints', function () {
    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    RegulatoryRegime::factory()->create([
        'code' => 'canada_2026',
        'name' => 'Canada',
        'market_code' => 'ca',
        'status' => 'active',
        'source_data' => [
            'milestones' => [
                'list_1_required_from' => '2026-04-12',
                'new_cosmetics_required_from' => '2026-08-01',
                'existing_products_required_from' => '2028-08-01',
            ],
        ],
    ]);
    RegulatoryRegime::factory()->create([
        'code' => 'eu',
        'name' => 'EU regime',
        'market_code' => 'eu',
        'status' => 'active',
        'is_default' => true,
    ]);

    mock(RecipeWorkbenchService::class, function ($mock): void {
        $mock->shouldReceive('currentVersionPayloadUsingCatalog')->andReturn(null);
        $mock->shouldReceive('packagingCatalogPayload')->andReturn([]);
        $mock->shouldReceive('phaseBlueprints')->andReturn([]);
    });
    mock(RecipeWorkbenchIngredientCatalogBuilder::class, fn ($mock) => $mock->shouldReceive('build')->andReturn([]));

    $payload = app(RecipeWorkbenchViewDataBuilder::class)->build($productFamily, null, null);
    $regimePayload = collect($payload['regulatoryRegimes'])->firstWhere('code', 'canada_2026');

    expect($regimePayload['milestones'])->toBe([
        'list_1_required_from' => '2026-04-12',
        'new_cosmetics_required_from' => '2026-08-01',
        'existing_products_required_from' => '2028-08-01',
    ]);
});

it('builds the initial workbench payload without eager preview or costing data', function () {
    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $currentVersionPayload = [
        'recipe' => [
            'id' => 42,
            'current_version_id' => 84,
            'version_number' => 3,
            'is_current' => true,
        ],
        'formulaName' => 'Deferred Draft',
        'oilUnit' => 'g',
        'oilWeight' => 1000,
        'manufacturingMode' => 'saponify_in_formula',
        'exposureMode' => 'rinse_off',
        'regulatoryRegime' => 'eu',
        'editMode' => 'percentage',
        'lyeType' => 'naoh',
        'kohPurity' => 90,
        'dualKohPercentage' => 40,
        'waterMode' => 'percent_of_oils',
        'waterValue' => 38,
        'superfat' => 5,
        'selectedIfraProductCategoryId' => null,
        'phaseItems' => [
            'saponified_oils' => [],
            'additives' => [],
            'fragrance' => [],
        ],
        'catalogReview' => [
            'needs_review' => false,
        ],
    ];

    mock(RecipeWorkbenchService::class, function ($mock) use ($currentVersionPayload): void {
        $mock->shouldReceive('currentVersionPayloadUsingCatalog')
            ->once()
            ->with(null, [])
            ->andReturn($currentVersionPayload);
        $mock->shouldReceive('currentVersionSnapshot')->never();
        $mock->shouldReceive('costingPayload')->never();
        $mock->shouldReceive('packagingCatalogPayload')
            ->once()
            ->andReturn([]);
        $mock->shouldReceive('phaseBlueprints')
            ->once()
            ->andReturn([]);
    });

    mock(RecipeWorkbenchIngredientCatalogBuilder::class, function ($mock): void {
        $mock->shouldReceive('build')
            ->once()
            ->andReturn([]);
    });

    $payload = app(RecipeWorkbenchViewDataBuilder::class)->build($productFamily, null, null);

    expect($payload['savedDraft'])->toBe($currentVersionPayload)
        ->and($payload['costing'])->toBeNull()
        ->and($payload['costingLoaded'])->toBeFalse();
});

it('includes the user packaging catalog in the initial workbench payload', function () {
    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $user = User::factory()->create();
    $packagingItem = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Amber Jar',
        'unit_cost' => 0.82,
        'currency' => 'EUR',
        'notes' => 'Reusable catalog item',
    ]);

    mock(RecipeWorkbenchService::class, function ($mock) use ($packagingItem): void {
        $mock->shouldReceive('currentVersionPayloadUsingCatalog')
            ->once()
            ->with(null, [])
            ->andReturn(null);
        $mock->shouldReceive('packagingCatalogPayload')
            ->once()
            ->andReturn([
                [
                    'id' => $packagingItem->id,
                    'name' => 'Amber Jar',
                    'unit_cost' => 0.82,
                    'currency' => 'EUR',
                    'notes' => 'Reusable catalog item',
                ],
            ]);
        $mock->shouldReceive('phaseBlueprints')
            ->once()
            ->andReturn([]);
    });

    mock(RecipeWorkbenchIngredientCatalogBuilder::class, function ($mock): void {
        $mock->shouldReceive('build')
            ->once()
            ->andReturn([]);
    });

    $payload = app(RecipeWorkbenchViewDataBuilder::class)->build($productFamily, null, $user);

    expect($payload['packagingCatalog'])->toHaveCount(1)
        ->and($payload['packagingCatalog'][0])->toMatchArray([
            'id' => $packagingItem->id,
            'name' => 'Amber Jar',
            'unit_cost' => 0.82,
            'currency' => 'EUR',
            'notes' => 'Reusable catalog item',
        ]);
});

it('uses localized maintained currency choices and preserves the stored currency', function () {
    app()->setLocale('fr');

    $productFamily = ProductFamily::factory()->create(['slug' => 'soap']);
    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create(['default_currency' => 'HRK']);

    mock(RecipeWorkbenchService::class, function ($mock): void {
        $mock->shouldReceive('currentVersionPayloadUsingCatalog')->andReturn(null);
        $mock->shouldReceive('packagingCatalogPayload')->andReturn([]);
        $mock->shouldReceive('phaseBlueprints')->andReturn([]);
    });
    mock(RecipeWorkbenchIngredientCatalogBuilder::class, fn ($mock) => $mock->shouldReceive('build')->andReturn([]));

    $payload = app(RecipeWorkbenchViewDataBuilder::class)->build($productFamily, null, $user);

    expect($payload['currencies']['USD'])->toBe('dollar des États-Unis')
        ->and($payload['currencies'])->toHaveKey('HRK')
        ->and($payload['currencies'])->not->toHaveKey('ZWL');
});

it('overlays partial localized workbench translations on the English payload', function () {
    config(['app.fallback_locale' => 'en']);
    app()->setLocale('fr');

    InterfaceTranslation::query()->create([
        'group' => 'workbench',
        'key' => 'header.product_name',
        'text' => ['fr' => 'Nom du produit partiel'],
    ]);

    $productFamily = ProductFamily::factory()->create(['slug' => 'soap']);

    mock(RecipeWorkbenchService::class, function ($mock): void {
        $mock->shouldReceive('currentVersionPayloadUsingCatalog')->andReturn(null);
        $mock->shouldReceive('packagingCatalogPayload')->andReturn([]);
        $mock->shouldReceive('phaseBlueprints')->andReturn([]);
    });
    mock(RecipeWorkbenchIngredientCatalogBuilder::class, fn ($mock) => $mock->shouldReceive('build')->andReturn([]));

    $payload = app(RecipeWorkbenchViewDataBuilder::class)->build($productFamily, null, null);

    expect($payload['translations']['header']['product_name'])->toBe('Nom du produit partiel')
        ->and($payload['translations']['packaging']['plan']['title'])->toBe('Packaging plan')
        ->and($payload['translations']['costing']['settings']['title'])->toBe('Costing setup');
});
