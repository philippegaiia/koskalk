<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\ProductionRunStatus;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\IngredientTranslation;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\SupportedLocale;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use App\Models\WorkspaceMaterialSetting;
use App\Services\Inventory\WorkspaceMaterialInventoryQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns physical, available, forecast, and buffer state for tracked materials', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'category' => IngredientCategory::Lipids,
        'subcategory' => IngredientSubcategory::VegetableOils,
    ]);
    WorkspaceIngredientCode::factory()->for($workspace)->for($ingredient)->create([
        'material_code' => 'OIL-001',
    ]);
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '1200.000000000',
    ]);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000.000000000',
    ]);

    $page = app(WorkspaceMaterialInventoryQuery::class)->paginate(
        workspace: $workspace,
        filters: [],
        perPage: 25,
        pageName: 'materials',
    );

    expect($page->total())->toBe(1)
        ->and($page->first()['name'])->toBe('Olive oil')
        ->and($page->first()['material_code'])->toBe('OIL-001')
        ->and($page->first()['physical'])->toBe('1000.000000000')
        ->and($page->first()['available'])->toBe('1000.000000000')
        ->and($page->first()['buffer_quantity'])->toBe('1200.000000000')
        ->and($page->first()['is_below_buffer'])->toBeTrue()
        ->and(app(WorkspaceMaterialInventoryQuery::class)->summary($workspace))->toMatchArray([
            'materials' => 1,
            'shortages' => 0,
            'below_buffer' => 1,
        ]);
});

it('searches inci aliases translations and workspace material codes', function (): void {
    $workspace = Workspace::factory()->create();
    $inci = Ingredient::factory()->create([
        'display_name' => 'Neutral name',
        'inci_name' => 'SEARCHABLE INCI TERM',
    ]);
    SupportedLocale::factory()->create([
        'code' => 'fr',
        'name' => 'French',
        'native_name' => 'Français',
    ]);
    $alias = IngredientAlias::factory()->for($inci)->create([
        'name' => 'Botanical alias',
        'normalized_name' => 'botanical alias',
    ]);
    IngredientTranslation::factory()->for($inci)->create([
        'locale' => 'fr',
        'display_name' => 'Nom traduit',
    ]);
    WorkspaceIngredientCode::factory()->for($workspace)->for($inci)->create([
        'material_code' => 'CODE-SEARCH',
    ]);
    StockLot::factory()->for($workspace)->for($inci)->create();

    $query = app(WorkspaceMaterialInventoryQuery::class);

    expect($query->paginate($workspace, ['search' => 'searchable inci term'])->total())->toBe(1)
        ->and($query->paginate($workspace, ['search' => 'botanical alias'])->total())->toBe(1)
        ->and($query->paginate($workspace, ['search' => 'nom traduit'])->total())->toBe(1)
        ->and($query->paginate($workspace, ['search' => 'code-search'])->total())->toBe(1);
});

it('keeps reserved and quarantined quantities inside physical stock but out of available stock', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Reserved oil']);
    $releasedLot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    $quarantinedLot = StockLot::factory()->for($workspace)->for($ingredient)->create();

    StockMovement::factory()->for($releasedLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '1000.000000000',
    ]);
    StockMovement::factory()->for($quarantinedLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '250.000000000',
    ]);

    $production = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Draft,
    ]);
    $requirement = ProductionRequirement::factory()
        ->for($production, 'productionRun')
        ->for($ingredient)
        ->create();
    StockReservation::factory()->create([
        'workspace_id' => $workspace->id,
        'production_run_id' => $production->id,
        'production_requirement_id' => $requirement->id,
        'stock_lot_id' => $releasedLot->id,
        'quantity' => '200.000000000',
        'created_by_user_id' => $workspace->owner_user_id,
    ]);

    $row = app(WorkspaceMaterialInventoryQuery::class)->paginate($workspace)->first();

    expect($row['physical'])->toBe('1250.000000000')
        ->and($row['quarantined'])->toBe('250.000000000')
        ->and($row['reserved'])->toBe('200.000000000')
        ->and($row['available'])->toBe('800.000000000');
});

