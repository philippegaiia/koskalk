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
        Schema::table('ingredient_components', function (Blueprint $table): void {
            $table->dropColumn('component_inci_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_components', function (Blueprint $table): void {
            $table->string('component_inci_name')->nullable()->after('component_ingredient_id');
        });
    }
};
