<?php

use App\Enums\MassDisplaySystem;
use App\Enums\MassUnit;
use App\Enums\OwnerType;
use App\Enums\StockMovementType;
use App\Enums\Visibility;
use App\Livewire\ProductionBench\Production\FlashPlanner;
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
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\FlashDateProposalService;
use App\Services\Production\FlashProductionSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('simulates multiple product lines and aggregate material requirements without reading stock or writing', function (): void {
    $fixture = flashSimulatorFixture();

    $beforeRuns = $fixture['workspace']->productionRuns()->count();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });
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
        ->task_minutes->toBe(45);

    $ingredient = collect($result['requirements'])->firstWhere('ingredient_id', $fixture['ingredient']->id);
    $packaging = collect($result['requirements'])->firstWhere('packaging_item_id', $fixture['packaging']->id);

    expect($ingredient['required'])->toBe('36000.000000000')
        ->and($ingredient)->toHaveKeys(['ingredient_id', 'subject_name', 'required', 'required_display', 'display_unit', 'unit_price', 'display_unit_price', 'price_currency', 'estimated_cost'])
        ->and($ingredient['required_display'])->toBe('36.000000000')
        ->and($ingredient['display_unit'])->toBe('kg')
        ->and($ingredient['display_unit_price'])->toBe('10.000000000')
        ->and($ingredient['estimated_cost'])->toBe('360.000000000')
        ->and($ingredient)->not->toHaveKey('available')
        ->and($ingredient)->not->toHaveKey('incoming')
        ->and($ingredient)->not->toHaveKey('shortage')
        ->and($ingredient)->not->toHaveKey('indicative_value');
    expect($packaging['required'])->toBe('300.000000000')
        ->and($packaging)->toHaveKeys(['packaging_item_id', 'subject_name', 'required', 'required_display', 'display_unit', 'unit_price', 'display_unit_price', 'price_currency', 'estimated_cost'])
        ->and($packaging['estimated_cost'])->toBe('300.000000000')
        ->and($packaging)->not->toHaveKey('available')
        ->and($packaging)->not->toHaveKey('incoming')
        ->and($packaging)->not->toHaveKey('shortage')
        ->and($packaging)->not->toHaveKey('indicative_value');

    expect($result['totals'])
        ->budget->toBe('660.000000000')
        ->budget_currency->toBe('EUR')
        ->missing_prices->toBe(0);
    expect($fixture['workspace']->productionRuns()->count())->toBe($beforeRuns);
    expect(collect($queries)->filter(fn (string $query): bool => str_starts_with($query, 'select * from "recipes"')))
        ->toHaveCount(1)
        ->and(collect($queries)->filter(fn (string $query): bool => str_contains($query, 'production_task_set_recipe')))
        ->toHaveCount(1);
});

it('reports missing current prices without reading stock coverage', function (): void {
    $fixture = flashSimulatorFixture(withPrices: false);
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $result = app(FlashProductionSimulator::class)->simulate($fixture['workspace'], [[
        'recipe_id' => $fixture['recipe']->id,
        'desired_units' => '10',
        'expected_units_per_batch' => '100',
        'basis_input_value' => '12',
        'basis_input_unit' => 'kg',
    ]]);

    expect($result['totals'])
        ->budget->toBeNull()
        ->budget_currency->toBeNull()
        ->missing_prices->toBe(2)
        ->and(collect($result['requirements'])->every(fn (array $row): bool => $row['estimated_cost'] === null))->toBeTrue()
        ->and(collect($queries)->filter(fn (string $query): bool => str_contains($query, 'stock_lots') || str_contains($query, 'stock_movements')))->toBeEmpty();
});

it('renders a locale-aware budget and display-unit requirements in Flash', function (): void {
    $fixture = flashSimulatorFixture();
    $fixture['owner']->update(['number_locale' => 'fr_FR']);
    $fixture['workspace']->update(['mass_display_system' => MassDisplaySystem::Metric]);
    Livewire::actingAs($fixture['owner'])->test(FlashPlanner::class)
        ->set('lines.0.recipe_id', (string) $fixture['recipe']->id)
        ->set('lines.0.desired_units', '25')
        ->set('lines.0.basis_input_value', '12,5')
        ->set('lines.0.basis_input_unit', 'kg')
        ->set('lines.0.expected_units_per_batch', '100')
        ->call('previewDates')
        ->assertSee('12,50 kg')
        ->assertSee('125,00 EUR')
        ->assertSee('225,00 EUR')
        ->assertSee('Make');
});

it('includes task type default durations when a task item has no override', function (): void {
    $fixture = flashSimulatorFixture();
    $fixture['taskSet']->items()->where('position', 2)->firstOrFail()->update(['duration_minutes' => null]);

    $result = app(FlashProductionSimulator::class)->simulate($fixture['workspace'], [[
        'recipe_id' => $fixture['recipe']->id,
        'desired_units' => '150',
        'expected_units_per_batch' => '100',
        'basis_input_value' => '12',
        'basis_input_unit' => 'kg',
        'task_set_id' => $fixture['taskSet']->id,
    ]]);

    expect($result['lines'][0]['task_minutes'])->toBe(30)
        ->and($result['totals']['task_minutes'])->toBe(60);
});

it('rejects flash simulations that exceed the batch limit', function (): void {
    $fixture = flashSimulatorFixture();

    expect(fn () => app(FlashProductionSimulator::class)->simulate($fixture['workspace'], [[
        'recipe_id' => $fixture['recipe']->id,
        'desired_units' => '1001',
        'expected_units_per_batch' => '1',
        'basis_input_value' => '12',
        'basis_input_unit' => 'kg',
    ]]))
        ->toThrow(ValidationException::class);
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

/** @return array{owner: User, workspace: Workspace, recipe: Recipe, ingredient: Ingredient, packaging: PackagingItem, taskSet: ProductionTaskSet} */
function flashSimulatorFixture(bool $withPrices = true): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['default_currency' => 'EUR']);
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
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

    $taskSet->recipes()->attach($recipe->id, ['is_default' => true]);

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

    return compact('owner', 'workspace', 'recipe', 'ingredient', 'packaging', 'taskSet');
}
