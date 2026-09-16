<?php

use App\Actions\Inventory\AssignStockLotLocation;
use App\Actions\Inventory\CreateOpeningStockLot;
use App\Actions\Inventory\SaveMaterialBuffer;
use App\Actions\Inventory\SaveMaterialStorageLocation;
use App\Actions\Inventory\SaveStorageLocation;
use App\Actions\Production\SaveProductionLocation;
use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\StorageLocation;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Inventory\StorageLocationSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function optionalLocationWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['uses_production_locations' => true, 'uses_storage_locations' => true]);
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    return [$owner, $workspace];
}

it('normalizes names and scopes duplicates to the owning workspace', function (): void {
    [$owner, $workspace] = optionalLocationWorkspace();
    $location = app(SaveStorageLocation::class)->handle($owner, $workspace, '  Oil   shelf  ');
    expect($location->name)->toBe('Oil shelf');
    expect(fn () => app(SaveStorageLocation::class)->handle($owner, $workspace, 'oil shelf'))->toThrow(ValidationException::class);
});

it('retains material defaults when the buffer is cleared and vice versa', function (): void {
    [$owner, $workspace] = optionalLocationWorkspace();
    $ingredient = Ingredient::factory()->create();
    $location = StorageLocation::factory()->for($workspace)->create();
    app(SaveMaterialBuffer::class)->handle($owner, $workspace, $ingredient, '2');
    $setting = app(SaveMaterialStorageLocation::class)->handle($owner, $workspace, $ingredient, $location->id);
    app(SaveMaterialBuffer::class)->handle($owner, $workspace, $ingredient, null);
    expect($setting->fresh()->buffer_quantity)->toBeNull();
    expect($setting->fresh()->default_storage_location_id)->toBe($location->id);
    app(SaveMaterialBuffer::class)->handle($owner, $workspace, $ingredient, '3');
    app(SaveMaterialStorageLocation::class)->handle($owner, $workspace, $ingredient, null);
    expect($setting->fresh()->buffer_quantity)->toBe('3000.000000000');
    expect($setting->fresh()->default_storage_location_id)->toBeNull();
});

it('reassigns a lot without writing stock movements or altering its cost', function (): void {
    [$owner, $workspace] = optionalLocationWorkspace();
    $lot = StockLot::factory()->for($workspace)->create();
    $before = $lot->fresh()->getAttributes();
    $location = StorageLocation::factory()->for($workspace)->create();
    app(AssignStockLotLocation::class)->handle($owner, $lot, $location->id);
    expect($lot->fresh()->storage_location_id)->toBe($location->id);
    expect($lot->movements()->count())->toBe(0);
    expect(collect($lot->fresh()->getAttributes())->except(['storage_location_id', 'updated_at'])->all())->toBe(collect($before)->except(['storage_location_id', 'updated_at'])->all());
    app(AssignStockLotLocation::class)->handle($owner, $lot, null);
    expect($lot->fresh()->storage_location_id)->toBeNull();
});

it('rejects foreign locations and disabled location actions', function (): void {
    [$owner, $workspace] = optionalLocationWorkspace();
    $lot = StockLot::factory()->for($workspace)->create();
    $foreign = StorageLocation::factory()->create();
    expect(fn () => app(AssignStockLotLocation::class)->handle($owner, $lot, $foreign->id))->toThrow(ValidationException::class);
    $workspace->update(['uses_production_locations' => false]);
    expect(fn () => app(SaveProductionLocation::class)->handle($owner, $workspace, 'Lab', 2))->toThrow(ValidationException::class);
});

it('uses the material default for opening stock but respects explicit clearing and opt out', function (): void {
    [$owner, $workspace] = optionalLocationWorkspace();
    $ingredient = Ingredient::factory()->create();
    $location = StorageLocation::factory()->for($workspace)->create();
    app(SaveMaterialStorageLocation::class)->handle($owner, $workspace, $ingredient, $location->id);
    $listing = SupplierListing::factory()->for($workspace)->for($ingredient)->create();
    $action = app(CreateOpeningStockLot::class);
    $first = $action->handle($owner, $workspace, $listing, '1', 'kg', '0.001', 'EUR', 'opening-default');
    expect($first->storage_location_id)->toBe($location->id);
    $second = $action->handle($owner, $workspace, $listing, '1', 'kg', '0.001', 'EUR', 'opening-cleared', storageLocationInput: ['storage_location_id' => null]);
    expect($second->storage_location_id)->toBeNull();
    $workspace->update(['uses_storage_locations' => false]);
    $third = $action->handle($owner, $workspace, $listing, '1', 'kg', '0.001', 'EUR', 'opening-off', storageLocationInput: ['storage_location_id' => $location->id]);
    expect($third->storage_location_id)->toBeNull();
});

it('reverses and reapplies the location schema without losing existing database guards', function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite table rebuild regression.');
    }
    $before = DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->pluck('sql', 'name')->all();
    $migration = require database_path('migrations/2026_09_16_163308_create_optional_locations_tables.php');
    $migration->down();
    expect(Schema::hasColumn('production_runs', 'production_location_id'))->toBeFalse();
    $migration->up();
    expect(DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->pluck('sql', 'name')->all())->toBe($before);
});

it('stops applying an archived default without erasing it', function (): void {
    [$owner, $workspace] = optionalLocationWorkspace();
    $ingredient = Ingredient::factory()->create();
    $location = StorageLocation::factory()->for($workspace)->create();
    $setting = app(SaveMaterialStorageLocation::class)->handle($owner, $workspace, $ingredient, $location->id);
    $location->update(['is_active' => false]);
    $selection = app(StorageLocationSelection::class);
    expect($selection->resolve($workspace, $ingredient, []))->toBeNull()
        ->and($setting->fresh()->default_storage_location_id)->toBe($location->id);
    expect(fn () => $selection->resolve($workspace, $ingredient, ['storage_location_id' => $location->id]))->toThrow(ValidationException::class);
});
