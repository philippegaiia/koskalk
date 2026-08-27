<?php

use App\Enums\ProductionFormulaComponent;
use App\Enums\ProductionRunStatus;
use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Enums\StockReservationStatus;
use App\Enums\StockUnitKind;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionConsumption;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\StockLot;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionDetailPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('presents one material row per formula or packaging item with reservation and actual state', function (): void {
    $fixture = productionDetailPresenterFixture();
    $production = $fixture['production'];

    $data = app(ProductionDetailPresenter::class)->present(
        production: $production->load([
            'requirements.reservations.stockLot',
            'formulaLines',
            'consumption.stockLot',
            'tasks',
        ]),
        actualRows: [
            $fixture['oilRequirement']->id.'-'.$fixture['oilLotA']->id => [
                'stock_lot_id' => $fixture['oilLotA']->id,
                'quantity' => '640.000000000',
                'note' => 'Spillage removed',
            ],
            $fixture['oilRequirement']->id.'-'.$fixture['oilLotB']->id => [
                'stock_lot_id' => $fixture['oilLotB']->id,
                'quantity' => '250.000000000',
                'note' => null,
            ],
            $fixture['lyeRequirement']->id.'-'.$fixture['lyeLot']->id => [
                'stock_lot_id' => $fixture['lyeLot']->id,
                'quantity' => '105.000000000',
                'note' => null,
            ],
            $fixture['packagingRequirement']->id.'-'.$fixture['packagingLot']->id => [
                'stock_lot_id' => $fixture['packagingLot']->id,
                'quantity' => '98',
                'note' => null,
            ],
        ],
        calculatedActualRows: [
            (string) $fixture['waterLine']->id => ['actual_mass_grams' => '196.000000000'],
        ],
    );

    expect($data['identity'])
        ->toMatchArray([
            'identifier' => 'PLAN-001',
            'product_name' => 'Olive soap',
            'planned_for' => '2026-08-12',
        ])
        ->and($data['lifecycle'][0]['state'])->toBe('completed')
        ->and($data['lifecycle'][1]['state'])->toBe('completed')
        ->and($data['lifecycle'][2]['state'])->toBe('completed')
        ->and($data['lifecycle'][3]['state'])->toBe('current')
        ->and($data['primary_action'])->toBe('complete')
        ->and($data['materials'])->toHaveCount(4)
        ->and(array_column($data['materials'], 'material_name'))->toBe([
            'Olive oil',
            'Sodium hydroxide',
            'Water',
            'Box',
        ]);

    $oil = $data['materials'][0];
    $water = $data['materials'][2];
    $packaging = $data['materials'][3];

    expect($oil['percentage'])->toBe('50%')
        ->and($oil['planned'])->toBe(['quantity' => '1000', 'unit' => 'g'])
        ->and($oil['reservation']['tracked'])->toBeTrue()
        ->and($oil['reservation']['total'])->toBe('900')
        ->and($oil['reservation']['lots'])->toHaveCount(2)
        ->and($oil['actual']['mode'])->toBe('editable')
        ->and($oil['actual']['rows'])->toHaveCount(2)
        ->and($oil['actual']['rows'][0]['state_key'])->toContain('actualRows.')
        ->and($water['reservation'])->toMatchArray([
            'tracked' => false,
            'total' => null,
            'lots' => [],
        ])
        ->and($water['actual']['rows'][0]['quantity'])->toBe('196.000000000')
        ->and($packaging['planned'])->toBe(['quantity' => '100', 'unit' => 'units'])
        ->and($packaging['material_code'])->toBe('PK-BOX')
        ->and($packaging['actual']['rows'][0]['quantity'])->toBe('98');
});

it('presents terminal lifecycle state and output release readiness', function (): void {
    $fixture = productionDetailPresenterFixture();
    $production = $fixture['production'];
    $production->update([
        'status' => ProductionRunStatus::Completed,
        'actual_output_units' => 283,
        'completed_at' => now(),
        'completed_by_user_id' => $fixture['owner']->id,
        'manufacture_date' => today(),
        'actual_ingredient_total' => '0',
        'actual_packaging_total' => '0',
        'actual_total_cost' => '0',
        'actual_cost_per_unit' => '0',
    ]);
    $outputLot = StockLot::factory()->for($fixture['workspace'])->forRecipe()->create([
        'production_run_id' => $production->id,
        'recipe_id' => $production->recipe_id,
        'origin' => StockLotOrigin::ProductionOutput,
        'unit_kind' => StockUnitKind::Count,
        'status' => StockLotStatus::Quarantined,
        'estimated_ready_on' => today()->subDay(),
    ]);
    ProductionTask::factory()->for($fixture['workspace'])->for($production, 'productionRun')->create([
        'name_snapshot' => 'Final quality check',
        'scheduled_for' => today()->subDay(),
        'completed_at' => now(),
    ]);

    $data = app(ProductionDetailPresenter::class)->present(
        production: $production->fresh()->load(['outputLot', 'tasks']),
    );

    expect($data['lifecycle'][4]['state'])->toBe('current')
        ->and($data['primary_action'])->toBe('release_batch')
        ->and($data['output'])
        ->toMatchArray([
            'planned' => '288',
            'actual' => '283',
            'variance' => '-5',
            'variance_percentage' => '-1.74',
        ])
        ->and($data['release'])
        ->toMatchArray([
            'ready' => true,
            'ready_date_reached' => true,
            'tasks_complete' => true,
        ]);
});

