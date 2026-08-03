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
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->json('previous_material_price_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('goods_receipt_lines')->whereNotNull('previous_material_price_snapshot')->exists()) {
            throw new LogicException('Cannot remove previous material price snapshots while receipt data still uses them.');
        }

        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropColumn('previous_material_price_snapshot');
        });
    }
};
