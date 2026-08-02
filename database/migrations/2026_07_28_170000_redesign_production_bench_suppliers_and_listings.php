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
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('website')->nullable();
        });

        Schema::table('supplier_listings', function (Blueprint $table): void {
            $table->renameColumn('pack_description', 'purchase_format');
            $table->renameColumn('canonical_quantity_per_pack', 'canonical_quantity_per_purchase_format');
            $table->renameColumn('commercial_quantity', 'net_quantity');
            $table->renameColumn('commercial_unit', 'net_unit');
            $table->renameColumn('pack_price', 'total_price');
            $table->string('price_basis', 24)->default('total_purchase_format');
            $table->decimal('price_amount', 20, 9)->nullable();
            $table->string('price_unit', 24)->nullable();
            $table->timestamp('price_recorded_at')->nullable();
        });

        DB::table('supplier_listings')->update([
            'price_amount' => DB::raw('total_price'),
            'price_recorded_at' => DB::raw('updated_at'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_listings', function (Blueprint $table): void {
            $table->dropColumn(['price_basis', 'price_amount', 'price_unit', 'price_recorded_at']);
            $table->renameColumn('purchase_format', 'pack_description');
            $table->renameColumn('canonical_quantity_per_purchase_format', 'canonical_quantity_per_pack');
            $table->renameColumn('net_quantity', 'commercial_quantity');
            $table->renameColumn('net_unit', 'commercial_unit');
            $table->renameColumn('total_price', 'pack_price');
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn([
                'address_line_1',
                'address_line_2',
                'city',
                'region',
                'postal_code',
                'country_code',
                'website',
            ]);
        });
    }
};
