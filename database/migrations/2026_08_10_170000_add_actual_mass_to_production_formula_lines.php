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
        Schema::table('production_formula_lines', function (Blueprint $table): void {
            $table->decimal('actual_mass_grams', 20, 9)
                ->nullable()
                ->after('planned_mass_grams');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_formula_lines', function (Blueprint $table): void {
            $table->dropColumn('actual_mass_grams');
        });
    }
};
