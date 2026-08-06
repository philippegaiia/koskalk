<?php

use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\CreateProductionDraft;
use App\Actions\Production\PrepareProductionStock;
use App\Actions\Production\SaveProductionActuals;
use App\Actions\Production\StartProduction;
use App\Livewire\ProductionBench\Production\ProductionDetail;
use App\MassUnit;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\OwnerType;
use App\ProductionConsumptionKind;
use App\ProductionRunStatus;
use App\StockMovementType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('records actual consumption during production without posting stock movements', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'actuals-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    $movementCount = StockMovement::query()->count();

    $saved = app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        [
            'production_requirement_id' => $ingredientRequirement->id,
            'stock_lot_id' => $oilLot->id,
            'quantity' => '11000.000000000',
            'note' => 'Slightly over the plan',
        ],
        [
            'production_requirement_id' => $packagingRequirement->id,
            'stock_lot_id' => $packagingLot->id,
            'quantity' => '98',
        ],
    ]);

    $ingredientActual = $saved->consumption()->where('production_requirement_id', $ingredientRequirement->id)->sole();

    expect($saved->status)->toBe(ProductionRunStatus::InProduction)
        ->and($ingredientActual->kind)->toBe(ProductionConsumptionKind::Ingredient)
        ->and($ingredientActual->quantity)->toBe('11000.000000000')
        ->and($ingredientActual->unit_snapshot)->toBe('g')
        ->and($ingredientActual->note)->toBe('Slightly over the plan')
        ->and($ingredientActual->recorded_by_user_id)->toBe($fixture['owner']->id)
        ->and($saved->consumption()->where('production_requirement_id', $packagingRequirement->id)->sole()->quantity)->toBe('98.000000000')
        ->and(StockMovement::query()->count())->toBe($movementCount);
});

it('updates and removes actual rows before the terminal action', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'actuals-2');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        [
            'production_requirement_id' => $ingredientRequirement->id,
            'stock_lot_id' => $oilLot->id,
            'quantity' => '10500.000000000',
        ],
    ]);

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        [
            'production_requirement_id' => $ingredientRequirement->id,
            'stock_lot_id' => $oilLot->id,
            'quantity' => '9000.000000000',
            'note' => 'Corrected at the bench',
        ],
    ]);

    expect($production->consumption()->sole()->quantity)->toBe('9000.000000000')
        ->and($production->consumption()->sole()->note)->toBe('Corrected at the bench')
        ->and($production->consumption()->count())->toBe(1);

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        [
            'production_requirement_id' => $ingredientRequirement->id,
            'stock_lot_id' => $oilLot->id,
            'quantity' => '0',
        ],
    ]);

    expect($production->consumption()->count())->toBe(0);
});

it('rejects actual rows outside in-production and invalid quantities', function (): void {
    $fixture = productionExecutionFixture();
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'actuals-draft-1',
        status: ProductionRunStatus::Scheduled,
        plannedFor: '2026-08-20',
    );

    expect(function () use ($fixture, $production): void {
        app(SaveProductionActuals::class)->handle($fixture['owner'], $production, []);
    })->toThrow(ValidationException::class);

    $started = productionExecutionRun($fixture, 'actuals-3');
    $packagingRequirement = $started->requirements()->where('kind', 'packaging')->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();

    expect(function () use ($fixture, $started, $packagingRequirement, $packagingLot): void {
        app(SaveProductionActuals::class)->handle($fixture['owner'], $started, [
            [
                'production_requirement_id' => $packagingRequirement->id,
                'stock_lot_id' => $packagingLot->id,
                'quantity' => '2.5',
            ],
        ]);
    })->toThrow(ValidationException::class);

    expect(function () use ($fixture, $started, $packagingRequirement, $packagingLot): void {
        app(SaveProductionActuals::class)->handle($fixture['owner'], $started, [
            [
                'production_requirement_id' => $packagingRequirement->id,
                'stock_lot_id' => $packagingLot->id,
                'quantity' => 'not-a-number',
            ],
        ]);
    })->toThrow(ValidationException::class);

    app(SaveProductionActuals::class)->handle($fixture['owner'], $started, [
        [
            'production_requirement_id' => $packagingRequirement->id,
            'stock_lot_id' => $packagingLot->id,
            'quantity' => '0',
        ],
    ]);

    expect($started->consumption()->count())->toBe(0);
});

