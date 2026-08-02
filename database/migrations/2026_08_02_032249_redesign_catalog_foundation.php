<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $packagingReferences = [
        'recipe_version_costing_packaging_items' => 'recipe_version_costing_packaging_items_user_packaging_item_id_f',
        'recipe_version_packaging_items' => 'recipe_version_packaging_items_user_packaging_item_id_foreign',
        'production_batch_packaging_items' => 'production_batch_packaging_items_user_packaging_item_id_foreign',
        'supplier_listings' => 'supplier_listings_user_packaging_item_id_foreign',
        'purchase_order_lines' => 'purchase_order_lines_user_packaging_item_id_foreign',
        'stock_lots' => 'stock_lots_user_packaging_item_id_foreign',
    ];

    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->text('notes')->nullable();
            $table->dropColumn(['supplier_name', 'supplier_reference', 'is_organic']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_listings DROP CONSTRAINT supplier_listings_exact_subject_check');
            DB::statement('ALTER TABLE stock_lots DROP CONSTRAINT stock_lots_exact_subject_check');
        }

        Schema::table('user_packaging_items', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::rename('user_packaging_items', 'packaging_items');

        foreach (array_keys($this->packagingReferences) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->renameColumn('user_packaging_item_id', 'packaging_item_id');
            });
        }

        Schema::table('packaging_items', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->string('category', 32)->default('other');
            $table->boolean('is_active')->default(true);
        });

        DB::statement(<<<'SQL'
            UPDATE packaging_items
            SET workspace_id = workspaces.id,
                created_by_user_id = packaging_items.user_id
            FROM workspaces
            WHERE workspaces.owner_user_id = packaging_items.user_id
              AND packaging_items.workspace_id IS NULL
        SQL);

        if (DB::table('packaging_items')->whereNull('workspace_id')->exists()) {
            throw new RuntimeException('Every existing packaging item must resolve to its owner workspace before migration.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE packaging_items ALTER COLUMN workspace_id SET NOT NULL');
        } else {
            Schema::table('packaging_items', function (Blueprint $table): void {
                $table->unsignedBigInteger('workspace_id')->nullable(false)->change();
            });
        }

        Schema::table('packaging_items', function (Blueprint $table): void {
            $table->dropColumn('user_id');
            $table->foreign('workspace_id', 'packaging_items_workspace_id_foreign')
                ->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'packaging_items_created_by_user_id_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['workspace_id', 'is_active'], 'packaging_items_workspace_id_is_active_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER INDEX user_packaging_items_pkey RENAME TO packaging_items_pkey');
            DB::statement('ALTER INDEX user_packaging_items_public_id_unique RENAME TO packaging_items_public_id_unique');

            foreach ($this->packagingReferences as $tableName => $oldConstraint) {
                $newConstraint = str_replace('user_packaging_item', 'packaging_item', $oldConstraint);
                DB::statement("ALTER TABLE {$tableName} RENAME CONSTRAINT {$oldConstraint} TO {$newConstraint}");
            }

            DB::statement('ALTER INDEX recipe_version_costing_packaging_items_user_packaging_item_id_i RENAME TO recipe_version_costing_packaging_items_packaging_item_id_index');
            DB::statement('ALTER INDEX recipe_version_packaging_items_user_packaging_item_id_index RENAME TO recipe_version_packaging_items_packaging_item_id_index');
            DB::statement(<<<'SQL'
                ALTER TABLE supplier_listings
                ADD CONSTRAINT supplier_listings_exact_subject_check
                CHECK (
                    (ingredient_id IS NOT NULL AND packaging_item_id IS NULL AND unit_kind = 'mass')
                    OR
                    (ingredient_id IS NULL AND packaging_item_id IS NOT NULL AND unit_kind = 'count')
                )
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE stock_lots
                ADD CONSTRAINT stock_lots_exact_subject_check
                CHECK (
                    (ingredient_id IS NOT NULL AND packaging_item_id IS NULL AND unit_kind = 'mass')
                    OR
                    (ingredient_id IS NULL AND packaging_item_id IS NOT NULL AND unit_kind = 'count')
                )
            SQL);
        } else {
            foreach (['supplier_listings', 'stock_lots'] as $tableName) {
                DB::statement(<<<SQL
                    CREATE TRIGGER {$tableName}_exact_subject_insert
                    BEFORE INSERT ON {$tableName}
                    WHEN NOT (
                        (NEW.ingredient_id IS NOT NULL AND NEW.packaging_item_id IS NULL AND NEW.unit_kind = 'mass')
                        OR
                        (NEW.ingredient_id IS NULL AND NEW.packaging_item_id IS NOT NULL AND NEW.unit_kind = 'count')
                    )
                    BEGIN
                        SELECT RAISE(ABORT, 'catalogue record requires exactly one correctly typed subject');
                    END
                SQL);
                DB::statement(<<<SQL
                    CREATE TRIGGER {$tableName}_exact_subject_update
                    BEFORE UPDATE OF ingredient_id, packaging_item_id, unit_kind ON {$tableName}
                    WHEN NOT (
                        (NEW.ingredient_id IS NOT NULL AND NEW.packaging_item_id IS NULL AND NEW.unit_kind = 'mass')
                        OR
                        (NEW.ingredient_id IS NULL AND NEW.packaging_item_id IS NOT NULL AND NEW.unit_kind = 'count')
                    )
                    BEGIN
                        SELECT RAISE(ABORT, 'catalogue record requires exactly one correctly typed subject');
                    END
                SQL);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The catalogue foundation migration is intentionally irreversible because it removes supplier-tainted ingredient fields.');
    }
};
