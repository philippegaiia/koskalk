<?php

use App\Enums\MassUnit;
use App\Enums\OwnerType;
use App\Enums\ProductionBasisKind;
use App\Enums\ProductionBenchEntitlementStatus;
use App\Enums\ProductionConsumptionKind;
use App\Enums\ProductionFormulaComponent;
use App\Enums\ProductionOutputType;
use App\Enums\ProductionRequirementKind;
use App\Enums\ProductionRunSource;
use App\Enums\ProductionRunStatus;
use App\Enums\StockLotOrigin;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionConsumption;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionJournalEntry;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the production planning tables with their durable snapshot columns', function (): void {
    expect(Schema::hasColumns('production_runs', [
        'public_id',
        'workspace_id',
        'recipe_id',
        'recipe_version_id',
        'status',
        'source',
        'planned_for',
        'basis_kind',
        'basis_quantity_grams',
        'basis_input_value',
        'basis_input_unit',
        'expected_units',
        'notes',
        'idempotency_key',
        'created_by_user_id',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_reason',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('production_requirements', [
            'production_run_id',
            'ingredient_id',
            'packaging_item_id',
            'recipe_item_id',
            'recipe_version_packaging_item_id',
            'kind',
            'required_mass_grams',
            'required_units',
            'subject_name_snapshot',
            'phase_key_snapshot',
            'phase_name_snapshot',
            'percentage_snapshot',
            'components_per_unit_snapshot',
            'unit_snapshot',
            'sort_order',
        ]))->toBeTrue();
});

