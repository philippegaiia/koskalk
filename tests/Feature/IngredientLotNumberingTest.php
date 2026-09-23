<?php

use App\Actions\Inventory\CreateOpeningStockLot;
use App\Actions\Inventory\SaveIngredientLotNumberSettings;
use App\Enums\StockUnitKind;
use App\Models\Ingredient;
use App\Models\IngredientLotNumberCounter;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array{owner: User, workspace: Workspace, ingredient: Ingredient, listing: SupplierListing} */
function ingredientNumberingFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()->for($workspace)->for($ingredient)->create();

    return compact('owner', 'workspace', 'ingredient', 'listing');
}

/** @param array{owner: User, workspace: Workspace, ingredient: Ingredient, listing: SupplierListing} $fixture */
function numberedOpeningLot(array $fixture, string $key, ?string $manual = null, string $stockedAt = '2026-09-23'): StockLot
{
    return app(CreateOpeningStockLot::class)->handle(
        actor: $fixture['owner'], workspace: $fixture['workspace'], listing: $fixture['listing'],
        quantity: '1', unit: 'kg', pricePerCanonicalUnit: '0.01', currency: 'EUR',
        idempotencyKey: $key, stockedAt: $stockedAt, internalLotCode: $manual,
    );
}

/**
 * @param  array{owner: User, workspace: Workspace, ingredient: Ingredient, listing: SupplierListing}  $fixture
 * @param  array<string, mixed>  $overrides
 */
function saveIngredientNumbering(array $fixture, array $overrides = []): void
{
    app(SaveIngredientLotNumberSettings::class)->handle($fixture['owner'], $fixture['workspace'], [
        'prefix' => 'SK', 'suffix' => '', 'date_format' => 'ymd', 'date_source' => 'created',
        'separator' => '-', 'include_material_code' => false, 'padding' => 4,
        'reset_period' => 'daily', 'next_number' => 1, ...$overrides,
    ]);
}

it('continues existing daily lot numbers and does not allocate again on an opening stock retry', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 23));
    $fixture = ingredientNumberingFixture();
    StockLot::factory()->for($fixture['workspace'])->for($fixture['ingredient'])->create(['internal_lot_code' => 'SK-260923-0042']);

    $lot = numberedOpeningLot($fixture, 'first');
    expect($lot->internal_lot_code)->toBe('SK-260923-43');
    expect(numberedOpeningLot($fixture, 'first')->id)->toBe($lot->id);
    expect(numberedOpeningLot($fixture, 'second')->internal_lot_code)->toBe('SK-260923-44');
});

it('uses stock dates and frozen ingredient codes while retaining counters for backdated periods', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 23));
    $fixture = ingredientNumberingFixture();
    WorkspaceIngredientCode::factory()->for($fixture['workspace'])->for($fixture['ingredient'])->create(['material_code' => 'OLIVE']);
    saveIngredientNumbering($fixture, ['prefix' => 'LOT', 'date_format' => 'Ymd', 'date_source' => 'stocked', 'include_material_code' => true]);

    $old = numberedOpeningLot($fixture, 'old', stockedAt: '2026-08-10');
    expect($old->internal_lot_code)->toBe('LOT-20260810-OLIVE-0001');
    expect(numberedOpeningLot($fixture, 'new')->internal_lot_code)->toBe('LOT-20260923-OLIVE-0001');
    expect(numberedOpeningLot($fixture, 'old-again', stockedAt: '2026-08-10')->internal_lot_code)->toBe('LOT-20260810-OLIVE-0002');
    WorkspaceIngredientCode::query()->where('workspace_id', $fixture['workspace']->id)->update(['material_code' => 'OIL']);
    expect($old->refresh()->internal_lot_code)->toBe('LOT-20260810-OLIVE-0001');
});

it('accepts a manual number without requiring an ingredient code or consuming the automatic counter', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 23));
    $fixture = ingredientNumberingFixture();
    saveIngredientNumbering($fixture, ['include_material_code' => true]);
    expect(numberedOpeningLot($fixture, 'manual', 'MY-LOT-42')->internal_lot_code)->toBe('MY-LOT-42');
    expect(fn () => numberedOpeningLot($fixture, 'missing-code'))->toThrow(ValidationException::class);
    saveIngredientNumbering($fixture);
    expect(numberedOpeningLot($fixture, 'automatic')->internal_lot_code)->toBe('SK-260923-0001');
});

it('rejects duplicate manual numbers in the workspace and allows them in another workspace', function (): void {
    $fixture = ingredientNumberingFixture();
    numberedOpeningLot($fixture, 'one', 'MY-LOT');
    expect(fn () => numberedOpeningLot($fixture, 'two', 'MY-LOT'))->toThrow(ValidationException::class);
    expect(numberedOpeningLot(ingredientNumberingFixture(), 'other', 'MY-LOT')->internal_lot_code)->toBe('MY-LOT');
});

