<?php

use App\MassUnit;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductionHoliday;
use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskSetItem;
use App\Models\ProductionTaskType;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\OwnerType;
use App\Services\Production\FlashDateProposalService;
use App\Services\Production\FlashProductionSimulator;
use App\StockMovementType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('simulates multiple product lines, whole batches, aggregate requirements, stock and indicative value without writing', function (): void {
    $fixture = flashSimulatorFixture();

    $beforeRuns = $fixture['workspace']->productionRuns()->count();
    $result = app(FlashProductionSimulator::class)->simulate($fixture['workspace'], [
        [
            'recipe_id' => $fixture['recipe']->id,
            'desired_units' => '120',
            'expected_units_per_batch' => '100',
            'basis_input_value' => '12',
            'basis_input_unit' => MassUnit::Kilogram->value,
            'task_set_id' => $fixture['taskSet']->id,
        ],
        [
            'recipe_id' => $fixture['recipe']->id,
            'desired_units' => '50',
            'expected_units_per_batch' => '100',
            'basis_input_value' => '12',
            'basis_input_unit' => MassUnit::Kilogram->value,
            'task_set_id' => $fixture['taskSet']->id,
        ],
    ]);

    expect($result['totals'])
        ->desired_units->toBe(170)
        ->expected_units->toBe(300)
        ->extra_units->toBe(130)
        ->whole_batches->toBe(3)
        ->task_minutes->toBe(45)
        ->missing_prices->toBe(0);

    $ingredient = collect($result['requirements'])->firstWhere('ingredient_id', $fixture['ingredient']->id);
    $packaging = collect($result['requirements'])->firstWhere('packaging_item_id', $fixture['packaging']->id);

    expect($ingredient)
        ->required->toBe('36000.000000000')
        ->available->toBe('5000.000000000')
        ->shortage->toBe('31000.000000000')
        ->indicative_unit_price->toBe('0.010000000000')
        ->indicative_value->toBe('360.000000000');
    expect($packaging)
        ->required->toBe('300.000000000')
        ->available->toBe('0.000000000')
        ->shortage->toBe('300.000000000');
    expect($fixture['workspace']->productionRuns()->count())->toBe($beforeRuns);
});

it('marks missing current prices explicitly instead of hiding the cost warning', function (): void {
    $fixture = flashSimulatorFixture(withPrices: false);

    $result = app(FlashProductionSimulator::class)->simulate($fixture['workspace'], [[
        'recipe_id' => $fixture['recipe']->id,
        'desired_units' => '10',
        'expected_units_per_batch' => '100',
        'basis_input_value' => '12',
        'basis_input_unit' => 'kg',
    ]]);

    expect($result['totals']['missing_prices'])->toBe(2)
        ->and(collect($result['requirements'])->every(fn (array $row): bool => $row['missing_price']))->toBeTrue();
});

it('proposes working dates and keeps the first task on the production date', function (): void {
    $fixture = flashSimulatorFixture();
    $fixture['workspace']->update(['production_works_on_weekends' => false]);
    ProductionHoliday::factory()->for($fixture['workspace'])->create([
        'date' => '2026-08-11',
        'is_recurring' => false,
    ]);

    $simulation = app(FlashProductionSimulator::class)->simulate($fixture['workspace'], [[
        'recipe_id' => $fixture['recipe']->id,
        'desired_units' => '150',
        'expected_units_per_batch' => '100',
        'basis_input_value' => '12',
        'basis_input_unit' => 'kg',
        'task_set_id' => $fixture['taskSet']->id,
    ]]);

    $proposals = app(FlashDateProposalService::class)->propose(
        workspace: $fixture['workspace'],
        lines: $simulation['lines'],
        firstDate: '2026-08-08',
        batchesPerDay: 1,
    );

    expect($proposals)->toHaveCount(2)
        ->and($proposals[0]['production_date'])->toBe('2026-08-10')
        ->and($proposals[0]['tasks'][0]['scheduled_for'])->toBe('2026-08-10')
        ->and($proposals[0]['tasks'][1]['scheduled_for'])->toBe('2026-08-12')
        ->and($proposals[1]['production_date'])->toBe('2026-08-12');
});

/** @return array{workspace: Workspace, recipe: Recipe, ingredient: Ingredient, packaging: PackagingItem, taskSet: ProductionTaskSet} */
function flashSimulatorFixture(bool $withPrices = true): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['default_currency' => 'EUR']);
    $family = ProductFamily::factory()->create([
        'calculation_basis' => 'initial_oils',
        'slug' => 'flash-family-'.fake()->unique()->numberBetween(1, 999999),
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Flash soap',
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'version_number' => 1,
        'is_current' => false,
    ]);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Flash oil']);
    $phase = RecipePhase::factory()->for($version)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Oils',
        'slug' => 'oils',
    ]);
    RecipeItem::factory()->for($version)->for($phase, 'recipePhase')->for($ingredient)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'percentage' => '100.0000',
    ]);
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Flash box']);
    RecipeVersionPackagingItem::query()->create([
        'recipe_version_id' => $version->id,
        'packaging_item_id' => $packaging->id,
        'name' => 'Flash box',
        'components_per_unit' => '1.000',
        'position' => 1,
    ]);
    $lot = StockLot::factory()->released()->for($workspace)->for($ingredient)->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '5000.000000000',
        'original_quantity' => '5',
        'original_unit' => 'kg',
    ]);
    $taskSet = ProductionTaskSet::factory()->for($workspace)->create(['name' => 'Flash tasks']);
    $firstType = ProductionTaskType::factory()->for($workspace)->create(['name' => 'Make', 'default_duration_minutes' => 10]);
    $secondType = ProductionTaskType::factory()->for($workspace)->create(['name' => 'Cure', 'default_duration_minutes' => 20]);
    ProductionTaskSetItem::factory()->for($taskSet, 'taskSet')->for($firstType, 'taskType')->create([
        'position' => 1,
        'days_after_production' => 0,
        'duration_minutes' => 10,
    ]);
    ProductionTaskSetItem::factory()->for($taskSet, 'taskSet')->for($secondType, 'taskType')->create([
        'position' => 2,
        'days_after_production' => 1,
        'duration_minutes' => 5,
    ]);

    if ($withPrices) {
        CurrentMaterialPrice::factory()->create([
            'workspace_id' => $workspace->id,
            'ingredient_id' => $ingredient->id,
            'packaging_item_id' => null,
            'price_per_canonical_unit' => '0.010000000000',
            'currency' => 'EUR',
        ]);
        CurrentMaterialPrice::factory()->create([
            'workspace_id' => $workspace->id,
            'ingredient_id' => null,
            'packaging_item_id' => $packaging->id,
            'price_per_canonical_unit' => '1.000000000000',
            'currency' => 'EUR',
        ]);
    }

    return compact('workspace', 'recipe', 'ingredient', 'packaging', 'taskSet');
}
