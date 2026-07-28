<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_lot_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('packs_received');
            $table->decimal('actual_quantity', 20, 9);
            $table->decimal('original_quantity', 20, 9);
            $table->string('original_unit', 24);
            $table->decimal('historical_total_cost', 20, 9);
            $table->string('supplier_batch_number', 120)->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
    }
};
