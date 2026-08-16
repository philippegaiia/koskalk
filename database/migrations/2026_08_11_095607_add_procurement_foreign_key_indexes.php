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
        Schema::table('supplier_listings', function (Blueprint $table): void {
            $table->index('supplier_id', 'supplier_listings_supplier_id_index');
            $table->index('ingredient_id', 'supplier_listings_ingredient_id_index');
            $table->index('packaging_item_id', 'supplier_listings_packaging_item_id_index');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->index('supplier_id', 'purchase_orders_supplier_id_index');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->index('purchase_order_id', 'purchase_order_lines_purchase_order_id_index');
            $table->index('supplier_listing_id', 'purchase_order_lines_supplier_listing_id_index');
            $table->index('ingredient_id', 'purchase_order_lines_ingredient_id_index');
            $table->index('packaging_item_id', 'purchase_order_lines_packaging_item_id_index');
        });

        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->index('purchase_order_id', 'goods_receipts_purchase_order_id_index');
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->index('goods_receipt_id', 'goods_receipt_lines_goods_receipt_id_index');
            $table->index('purchase_order_line_id', 'goods_receipt_lines_purchase_order_line_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_listings', function (Blueprint $table): void {
            $table->dropIndex('supplier_listings_supplier_id_index');
            $table->dropIndex('supplier_listings_ingredient_id_index');
            $table->dropIndex('supplier_listings_packaging_item_id_index');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropIndex('purchase_orders_supplier_id_index');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropIndex('purchase_order_lines_purchase_order_id_index');
            $table->dropIndex('purchase_order_lines_supplier_listing_id_index');
            $table->dropIndex('purchase_order_lines_ingredient_id_index');
            $table->dropIndex('purchase_order_lines_packaging_item_id_index');
        });

        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropIndex('goods_receipts_purchase_order_id_index');
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->dropIndex('goods_receipt_lines_goods_receipt_id_index');
            $table->dropIndex('goods_receipt_lines_purchase_order_line_id_index');
        });
    }
};