it('stores production output configuration, readiness estimates, and output settings', function (): void {
    expect(array_column(ProductionOutputType::cases(), 'value'))->toBe([
        'finished_product',
        'manufactured_ingredient',
    ])
        ->and(Schema::hasColumns('recipes', [
            'production_output_type',
            'output_ingredient_id',
            'ready_delay_days',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('production_runs', [
            'production_output_type',
            'output_ingredient_id',
            'output_ready_delay_days',
            'estimated_ready_on',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('stock_lots', 'estimated_ready_on'))->toBeTrue()
        ->and(Schema::hasTable('production_output_settings'))->toBeTrue()
        ->and(Schema::hasTable('production_run_number_issuances'))->toBeTrue();
});

it('restores output availability when the output configuration migration is rolled back', function (): void {
    $lot = StockLot::factory()->create([
        'origin' => StockLotOrigin::ProductionOutput,
        'available_from' => null,
        'estimated_ready_on' => '2026-09-10',
    ]);
    $migration = require database_path('migrations/2026_08_10_231100_add_output_configuration_to_recipes_runs_and_lots.php');
    $indexMigration = require database_path('migrations/2026_08_11_095609_add_production_foreign_key_indexes.php');
    $followUpIndexMigration = require database_path('migrations/2026_08_12_063613_add_missing_production_foreign_key_indexes.php');

    $followUpIndexMigration->down();
    $indexMigration->down();
    $migration->down();

    expect(substr((string) DB::table('stock_lots')->where('id', $lot->id)->value('available_from'), 0, 10))
        ->toBe('2026-09-10');

    $migration->up();
    $indexMigration->up();
    $followUpIndexMigration->up();
});

it('defines the complete production planning enum contract', function (): void {
    expect(array_column(ProductionRunStatus::cases(), 'value'))->toBe([
        'draft',
        'scheduled',
        'reserved',
        'in_production',
        'completed',
        'cancelled',
        'aborted',
    ])->and(array_column(ProductionRunSource::cases(), 'value'))->toBe([
        'direct',
        'flash',
    ])->and(array_column(ProductionBasisKind::cases(), 'value'))->toBe([
        'oil_mass',
        'total_formula_mass',
    ])->and(array_column(ProductionRequirementKind::cases(), 'value'))->toBe([
        'ingredient',
        'packaging',
    ]);
});

it('provides the customer-facing production status labels', function (): void {
    expect(method_exists(ProductionRunStatus::class, 'label'))->toBeTrue();

    expect(array_map(
        fn (ProductionRunStatus $status): string => $status->label(),
        ProductionRunStatus::cases(),
    ))->toBe([
        'Draft',
        'Planned',
        'Stock prepared',
        'In production',
        'Completed',
        'Cancelled',
        'Aborted',
    ]);
});

it('casts production values precisely and exposes the complete relationship graph', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $recipe = productionPlanningRecipe($workspace);
    $version = productionPlanningVersion($recipe, $workspace);
    $cancelledBy = User::factory()->create();

    $production = ProductionRun::factory()
        ->for($workspace)
        ->for($recipe)
        ->for($version, 'recipeVersion')
        ->for($owner, 'createdBy')
        ->for($cancelledBy, 'cancelledBy')
        ->create([
            'status' => ProductionRunStatus::Cancelled,
            'source' => ProductionRunSource::Flash,
            'planned_for' => '2026-08-20',
            'basis_kind' => ProductionBasisKind::OilMass,
            'basis_quantity_grams' => '12000.123456789',
            'basis_input_value' => '12.000123457',
            'basis_input_unit' => 'kg',
            'expected_units' => 100,
            'cancelled_at' => '2026-08-10 10:30:00',
        ]);

    $ingredientRequirement = ProductionRequirement::factory()
        ->for($production)
        ->create([
            'sort_order' => 2,
            'required_mass_grams' => '0.123456789',
        ]);
    $packagingRequirement = ProductionRequirement::factory()
        ->for($production)
        ->forPackaging()
        ->create([
            'sort_order' => 1,
            'required_units' => 100,
        ]);

    expect($production->public_id)->not->toBeEmpty()
        ->and($production->getRouteKeyName())->toBe('public_id')
        ->and($production->status)->toBe(ProductionRunStatus::Cancelled)
        ->and($production->source)->toBe(ProductionRunSource::Flash)
        ->and($production->basis_kind)->toBe(ProductionBasisKind::OilMass)
        ->and($production->planned_for?->toDateString())->toBe('2026-08-20')
        ->and($production->basis_quantity_grams)->toBe('12000.123456789')
        ->and($production->basis_input_value)->toBe('12.000123457')
        ->and($production->basis_input_unit)->toBe(MassUnit::Kilogram)
        ->and($production->expected_units)->toBe(100)
        ->and($production->workspace->is($workspace))->toBeTrue()
        ->and($production->recipe->is($recipe))->toBeTrue()
        ->and($production->recipeVersion->is($version))->toBeTrue()
        ->and($production->createdBy->is($owner))->toBeTrue()
        ->and($production->cancelledBy->is($cancelledBy))->toBeTrue()
        ->and($production->requirements->modelKeys())->toBe([
            $packagingRequirement->id,
            $ingredientRequirement->id,
        ])
        ->and($ingredientRequirement->kind)->toBe(ProductionRequirementKind::Ingredient)
        ->and($ingredientRequirement->required_mass_grams)->toBe('0.123456789')
        ->and($ingredientRequirement->required_units)->toBeNull()
        ->and($packagingRequirement->kind)->toBe(ProductionRequirementKind::Packaging)
        ->and($packagingRequirement->required_units)->toBe(100)
        ->and($packagingRequirement->packagingItem->workspace_id)->toBe($workspace->id)
        ->and($workspace->productionRuns->contains($production))->toBeTrue()
        ->and($recipe->productionRuns->contains($production))->toBeTrue()
        ->and($version->productionRuns->contains($production))->toBeTrue();
});

it('builds a coherent published production factory graph', function (): void {
    $production = ProductionRun::factory()->create();

    expect($production->workspace_id)->toBe($production->recipe->workspace_id)
        ->and($production->recipe_id)->toBe($production->recipeVersion->recipe_id)
        ->and($production->workspace_id)->toBe($production->recipeVersion->workspace_id)
        ->and($production->recipeVersion->is_current)->toBeFalse()
        ->and($production->created_by_user_id)->toBe($production->workspace->owner_user_id);
});

it('keeps production idempotency keys unique inside one workspace only', function (): void {
    $firstWorkspace = Workspace::factory()->create();
    $secondWorkspace = Workspace::factory()->create();

    ProductionRun::factory()->for($firstWorkspace)->create([
        'idempotency_key' => 'plan-production-42',
    ]);
    ProductionRun::factory()->for($secondWorkspace)->create([
        'idempotency_key' => 'plan-production-42',
    ]);

    expect(fn (): ProductionRun => ProductionRun::factory()->for($firstWorkspace)->create([
        'idempotency_key' => 'plan-production-42',
    ]))->toThrow(QueryException::class);
});

it('requires positive production quantities', function (array $attributes): void {
    expect(fn (): ProductionRun => ProductionRun::factory()->create($attributes))
        ->toThrow(QueryException::class);
})->with([
    'zero canonical basis' => [['basis_quantity_grams' => '0']],
    'negative entered basis' => [['basis_input_value' => '-1']],
    'zero expected units' => [['expected_units' => 0]],
]);

it('enforces production value integrity on updates', function (): void {
    $production = ProductionRun::factory()->create();

    expect(fn (): int => DB::table('production_runs')
        ->where('id', $production->id)
        ->update(['basis_quantity_grams' => '0']))->toThrow(QueryException::class);
});

it('requires exactly one correctly typed requirement subject and quantity', function (): void {
    $production = ProductionRun::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $packagingItem = PackagingItem::factory()->for($production->workspace)->create();

    ProductionRequirement::factory()
        ->for($production)
        ->for($ingredient)
        ->create(['required_mass_grams' => '0.000000001']);
    ProductionRequirement::factory()
        ->for($production)
        ->forPackaging($packagingItem)
        ->create(['required_units' => 2]);

    $invalidRows = [
        'both subjects' => [
            'ingredient_id' => $ingredient->id,
            'packaging_item_id' => $packagingItem->id,
        ],
        'no subject' => [
            'ingredient_id' => null,
            'packaging_item_id' => null,
        ],
        'ingredient with count kind' => [
            'ingredient_id' => $ingredient->id,
            'packaging_item_id' => null,
            'kind' => ProductionRequirementKind::Packaging,
        ],
        'ingredient with count quantity' => [
            'ingredient_id' => $ingredient->id,
            'packaging_item_id' => null,
            'kind' => ProductionRequirementKind::Ingredient,
            'required_mass_grams' => null,
            'required_units' => 1,
        ],
        'ingredient without a quantity' => [
            'ingredient_id' => $ingredient->id,
            'packaging_item_id' => null,
            'kind' => ProductionRequirementKind::Ingredient,
            'required_mass_grams' => null,
            'required_units' => null,
        ],
        'packaging with mass quantity' => [
            'ingredient_id' => null,
            'packaging_item_id' => $packagingItem->id,
            'kind' => ProductionRequirementKind::Packaging,
            'required_mass_grams' => '1',
            'required_units' => null,
        ],
        'zero mass' => [
            'required_mass_grams' => '0',
        ],
        'zero units' => [
            'ingredient_id' => null,
            'packaging_item_id' => $packagingItem->id,
            'kind' => ProductionRequirementKind::Packaging,
            'required_mass_grams' => null,
            'required_units' => 0,
        ],
        'packaging without a quantity' => [
            'ingredient_id' => null,
            'packaging_item_id' => $packagingItem->id,
            'kind' => ProductionRequirementKind::Packaging,
            'required_mass_grams' => null,
            'required_units' => null,
        ],
    ];

    foreach ($invalidRows as $attributes) {
        expect(fn (): ProductionRequirement => ProductionRequirement::factory()
            ->for($production)
            ->for($ingredient)
            ->create($attributes))->toThrow(QueryException::class);
    }
});

it('rejects fractional packaging quantities at the database boundary', function (): void {
    $production = ProductionRun::factory()->create();
    $packagingItem = PackagingItem::factory()->for($production->workspace)->create();

    expect(fn (): bool => DB::table('production_requirements')->insert([
        'production_run_id' => $production->id,
        'ingredient_id' => null,
        'packaging_item_id' => $packagingItem->id,
        'recipe_item_id' => null,
        'recipe_version_packaging_item_id' => null,
        'kind' => ProductionRequirementKind::Packaging->value,
        'required_mass_grams' => null,
        'required_units' => 2.5,
        'subject_name_snapshot' => 'Bottle',
        'phase_key_snapshot' => null,
        'phase_name_snapshot' => null,
        'percentage_snapshot' => null,
        'components_per_unit_snapshot' => '1.000000000',
        'unit_snapshot' => 'unit',
        'sort_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces requirement integrity on updates as well as inserts', function (): void {
    $requirement = ProductionRequirement::factory()->create();

    expect(fn (): bool => DB::table('production_requirements')
        ->where('id', $requirement->id)
        ->update([
            'required_mass_grams' => null,
            'required_units' => 1,
        ]))->toThrow(QueryException::class);

    expect(fn (): int => DB::table('production_requirements')
        ->where('id', $requirement->id)
        ->update([
            'required_mass_grams' => null,
            'required_units' => null,
        ]))->toThrow(QueryException::class);
});

it('authorizes production records by workspace role and active entitlement', function (): void {
    $owner = User::factory()->create();
    $editor = User::factory()->create();
    $viewer = User::factory()->create();
    $outsider = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $production = ProductionRun::factory()->for($workspace)->create();

    expect(Gate::forUser($owner)->allows('view', $production))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $production))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('view', $production))->toBeTrue()
        ->and(Gate::forUser($outsider)->allows('view', $production))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', [ProductionRun::class, $workspace]))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('update', $production))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('update', $production))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $production))->toBeFalse();

    $workspace->productionEntitlement()->update([
        'status' => ProductionBenchEntitlementStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    expect(Gate::forUser($owner)->allows('view', $production))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', [ProductionRun::class, $workspace]))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $production))->toBeFalse();
});

it('creates the independent production formula snapshot schema', function (): void {
    expect(Schema::hasColumns('production_runs', [
        'recipe_name_snapshot',
        'source_formula_version_number',
        'formula_context_snapshot',
        'formula_snapshot_completed_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('production_formula_lines', [
            'production_run_id',
            'ingredient_id',
            'recipe_item_id',
            'component',
            'subject_name_snapshot',
            'phase_key_snapshot',
            'phase_name_snapshot',
            'basis_percentage_snapshot',
            'planned_mass_grams',
            'note_snapshot',
            'sort_order',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('production_requirements', 'note_snapshot'))->toBeTrue();
});

it('defines the complete formula component enum contract', function (): void {
    expect(array_column(ProductionFormulaComponent::cases(), 'value'))->toBe([
        'ingredient',
        'naoh',
        'koh',
        'water',
    ]);
});

it('exposes the formula line relationship in the production aggregate', function (): void {
    $production = ProductionRun::factory()->create();

    $line = ProductionFormulaLine::factory()->for($production, 'productionRun')->create([
        'component' => ProductionFormulaComponent::Ingredient,
        'basis_percentage_snapshot' => '25.000000000',
        'planned_mass_grams' => '2500.000000000',
    ]);

    expect($production->formulaLines()->sole()->is($line))->toBeTrue()
        ->and($production->displayRecipeName())->toBe($production->recipe->name);
});

it('keeps formula snapshots readable after recipe and version deletion', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $recipe = productionPlanningRecipe($workspace);
    $version = productionPlanningVersion($recipe, $workspace);
    $ingredient = Ingredient::factory()->create();

    $production = ProductionRun::factory()
        ->for($workspace)
        ->for($recipe)
        ->for($version, 'recipeVersion')
        ->create([
            'recipe_name_snapshot' => 'Historical Soap',
            'source_formula_version_number' => 3,
            'formula_context_snapshot' => ['lye_type' => 'naoh', 'superfat_percentage' => 5],
            'formula_snapshot_completed_at' => now(),
        ]);

    $line = ProductionFormulaLine::factory()->for($production, 'productionRun')->create([
        'component' => ProductionFormulaComponent::Ingredient,
        'ingredient_id' => $ingredient->id,
        'recipe_item_id' => null,
        'subject_name_snapshot' => $ingredient->display_name,
        'basis_percentage_snapshot' => '25.000000000',
        'planned_mass_grams' => '2500.000000000',
    ]);

    Recipe::withoutGlobalScopes()->findOrFail($recipe->id)->delete();

    $production->refresh();

    expect($production->recipe_id)->toBeNull()
        ->and($production->recipe_version_id)->toBeNull()
        ->and($production->displayRecipeName())->toBe('Historical Soap')
        ->and($production->formulaLines()->count())->toBe(1)
        ->and($production->formulaLines()->sole()->is($line))->toBeTrue()
        ->and($production->formulaLines()->sole()->ingredient_id)->toBe($ingredient->id)
        ->and($production->formulaLines()->sole()->subject_name_snapshot)->toBe($ingredient->display_name);

    $ingredient->delete();

    expect($production->formulaLines()->sole()->ingredient_id)->toBeNull()
        ->and($production->formulaLines()->sole()->subject_name_snapshot)->toBe($ingredient->display_name);
});

it('enforces formula line component and quantity integrity', function (): void {
    $production = ProductionRun::factory()->create();

    expect(fn (): ProductionFormulaLine => ProductionFormulaLine::factory()
        ->for($production, 'productionRun')
        ->create(['component' => 'scent']))->toThrow(ValueError::class);

    expect(fn (): bool => DB::table('production_formula_lines')->insert([
        'production_run_id' => $production->id,
        'ingredient_id' => null,
        'recipe_item_id' => null,
        'component' => 'scent',
        'subject_name_snapshot' => 'Scent',
        'phase_key_snapshot' => 'main',
        'phase_name_snapshot' => 'Main',
        'basis_percentage_snapshot' => '1.000000000',
        'planned_mass_grams' => '10.000000000',
        'note_snapshot' => null,
        'sort_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn (): ProductionFormulaLine => ProductionFormulaLine::factory()
        ->for($production, 'productionRun')
        ->create([
            'component' => ProductionFormulaComponent::Naoh,
            'basis_percentage_snapshot' => '0',
        ]))->toThrow(QueryException::class);

    expect(fn (): ProductionFormulaLine => ProductionFormulaLine::factory()
        ->for($production, 'productionRun')
        ->create([
            'component' => ProductionFormulaComponent::Water,
            'planned_mass_grams' => '-1',
        ]))->toThrow(QueryException::class);

    expect(fn (): ProductionFormulaLine => ProductionFormulaLine::factory()
        ->for($production, 'productionRun')
        ->create(['sort_order' => 0]))->toThrow(QueryException::class);
});

it('creates the production execution schema', function (): void {
    expect(Schema::hasColumns('production_runs', [
        'started_at',
        'started_by_user_id',
        'completed_at',
        'completed_by_user_id',
        'aborted_at',
        'aborted_by_user_id',
        'abort_reason',
        'manufacture_date',
        'actual_output_units',
        'actual_output_mass_grams',
        'cost_currency',
        'actual_ingredient_total',
        'actual_packaging_total',
        'actual_total_cost',
        'actual_cost_per_unit',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('production_consumption', [
            'production_run_id',
            'production_requirement_id',
            'stock_lot_id',
            'kind',
            'subject_name_snapshot',
            'quantity',
            'unit_snapshot',
            'price_per_unit',
            'line_cost',
            'recorded_by_user_id',
            'note',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('production_journal_entries', [
            'production_run_id',
            'body',
            'created_by_user_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('stock_lots', 'production_run_id'))->toBeTrue();
});

it('defines the complete consumption kind enum contract', function (): void {
    expect(array_column(ProductionConsumptionKind::cases(), 'value'))->toBe([
        'ingredient',
        'packaging',
    ]);
});

it('exposes the execution relationships on the production aggregate', function (): void {
    $production = ProductionRun::factory()->create();
    $consumption = ProductionConsumption::factory()->for($production, 'productionRun')->create();
    $entry = ProductionJournalEntry::factory()->for($production, 'productionRun')->create();

    expect($production->consumption()->sole()->is($consumption))->toBeTrue()
        ->and($production->journalEntries()->sole()->is($entry))->toBeTrue()
        ->and($production->outputLot)->toBeNull();
});

it('enforces consumption subject, unit, and quantity integrity', function (): void {
    $production = ProductionRun::factory()->create();

    expect(fn (): ProductionConsumption => ProductionConsumption::factory()
        ->for($production, 'productionRun')
        ->create(['kind' => ProductionConsumptionKind::Ingredient, 'unit_snapshot' => 'unit']))
        ->toThrow(QueryException::class);

    expect(fn (): ProductionConsumption => ProductionConsumption::factory()
        ->for($production, 'productionRun')
        ->create(['kind' => ProductionConsumptionKind::Packaging, 'quantity' => '2.5']))
        ->toThrow(QueryException::class);

    expect(fn (): ProductionConsumption => ProductionConsumption::factory()
        ->for($production, 'productionRun')
        ->create(['quantity' => '0']))
        ->toThrow(QueryException::class);

    expect(fn (): ProductionConsumption => ProductionConsumption::factory()
        ->for($production, 'productionRun')
        ->create(['kind' => 'scent']))
        ->toThrow(ValueError::class);
});

it('rejects a production with both output kinds recorded', function (): void {
    $production = ProductionRun::factory()->create();

    expect(fn (): bool => DB::table('production_runs')
        ->where('id', $production->id)
        ->update([
            'actual_output_units' => 10,
            'actual_output_mass_grams' => '1000.000000000',
        ]))->toThrow(QueryException::class);
});

it('cascades consumption and journal rows when a production is deleted', function (): void {
    $production = ProductionRun::factory()->create();
    $consumption = ProductionConsumption::factory()->for($production, 'productionRun')->create();
    $entry = ProductionJournalEntry::factory()->for($production, 'productionRun')->create();

    $production->delete();

    expect(ProductionConsumption::query()->find($consumption->id))->toBeNull()
        ->and(ProductionJournalEntry::query()->find($entry->id))->toBeNull();
});

it('allows stock lots for finished products and intermediates as subjects', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $recipe = productionPlanningRecipe($workspace);

    $productLot = StockLot::factory()->for($workspace)->forRecipe()->create([
        'recipe_id' => $recipe->id,
        'origin' => 'production_output',
    ]);

    expect($productLot->recipe_id)->toBe($recipe->id)
        ->and($productLot->subjectName())->toBe($recipe->name);

    expect(fn (): StockLot => StockLot::factory()->for($workspace)->forRecipe()->create([
        'recipe_id' => $recipe->id,
        'ingredient_id' => Ingredient::factory()->create()->id,
    ]))->toThrow(QueryException::class);
});

it('enforces completion fields together and non-negative totals at the database', function (): void {
    $production = ProductionRun::factory()->create();

    expect(fn (): bool => DB::table('production_runs')
        ->where('id', $production->id)
        ->update(['completed_at' => now()]))->toThrow(QueryException::class);

    expect(fn (): bool => DB::table('production_runs')
        ->where('id', $production->id)
        ->update([
            'completed_at' => now(),
            'completed_by_user_id' => 1,
            'manufacture_date' => '2026-08-20',
            'actual_ingredient_total' => '0.000000000',
            'actual_packaging_total' => '0.000000000',
            'actual_total_cost' => '-1.000000000',
        ]))->toThrow(QueryException::class);
});

it('allows at most one output lot per production run', function (): void {
    $production = ProductionRun::factory()->create();
    StockLot::factory()->for($production->workspace)->forRecipe()->create([
        'production_run_id' => $production->id,
        'origin' => 'production_output',
    ]);

    expect(fn (): StockLot => StockLot::factory()->for($production->workspace)->forRecipe()->create([
        'production_run_id' => $production->id,
        'origin' => 'production_output',
    ]))->toThrow(QueryException::class);
});

it('allows at most one consumption row per requirement and lot', function (): void {
    $production = ProductionRun::factory()->create();
    $requirement = ProductionRequirement::factory()->for($production, 'productionRun')->create();
    $lot = StockLot::factory()->for($production->workspace)->released()->create([
        'ingredient_id' => Ingredient::factory()->create()->id,
        'packaging_item_id' => null,
        'unit_kind' => 'mass',
    ]);
    ProductionConsumption::factory()->for($production, 'productionRun')->create([
        'production_requirement_id' => $requirement->id,
        'stock_lot_id' => $lot->id,
    ]);

    expect(fn (): ProductionConsumption => ProductionConsumption::factory()->for($production, 'productionRun')->create([
        'production_requirement_id' => $requirement->id,
        'stock_lot_id' => $lot->id,
    ]))->toThrow(QueryException::class);
});

it('allows non-output lots to share the same production run link', function (): void {
    $production = ProductionRun::factory()->create();

    StockLot::factory()->for($production->workspace)->released()->create([
        'ingredient_id' => Ingredient::factory()->create()->id,
        'packaging_item_id' => null,
        'unit_kind' => 'mass',
        'production_run_id' => $production->id,
        'origin' => 'opening_balance',
    ]);
    StockLot::factory()->for($production->workspace)->released()->create([
        'ingredient_id' => Ingredient::factory()->create()->id,
        'packaging_item_id' => null,
        'unit_kind' => 'mass',
        'production_run_id' => $production->id,
        'origin' => 'adjustment',
    ]);

    expect(StockLot::query()->where('production_run_id', $production->id)->count())->toBe(2);
});

function productionPlanningRecipe(Workspace $workspace): Recipe
{
    return Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
}

function productionPlanningVersion(Recipe $recipe, Workspace $workspace): RecipeVersion
{
    return RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
    ]);
}
