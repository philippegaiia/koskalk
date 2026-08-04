<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_lot_cost_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('compensates_adjustment_id')
                ->nullable()
                ->constrained('stock_lot_cost_adjustments')
                ->restrictOnDelete();
            $table->string('type', 32);
            $table->decimal('amount', 20, 9);
            $table->string('currency', 3);
            $table->decimal('costing_amount', 20, 9);
            $table->string('costing_currency', 3);
            $table->decimal('exchange_rate', 20, 12);
            $table->date('exchange_rate_date');
            $table->string('exchange_rate_provider', 48);
            $table->boolean('exchange_rate_is_manual')->default(false);
            $table->text('reason');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'stock_lot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_lot_cost_adjustments');
    }
};
