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
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_packaging_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('supplier_sku')->nullable();
            $table->string('listing_name');
            $table->string('unit_kind', 16);
            $table->unsignedInteger('ordered_packs');
            $table->decimal('canonical_quantity_per_pack', 20, 9);
            $table->decimal('pack_price', 20, 9);
            $table->string('currency', 3);
            $table->decimal('expected_quantity', 20, 9);
            $table->decimal('expected_cost', 20, 9);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
