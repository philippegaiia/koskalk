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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_lot_id')->constrained()->restrictOnDelete();
            $table->string('type', 40)->index();
            $table->decimal('quantity_delta', 20, 9);
            $table->decimal('original_quantity', 20, 9)->nullable();
            $table->string('original_unit', 24)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('source');
            $table->foreignId('reversal_of_stock_movement_id')
                ->nullable()
                ->constrained('stock_movements')
                ->restrictOnDelete();
            $table->string('idempotency_key', 120);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['stock_lot_id', 'occurred_at']);
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER stock_movements_non_zero_quantity_insert
                BEFORE INSERT ON stock_movements
                WHEN NEW.quantity_delta = 0
                BEGIN
                    SELECT RAISE(ABORT, 'stock movement quantity must be non-zero');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER stock_movements_non_zero_quantity_update
                BEFORE UPDATE OF quantity_delta ON stock_movements
                WHEN NEW.quantity_delta = 0
                BEGIN
                    SELECT RAISE(ABORT, 'stock movement quantity must be non-zero');
                END
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements
            ADD CONSTRAINT stock_movements_non_zero_quantity_check
            CHECK (quantity_delta <> 0)
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
