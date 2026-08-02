<?php

use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\Workspace;
use App\StockUnitKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('backfills legacy listing prices and remains reversible', function (): void {
    $workspace = Workspace::factory()->create();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $migration = supplierListingRedesignMigration();
    $recordedAt = '2026-07-27 14:15:16';

    $migration->down();

    $listingId = DB::table('supplier_listings')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'packaging_item_id' => null,
        'supplier_sku' => 'LEGACY-DRUM',
        'supplier_item_name' => null,
        'pack_description' => 'Legacy drum',
        'container' => 'drum',
        'unit_kind' => StockUnitKind::Mass->value,
        'canonical_quantity_per_pack' => '200000',
        'commercial_quantity' => '200',
        'commercial_unit' => 'kg',
        'pack_price' => '840.25',
        'currency' => 'EUR',
        'minimum_packs' => 1,
        'notes' => null,
        'is_active' => true,
        'created_at' => $recordedAt,
        'updated_at' => $recordedAt,
    ]);

    $migration->up();

    $migratedListing = DB::table('supplier_listings')->find($listingId);

    expect($migratedListing)
        ->price_amount->toEqual(840.25)
        ->total_price->toEqual(840.25)
        ->price_recorded_at->toBe($recordedAt);

    $migration->down();

    expect(Schema::hasColumn('supplier_listings', 'pack_price'))->toBeTrue()
        ->and(Schema::hasColumn('supplier_listings', 'total_price'))->toBeFalse()
        ->and(DB::table('supplier_listings')->where('id', $listingId)->value('pack_price'))->toEqual(840.25);

    $migration->up();

    $remigratedListing = DB::table('supplier_listings')->find($listingId);

    expect($remigratedListing)
        ->price_amount->toEqual(840.25)
        ->total_price->toEqual(840.25)
        ->price_recorded_at->toBe($recordedAt);
});

function supplierListingRedesignMigration(): Migration
{
    return require database_path('migrations/2026_07_28_170000_redesign_production_bench_suppliers_and_listings.php');
}