it('skips manual collisions and lets the counter grow beyond its minimum digits', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 23));
    $fixture = ingredientNumberingFixture();
    saveIngredientNumbering($fixture, ['next_number' => 9999]);
    numberedOpeningLot($fixture, 'manual', 'SK-260923-9999');
    expect(numberedOpeningLot($fixture, 'auto')->internal_lot_code)->toBe('SK-260923-10000');
});

it('rejects reset periods not represented by the date format', function (string $format, string $reset): void {
    $fixture = ingredientNumberingFixture();
    expect(fn () => saveIngredientNumbering($fixture, ['date_format' => $format, 'reset_period' => $reset]))->toThrow(ValidationException::class);
})->with([['none', 'yearly'], ['Y', 'monthly'], ['ym', 'daily']]);

it('keeps a continuous counter when dates change with no reset', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 23));
    $fixture = ingredientNumberingFixture();
    saveIngredientNumbering($fixture, ['date_format' => 'none', 'reset_period' => 'never', 'prefix' => 'IN', 'next_number' => 42, 'suffix' => 'FR', 'separator' => '/']);
    expect(numberedOpeningLot($fixture, 'one')->internal_lot_code)->toBe('IN/0042/FR');
    $this->travel(1)->days();
    expect(numberedOpeningLot($fixture, 'two')->internal_lot_code)->toBe('IN/0043/FR');
});

it('keeps packaging numbering valid after custom and five digit ingredient lot numbers', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 23));
    $fixture = ingredientNumberingFixture();
    numberedOpeningLot($fixture, 'high', 'SK-260923-9999');
    numberedOpeningLot($fixture, 'higher', 'SK-260923-10000');
    numberedOpeningLot($fixture, 'custom', 'SK-260923-Z');
    $packaging = PackagingItem::factory()->for($fixture['workspace'])->create();
    $fixture['listing'] = SupplierListing::factory()->for($fixture['workspace'])->create([
        'ingredient_id' => null, 'packaging_item_id' => $packaging->id,
        'unit_kind' => StockUnitKind::Count, 'net_unit' => 'count',
    ]);
    $lot = app(CreateOpeningStockLot::class)->handle(
        actor: $fixture['owner'], workspace: $fixture['workspace'], listing: $fixture['listing'],
        quantity: '10', unit: 'count', pricePerCanonicalUnit: '0.01', currency: 'EUR', idempotencyKey: 'packaging',
    );
    expect($lot->internal_lot_code)->toBe('SK-260923-10001');
});

it('resets counters at the configured calendar boundary', function (string $reset, string $format, string $nextDate, string $expected): void {
    $this->travelTo(now()->setDate(2026, 9, 23));
    $fixture = ingredientNumberingFixture();
    saveIngredientNumbering($fixture, ['reset_period' => $reset, 'date_format' => $format, 'next_number' => 42]);
    numberedOpeningLot($fixture, 'first');
    $this->travelTo(Carbon::parse($nextDate));
    expect(numberedOpeningLot($fixture, 'next-period')->internal_lot_code)->toBe($expected);
})->with([
    ['daily', 'ymd', '2026-09-24', 'SK-260924-0001'],
    ['monthly', 'ym', '2026-10-01', 'SK-2610-0001'],
    ['yearly', 'Y', '2027-01-01', 'SK-2027-0001'],
]);

it('rejects invalid manual numbers without consuming a counter or creating stock', function (string $number): void {
    $fixture = ingredientNumberingFixture();
    expect(fn () => numberedOpeningLot($fixture, 'invalid', $number))->toThrow(ValidationException::class);
    expect(StockLot::query()->where('workspace_id', $fixture['workspace']->id)->count())->toBe(0);
    expect(IngredientLotNumberCounter::query()->count())->toBe(0);
})->with(['spaces in number', '<script>', str_repeat('A', 65)]);

it('refuses to move an issued counter backwards', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 23));
    $fixture = ingredientNumberingFixture();
    numberedOpeningLot($fixture, 'first');
    expect(fn () => saveIngredientNumbering($fixture))->toThrow(ValidationException::class);
    expect(numberedOpeningLot($fixture, 'second')->internal_lot_code)->toBe('SK-260923-2');
});

it('does not advance an unconfigured automatic counter to a manually entered numeric reference', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 23));
    $fixture = ingredientNumberingFixture();
    numberedOpeningLot($fixture, 'manual', 'SK-260923-999999999999');
    expect(numberedOpeningLot($fixture, 'auto')->internal_lot_code)->toBe('SK-260923-1');
});
