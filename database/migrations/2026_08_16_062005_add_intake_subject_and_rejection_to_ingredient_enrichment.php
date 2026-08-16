<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ingredient_enrichment_batches', function (Blueprint $table): void {
            $table->unsignedInteger('rejected_count')->default(0)->after('cancelled_count');
        });

        Schema::table('ingredient_enrichment_batch_items', function (Blueprint $table): void {
            $table->dropForeign(['ingredient_id']);
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->restrictOnDelete();
            $table->foreignId('ingredient_intake_item_id')->nullable()->after('ingredient_id')->constrained()->restrictOnDelete();
            $table->string('catalog_key', 120)->nullable()->change();
            $table->foreignId('rejected_by_user_id')->nullable()->after('approved_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestampTz('rejected_at')->nullable()->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->unique(
                ['ingredient_enrichment_batch_id', 'ingredient_intake_item_id'],
                'ingredient_enrichment_items_batch_intake_item_unique',
            );
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE ingredient_enrichment_batch_items
                ADD CONSTRAINT ingredient_enrichment_batch_items_exactly_one_subject
                CHECK (num_nonnulls(ingredient_id, ingredient_intake_item_id) = 1)
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER ingredient_enrichment_items_exactly_one_subject_insert
                BEFORE INSERT ON ingredient_enrichment_batch_items
                WHEN ((NEW.ingredient_id IS NULL) = (NEW.ingredient_intake_item_id IS NULL))
                BEGIN
                    SELECT RAISE(ABORT, 'ingredient enrichment item must target exactly one subject');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER ingredient_enrichment_items_exactly_one_subject_update
                BEFORE UPDATE OF ingredient_id, ingredient_intake_item_id ON ingredient_enrichment_batch_items
                WHEN ((NEW.ingredient_id IS NULL) = (NEW.ingredient_intake_item_id IS NULL))
                BEGIN
                    SELECT RAISE(ABORT, 'ingredient enrichment item must target exactly one subject');
                END
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE ingredient_enrichment_batch_items DROP CONSTRAINT IF EXISTS ingredient_enrichment_batch_items_exactly_one_subject');
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS ingredient_enrichment_items_exactly_one_subject_insert');
            DB::statement('DROP TRIGGER IF EXISTS ingredient_enrichment_items_exactly_one_subject_update');
        }

        Schema::table('ingredient_enrichment_batch_items', function (Blueprint $table): void {
            $table->dropUnique('ingredient_enrichment_items_batch_intake_item_unique');
            $table->dropForeign(['ingredient_intake_item_id']);
            $table->dropForeign(['rejected_by_user_id']);
            $table->dropColumn(['ingredient_intake_item_id', 'rejected_by_user_id', 'rejected_at', 'rejection_reason']);
            $table->dropForeign(['ingredient_id']);
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->nullOnDelete();
            $table->string('catalog_key', 120)->nullable(false)->change();
        });

        Schema::table('ingredient_enrichment_batches', function (Blueprint $table): void {
            $table->dropColumn('rejected_count');
        });
    }
};