it('saves actuals from the production sheet', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'actuals-ui-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $production->id])
        ->assertSee('Actual consumption')
        ->set('actualRows.'.$ingredientRequirement->id.'.stock_lot_id', (string) $oilLot->id)
        ->set('actualRows.'.$ingredientRequirement->id.'.quantity', '10500')
        ->set('actualRows.'.$ingredientRequirement->id.'.note', 'From the bench')
        ->call('saveActuals')
        ->assertDispatched('app-notification', function (string $event, array $payload): bool {
            return $event === 'app-notification'
                && str_starts_with($payload['message'], __('production_bench.production.actuals_saved'))
                && $payload['type'] === 'success';
        });

    expect($production->consumption()->where('production_requirement_id', $ingredientRequirement->id)->sole()->quantity)
        ->toBe('10500.000000000');
});

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion, olive: Ingredient, packaging: PackagingItem}
 */
function productionExecutionFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'execution-soap-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'initial_oils',
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Execution soap',
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
        'manufacturing_mode' => 'saponify_in_formula',
        'batch_mass_grams' => '1000.000000000',
        'batch_unit' => 'g',
        'calculation_context' => [
            'editing_mode' => 'percentage',
            'lye_type' => 'naoh',
            'koh_purity_percentage' => 90,
            'dual_lye_koh_percentage' => 40,
            'superfat' => 5,
            'oil_weight' => 1000,
            'oil_unit' => 'g',
            'mass_grams' => 1000,
            'totals' => [],
        ],
        'water_settings' => ['mode' => 'percent_of_oils', 'value' => 38],
    ]);

    $oleic = FattyAcid::factory()->create(['key' => 'oleic-'.fake()->unique()->numberBetween(1, 999999), 'name' => 'Oleic']);
    $olive = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    IngredientSapProfile::factory()->create(['ingredient_id' => $olive->id, 'koh_sap_value' => 0.188]);
    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $olive->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 100,
    ]);

    $phase = RecipePhase::factory()->for($version)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Saponified Oils',
        'slug' => 'saponified_oils',
        'sort_order' => 1,
    ]);
    RecipeItem::factory()->for($version)->for($phase, 'recipePhase')->for($olive)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '100.0000',
        'weight' => null,
    ]);

    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Soap box']);
    RecipeVersionPackagingItem::query()->create([
        'recipe_version_id' => $version->id,
        'packaging_item_id' => $packaging->id,
        'name' => 'Soap box',
        'components_per_unit' => '1.000',
        'position' => 1,
    ]);

    return compact('owner', 'workspace', 'recipe', 'version', 'olive', 'packaging');
}

/**
 * @param  array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}  $fixture
 */
function productionExecutionRun(array $fixture, string $idempotencyKey): ProductionRun
{
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: $idempotencyKey,
        status: ProductionRunStatus::Scheduled,
        plannedFor: '2026-08-20',
    );

    $production->requirements->each(function ($requirement) use ($fixture): void {
        $lot = StockLot::factory()->for($fixture['workspace'])->released()->create([
            'ingredient_id' => $requirement->ingredient_id,
            'packaging_item_id' => $requirement->packaging_item_id,
            'unit_kind' => $requirement->ingredient_id !== null ? 'mass' : 'count',
            'expires_at' => '2027-01-01',
            'released_at' => now(),
        ]);
        StockMovement::factory()->for($lot, 'stockLot')->create([
            'workspace_id' => $fixture['workspace']->id,
            'type' => StockMovementType::OpeningBalance,
            'quantity_delta' => $requirement->ingredient_id !== null
                ? $requirement->required_mass_grams
                : (string) $requirement->required_units,
        ]);
    });

    app(PrepareProductionStock::class)->handle(
        actor: $fixture['owner'],
        productionIds: [$production->id],
        idempotencyKey: $idempotencyKey.'-prepare',
    );

    app(AssignProductionBatchNumbers::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        productionIds: [$production->id],
    );

    return app(StartProduction::class)->handle($fixture['owner'], $production->fresh());
}
