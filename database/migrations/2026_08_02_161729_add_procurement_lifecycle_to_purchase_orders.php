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
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('stage', 24)->default('purchase_order')->index();
            $table->string('quotation_reference', 64)->nullable();
            $table->timestamp('quotation_requested_at')->nullable();
            $table->json('quotation_snapshot')->nullable();
            $table->timestamp('price_confirmed_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->json('purchase_order_snapshot')->nullable();
            $table->json('supplier_snapshot')->nullable();
            $table->json('delivery_address_snapshot')->nullable();
            $table->decimal('shipping_amount', 20, 9)->default(0);
            $table->decimal('discount_amount', 20, 9)->default(0);
            $table->decimal('tax_amount', 20, 9)->default(0);

            $table->unique(['workspace_id', 'quotation_reference']);
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->string('supplier_item_name')->nullable();
            $table->string('price_basis', 24)->nullable();
            $table->decimal('price_amount', 20, 9)->nullable();
            $table->string('price_unit', 24)->nullable();
            $table->timestamp('price_recorded_at')->nullable();
            $table->decimal('pack_price', 20, 9)->nullable()->change();
            $table->decimal('expected_cost', 20, 9)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->decimal('pack_price', 20, 9)->nullable(false)->change();
            $table->decimal('expected_cost', 20, 9)->nullable(false)->change();
            $table->dropColumn(['supplier_item_name', 'price_basis', 'price_amount', 'price_unit', 'price_recorded_at']);
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropUnique(['workspace_id', 'quotation_reference']);
            $table->dropColumn([
                'stage',
                'quotation_reference',
                'quotation_requested_at',
                'quotation_snapshot',
                'price_confirmed_at',
                'issued_at',
                'purchase_order_snapshot',
                'supplier_snapshot',
                'delivery_address_snapshot',
                'shipping_amount',
                'discount_amount',
                'tax_amount',
            ]);
        });
    }
};
