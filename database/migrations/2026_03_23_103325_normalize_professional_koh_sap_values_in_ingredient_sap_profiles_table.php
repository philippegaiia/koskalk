<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('ingredient_sap_profiles')
            ->where('koh_sap_value', '>', 1)
            ->update([
                'koh_sap_value' => DB::raw('koh_sap_value / 1000'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