it('groups only lye water materials while preserving every other material position', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $production = ProductionRun::factory()->for($workspace)->create();

    foreach ([
        ['Olive oil', 'saponified_oils', ProductionFormulaComponent::Ingredient, 1],
        ['Rose hydrosol', 'lye_water', ProductionFormulaComponent::Ingredient, 2],
        ['Green clay', 'formula_additions', ProductionFormulaComponent::Ingredient, 3],
        ['Sodium hydroxide', 'lye_water', ProductionFormulaComponent::Naoh, 4],
        ['Coconut oil addition', 'saponified_oils', ProductionFormulaComponent::Ingredient, 5],
        ['Water', 'lye_water', ProductionFormulaComponent::Water, 6],
        ['Lavender oil', 'formula_additions', ProductionFormulaComponent::Ingredient, 7],
    ] as [$name, $phase, $component, $sortOrder]) {
        ProductionFormulaLine::factory()->for($production, 'productionRun')->create([
            'component' => $component,
            'subject_name_snapshot' => $name,
            'phase_key_snapshot' => $phase,
            'phase_name_snapshot' => str($phase)->replace('_', ' ')->title(),
            'planned_mass_grams' => '10.000000000',
            'sort_order' => $sortOrder,
        ]);
    }

    ProductionRequirement::factory()->for($production, 'productionRun')->forPackaging(
        PackagingItem::factory()->for($workspace)->create(['name' => 'Carton']),
    )->create([
        'subject_name_snapshot' => 'Carton',
        'required_units' => 10,
        'sort_order' => 8,
    ]);

    $materials = app(ProductionDetailPresenter::class)->present(
        $production->fresh()->load(['requirements.reservations.stockLot', 'formulaLines', 'tasks', 'outputLot']),
    )['materials'];

    expect(collect($materials)->pluck('material_name')->all())->toBe([
        'Olive oil',
        'Rose hydrosol',
        'Sodium hydroxide',
        'Water',
        'Green clay',
        'Coconut oil addition',
        'Lavender oil',
        'Carton',
    ]);
});

/**
 * @return array{
 *     owner: User,
 *     workspace: Workspace,
 *     production: ProductionRun,
 *     oilRequirement: ProductionRequirement,
 *     lyeRequirement: ProductionRequirement,
 *     packagingRequirement: ProductionRequirement,
 *     oilLotA: StockLot,
 *     oilLotB: StockLot,
 *     lyeLot: StockLot,
 *     packagingLot: StockLot,
 *     waterLine: ProductionFormulaLine,
 * }
 */
function productionDetailPresenterFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $production = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::InProduction,
        'recipe_name_snapshot' => 'Olive soap',
        'planning_batch_number' => 'PLAN-001',
        'planned_for' => '2026-08-12',
        'basis_quantity_grams' => '2000.000000000',
        'basis_input_value' => '2.000000000',
        'expected_units' => 288,
    ]);
    $oil = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $lye = Ingredient::factory()->create(['display_name' => 'Sodium hydroxide']);
    $packaging = PackagingItem::factory()->for($workspace)->create([
        'name' => 'Box',
        'material_code' => 'PK-BOX',
    ]);
    $oilLine = ProductionFormulaLine::factory()->for($production, 'productionRun')->create([
        'ingredient_id' => $oil->id,
        'component' => ProductionFormulaComponent::Ingredient,
        'subject_name_snapshot' => 'Olive oil',
        'basis_percentage_snapshot' => '50.000000000',
        'planned_mass_grams' => '1000.000000000',
        'sort_order' => 1,
    ]);
    $lyeLine = ProductionFormulaLine::factory()->for($production, 'productionRun')->create([
        'ingredient_id' => $lye->id,
        'component' => ProductionFormulaComponent::Naoh,
        'subject_name_snapshot' => 'Sodium hydroxide',
        'basis_percentage_snapshot' => '5.000000000',
        'planned_mass_grams' => '105.000000000',
        'sort_order' => 2,
    ]);
    $waterLine = ProductionFormulaLine::factory()->for($production, 'productionRun')->create([
        'ingredient_id' => null,
        'component' => ProductionFormulaComponent::Water,
        'subject_name_snapshot' => 'Water',
        'basis_percentage_snapshot' => '10.000000000',
        'planned_mass_grams' => '200.000000000',
        'sort_order' => 3,
    ]);
    $oilRequirement = ProductionRequirement::factory()->for($production, 'productionRun')->for($oil)->create([
        'subject_name_snapshot' => 'Olive oil',
        'required_mass_grams' => '1000.000000000',
        'percentage_snapshot' => '50.000000000',
        'sort_order' => 1,
    ]);
    $lyeRequirement = ProductionRequirement::factory()->for($production, 'productionRun')->for($lye)->create([
        'subject_name_snapshot' => 'Sodium hydroxide',
        'required_mass_grams' => '105.000000000',
        'percentage_snapshot' => '5.000000000',
        'sort_order' => 2,
    ]);
    $packagingRequirement = ProductionRequirement::factory()->for($production, 'productionRun')->forPackaging($packaging)->create([
        'subject_name_snapshot' => 'Box',
        'material_code_snapshot' => 'PK-BOX',
        'required_units' => 100,
        'sort_order' => 3,
    ]);
    $oilLotA = StockLot::factory()->for($workspace)->released()->create([
        'ingredient_id' => $oil->id,
        'internal_lot_code' => 'OIL-A',
    ]);
    $oilLotB = StockLot::factory()->for($workspace)->released()->create([
        'ingredient_id' => $oil->id,
        'internal_lot_code' => 'OIL-B',
    ]);
    $lyeLot = StockLot::factory()->for($workspace)->released()->create([
        'ingredient_id' => $lye->id,
        'internal_lot_code' => 'LYE-A',
    ]);
    $packagingLot = StockLot::factory()->for($workspace)->forPackaging()->released()->create([
        'packaging_item_id' => $packaging->id,
        'internal_lot_code' => 'BOX-A',
    ]);
    foreach ([
        [$oilRequirement, $oilLotA, '650.000000000'],
        [$oilRequirement, $oilLotB, '250.000000000'],
        [$lyeRequirement, $lyeLot, '105.000000000'],
        [$packagingRequirement, $packagingLot, '100.000000000'],
    ] as [$requirement, $lot, $quantity]) {
        StockReservation::factory()->create([
            'workspace_id' => $workspace->id,
            'production_run_id' => $production->id,
            'production_requirement_id' => $requirement->id,
            'stock_lot_id' => $lot->id,
            'quantity' => $quantity,
            'status' => StockReservationStatus::Active,
            'created_by_user_id' => $owner->id,
        ]);
    }
    ProductionConsumption::factory()->create([
        'production_run_id' => $production->id,
        'production_requirement_id' => $oilRequirement->id,
        'stock_lot_id' => $oilLotA->id,
        'quantity' => '640.000000000',
        'subject_name_snapshot' => 'Olive oil',
    ]);

    return compact(
        'owner',
        'workspace',
        'production',
        'oilRequirement',
        'lyeRequirement',
        'packagingRequirement',
        'oilLotA',
        'oilLotB',
        'lyeLot',
        'packagingLot',
        'waterLine',
    );
}

it('presents the frozen KOH purity snapshot name without rebuilding a live label', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $production = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::InProduction,
        'recipe_name_snapshot' => 'Potash soap',
        'planning_batch_number' => 'PLAN-002',
        'basis_quantity_grams' => '2000.000000000',
        'expected_units' => 100,
    ]);
    $koh = Ingredient::factory()->create(['display_name' => 'Potassium hydroxide']);
    ProductionFormulaLine::factory()->for($production, 'productionRun')->create([
        'ingredient_id' => $koh->id,
        'component' => ProductionFormulaComponent::Koh,
        'subject_name_snapshot' => 'Potassium hydroxide (KOH 90%)',
        'basis_percentage_snapshot' => '14.500000000',
        'planned_mass_grams' => '290.000000000',
        'sort_order' => 1,
    ]);

    $data = app(ProductionDetailPresenter::class)->present(
        production: $production->load(['requirements.reservations.stockLot', 'formulaLines', 'consumption.stockLot', 'tasks']),
        actualRows: [],
        calculatedActualRows: [],
    );

    expect(array_column($data['materials'], 'material_name'))
        ->toBe(['Potassium hydroxide (KOH 90%)']);
});
