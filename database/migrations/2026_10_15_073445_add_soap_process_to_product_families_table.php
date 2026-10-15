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
        Schema::table('product_families', function (Blueprint $table) {
            $table->string('soap_process', 32)->nullable()->after('calculation_basis');
        });

        DB::table('product_families')
            ->where('calculation_basis', 'initial_oils')
            ->update(['soap_process' => 'bar']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_families', function (Blueprint $table) {
            $table->dropColumn('soap_process');
        });
    }
};
