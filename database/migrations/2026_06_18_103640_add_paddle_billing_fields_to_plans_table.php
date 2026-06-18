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
        Schema::table('plans', function (Blueprint $table) {
            $table->string('paddle_product_id')->nullable()->after('description')->index();
            $table->string('paddle_price_id')->nullable()->after('paddle_product_id')->unique();
            $table->string('billing_interval')->nullable()->after('paddle_price_id');
            $table->string('price_label')->nullable()->after('billing_interval');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'paddle_product_id',
                'paddle_price_id',
                'billing_interval',
                'price_label',
            ]);
        });
    }
};