it('filters by taxonomy and negative forecast', function (): void {
    $workspace = Workspace::factory()->create();
    $matching = Ingredient::factory()->create([
        'display_name' => 'Matching oil',
        'category' => IngredientCategory::Lipids,
        'subcategory' => IngredientSubcategory::VegetableOils,
    ]);
    $other = Ingredient::factory()->create([
        'display_name' => 'Other material',
        'category' => IngredientCategory::Actives,
        'subcategory' => IngredientSubcategory::OtherActives,
    ]);
    StockLot::factory()->for($workspace)->for($matching)->create();
    StockLot::factory()->for($workspace)->for($other)->create();
    $production = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Scheduled,
    ]);
    ProductionRequirement::factory()->for($production, 'productionRun')->for($matching)->create([
        'required_mass_grams' => '50.000000000',
    ]);

    $page = app(WorkspaceMaterialInventoryQuery::class)->paginate($workspace, [
        'category' => IngredientCategory::Lipids->value,
        'subcategory' => IngredientSubcategory::VegetableOils->value,
        'stock_state' => 'negative_forecast',
    ]);

    expect($page->total())->toBe(1)
        ->and($page->first()['name'])->toBe('Matching oil')
        ->and($page->first()['forecast'])->toBe('-50.000000000');
});

it('sorts deterministically and paginates on the database query', function (): void {
    $workspace = Workspace::factory()->create();

    foreach (['C material', 'A material', 'B material'] as $name) {
        $ingredient = Ingredient::factory()->create(['display_name' => $name]);
        StockLot::factory()->for($workspace)->for($ingredient)->create();
    }

    $query = app(WorkspaceMaterialInventoryQuery::class);
    $ascending = $query->paginate($workspace, ['sort' => 'name', 'direction' => 'asc'], 25, 'materials');
    $descending = $query->paginate($workspace, ['sort' => 'name', 'direction' => 'desc'], 25, 'materials');

    expect($ascending->pluck('name')->all())->toBe(['A material', 'B material', 'C material'])
        ->and($descending->pluck('name')->all())->toBe(['C material', 'B material', 'A material']);
});

it('pages more than 25 materials with a bounded query count', function (): void {
    $workspace = Workspace::factory()->create();

    for ($index = 0; $index < 30; $index++) {
        $ingredient = Ingredient::factory()->create([
            'display_name' => 'Material '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
        ]);
        StockLot::factory()->for($workspace)->for($ingredient)->create();
    }

    $query = app(WorkspaceMaterialInventoryQuery::class);
    $filters = ['sort' => 'name', 'direction' => 'asc'];

    DB::flushQueryLog();
    DB::enableQueryLog();

    $firstPage = $query->paginate($workspace, $filters, 25, 'materials');

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    request()->merge(['materials' => 2]);
    $secondPage = $query->paginate($workspace, $filters, 25, 'materials');

    expect($firstPage->total())->toBe(30)
        ->and($firstPage->count())->toBe(25)
        ->and($secondPage->count())->toBe(5)
        ->and($firstPage->pluck('name')->first())->toBe('Material 00')
        ->and($secondPage->pluck('name')->last())->toBe('Material 29')
        ->and($firstPage->pluck('name'))->not->toContain('Material 29');

    // Plan Task 3 Step 6: the ceiling is the observed implementation count plus one,
    // not an arbitrary large number. Subjects are batched into one query per type, so
    // the count is flat at 5 whether the page holds 25 or 100 rows; 6 gives one query
    // of headroom and fails loudly if per-row hydration ever returns.
    expect($queryCount)->toBeLessThanOrEqual(6);
});

it('includes lot-only and setting-only materials without duplicates', function (): void {
    $workspace = Workspace::factory()->create();
    $lotOnly = Ingredient::factory()->create(['display_name' => 'Lot only']);
    $settingOnly = Ingredient::factory()->create(['display_name' => 'Setting only']);
    StockLot::factory()->for($workspace)->for($lotOnly)->create();
    WorkspaceMaterialSetting::factory()->for($workspace)->for($settingOnly)->create();

    $page = app(WorkspaceMaterialInventoryQuery::class)->paginate($workspace);

    expect($page->total())->toBe(2)
        ->and($page->pluck('name')->sort()->values()->all())->toBe(['Lot only', 'Setting only']);
});
