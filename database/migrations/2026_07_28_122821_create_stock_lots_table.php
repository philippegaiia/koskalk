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
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_packaging_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('internal_lot_code', 64);
            $table->string('supplier_batch_number', 120)->nullable()->index();
            $table->string('origin', 32)->index();
            $table->string('unit_kind', 16);
            $table->string('status', 24)->index();
            $table->date('stocked_at')->index();
            $table->date('expires_at')->nullable()->index();
            $table->date('available_from')->nullable()->index();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('release_note')->nullable();
            $table->boolean('provenance_complete')->default(false);
            $table->decimal('historical_unit_cost', 20, 9)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'internal_lot_code']);
            $table->index(['workspace_id', 'ingredient_id', 'status']);
            $table->index(['workspace_id', 'user_packaging_item_id', 'status'], 'stock_lots_workspace_packaging_status_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stock_lots
            ADD CONSTRAINT stock_lots_exact_subject_check
            CHECK (
                (ingredient_id IS NOT NULL AND user_packaging_item_id IS NULL AND unit_kind = 'mass')
                OR
                (ingredient_id IS NULL AND user_packaging_item_id IS NOT NULL AND unit_kind = 'count')
            )
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_lots');
    }
};
