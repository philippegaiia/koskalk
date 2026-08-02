<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_listings', function (Blueprint $table): void {
            $table->renameColumn('supplier_name', 'supplier_item_name');
            $table->string('organic_status', 24)->default('unknown');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->string('organic_status', 24)->default('unknown');
        });

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->foreignId('supplier_listing_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('organic_status', 24)->default('unknown');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE purchase_order_lines
                ADD CONSTRAINT purchase_order_lines_exact_subject_check
                CHECK (
                    (ingredient_id IS NOT NULL AND packaging_item_id IS NULL AND unit_kind = 'mass')
                    OR
                    (ingredient_id IS NULL AND packaging_item_id IS NOT NULL AND unit_kind = 'count')
                )
            SQL);
        } else {
            DB::statement('DROP TRIGGER IF EXISTS stock_lots_exact_subject_insert');
            DB::statement('DROP TRIGGER IF EXISTS stock_lots_exact_subject_update');
            DB::statement(<<<'SQL'
                CREATE TRIGGER stock_lots_exact_subject_insert
                BEFORE INSERT ON stock_lots
                WHEN NOT (
                    (NEW.ingredient_id IS NOT NULL AND NEW.packaging_item_id IS NULL AND NEW.unit_kind = 'mass')
                    OR
                    (NEW.ingredient_id IS NULL AND NEW.packaging_item_id IS NOT NULL AND NEW.unit_kind = 'count')
                )
                BEGIN
                    SELECT RAISE(ABORT, 'stock lot requires exactly one correctly typed subject');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER stock_lots_exact_subject_update
                BEFORE UPDATE OF ingredient_id, packaging_item_id, unit_kind ON stock_lots
                WHEN NOT (
                    (NEW.ingredient_id IS NOT NULL AND NEW.packaging_item_id IS NULL AND NEW.unit_kind = 'mass')
                    OR
                    (NEW.ingredient_id IS NULL AND NEW.packaging_item_id IS NOT NULL AND NEW.unit_kind = 'count')
                )
                BEGIN
                    SELECT RAISE(ABORT, 'stock lot requires exactly one correctly typed subject');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER purchase_order_lines_exact_subject_insert
                BEFORE INSERT ON purchase_order_lines
                WHEN NOT (
                    (NEW.ingredient_id IS NOT NULL AND NEW.packaging_item_id IS NULL AND NEW.unit_kind = 'mass')
                    OR
                    (NEW.ingredient_id IS NULL AND NEW.packaging_item_id IS NOT NULL AND NEW.unit_kind = 'count')
                )
                BEGIN
                    SELECT RAISE(ABORT, 'purchase order line requires exactly one correctly typed subject');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER purchase_order_lines_exact_subject_update
                BEFORE UPDATE OF ingredient_id, packaging_item_id, unit_kind ON purchase_order_lines
                WHEN NOT (
                    (NEW.ingredient_id IS NOT NULL AND NEW.packaging_item_id IS NULL AND NEW.unit_kind = 'mass')
                    OR
                    (NEW.ingredient_id IS NULL AND NEW.packaging_item_id IS NOT NULL AND NEW.unit_kind = 'count')
                )
                BEGIN
                    SELECT RAISE(ABORT, 'purchase order line requires exactly one correctly typed subject');
                END
            SQL);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The supplier-listing alignment migration is intentionally irreversible.');
    }
};
