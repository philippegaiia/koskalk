<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('current_material_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('packaging_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('price_per_canonical_unit', 24, 12);
            $table->string('currency', 3);
            $table->timestamp('recorded_at');
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE current_material_prices ADD CONSTRAINT current_material_prices_exact_subject_check CHECK (((ingredient_id IS NOT NULL)::int + (packaging_item_id IS NOT NULL)::int) = 1)');
        } else {
            DB::statement(<<<'SQL'
                CREATE TRIGGER current_material_prices_exact_subject_insert
                BEFORE INSERT ON current_material_prices
                WHEN ((NEW.ingredient_id IS NOT NULL) + (NEW.packaging_item_id IS NOT NULL)) != 1
                BEGIN
                    SELECT RAISE(ABORT, 'current material price requires exactly one subject');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER current_material_prices_exact_subject_update
                BEFORE UPDATE OF ingredient_id, packaging_item_id ON current_material_prices
                WHEN ((NEW.ingredient_id IS NOT NULL) + (NEW.packaging_item_id IS NOT NULL)) != 1
                BEGIN
                    SELECT RAISE(ABORT, 'current material price requires exactly one subject');
                END
            SQL);
        }

        DB::statement('CREATE UNIQUE INDEX current_material_prices_workspace_ingredient_unique ON current_material_prices (workspace_id, ingredient_id) WHERE ingredient_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX current_material_prices_workspace_packaging_unique ON current_material_prices (workspace_id, packaging_item_id) WHERE packaging_item_id IS NOT NULL');

        DB::statement(<<<'SQL'
            INSERT INTO current_material_prices (
                workspace_id, ingredient_id, packaging_item_id,
                price_per_canonical_unit, currency, recorded_at,
                source_type, source_id, created_by_user_id, created_at, updated_at
            )
            SELECT workspaces.id, user_ingredient_prices.ingredient_id, NULL,
                user_ingredient_prices.price_per_kg / 1000,
                user_ingredient_prices.currency,
                COALESCE(user_ingredient_prices.last_used_at, user_ingredient_prices.updated_at, CURRENT_TIMESTAMP),
                'manual_costing', user_ingredient_prices.id, user_ingredient_prices.user_id,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM user_ingredient_prices
            JOIN workspaces ON workspaces.owner_user_id = user_ingredient_prices.user_id
            ON CONFLICT (workspace_id, ingredient_id) WHERE ingredient_id IS NOT NULL
            DO UPDATE SET
                price_per_canonical_unit = EXCLUDED.price_per_canonical_unit,
                currency = EXCLUDED.currency,
                recorded_at = EXCLUDED.recorded_at,
                source_type = EXCLUDED.source_type,
                source_id = EXCLUDED.source_id,
                created_by_user_id = EXCLUDED.created_by_user_id,
                updated_at = EXCLUDED.updated_at
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO current_material_prices (
                workspace_id, ingredient_id, packaging_item_id,
                price_per_canonical_unit, currency, recorded_at,
                source_type, source_id, created_by_user_id, created_at, updated_at
            )
            SELECT workspace_id, NULL, id, unit_cost, currency,
                COALESCE(updated_at, CURRENT_TIMESTAMP),
                'manual_costing', id, created_by_user_id,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM packaging_items
            WHERE 1 = 1
            ON CONFLICT (workspace_id, packaging_item_id) WHERE packaging_item_id IS NOT NULL
            DO UPDATE SET
                price_per_canonical_unit = EXCLUDED.price_per_canonical_unit,
                currency = EXCLUDED.currency,
                recorded_at = EXCLUDED.recorded_at,
                source_type = EXCLUDED.source_type,
                source_id = EXCLUDED.source_id,
                created_by_user_id = EXCLUDED.created_by_user_id,
                updated_at = EXCLUDED.updated_at
        SQL);

        $unresolvedIngredientPrices = DB::table('user_ingredient_prices')
            ->leftJoin('workspaces', 'workspaces.owner_user_id', '=', 'user_ingredient_prices.user_id')
            ->whereNull('workspaces.id')
            ->exists();

        if ($unresolvedIngredientPrices) {
            throw new RuntimeException('Every existing ingredient price must resolve to its owner workspace before migration.');
        }

        Schema::drop('user_ingredient_prices');
        Schema::table('packaging_items', function (Blueprint $table): void {
            $table->dropColumn(['unit_cost', 'currency']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('The workspace price migration is intentionally irreversible after price backfill.');
    }
};
