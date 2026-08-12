<?php

use App\Models\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('backfills every legacy CAS and EC value without changing its digits', function (): void {
    $ingredient = Ingredient::factory()->create();
    $migration = require database_path('migrations/2026_08_12_120000_create_ingredient_identity_tables_and_backfill_identifiers.php');
    $dropMigration = require database_path('migrations/2026_08_12_120100_drop_legacy_ingredient_identifier_columns.php');

    $dropMigration->down();
    $migration->down();

    try {
        DB::table('ingredients')->where('id', $ingredient->id)->update([
            'cas_number' => ' 19856-23-6, 19856-23-6 ; 8001-25-00 ',
            'ec_number' => '243-378-4',
        ]);

        $migration->up();

        $rows = DB::table('ingredient_identifiers')
            ->where('ingredient_id', $ingredient->id)
            ->orderBy('scheme')
            ->orderBy('id')
            ->get();

        expect($rows)->toHaveCount(3)
            ->and($rows->pluck('value')->all())->toContain('19856-23-6', '8001-25-00', '243-378-4')
            ->and($rows->where('scheme', 'cas')->where('is_primary', true)->value('value'))->toBe('19856-23-6')
            ->and($rows->where('scheme', 'ec')->where('is_primary', true)->value('value'))->toBe('243-378-4');
    } finally {
        if (! Schema::hasTable('ingredient_identifiers')) {
            $migration->up();
        }

        if (Schema::hasColumn('ingredients', 'cas_number')) {
            $dropMigration->up();
        }
    }
});
