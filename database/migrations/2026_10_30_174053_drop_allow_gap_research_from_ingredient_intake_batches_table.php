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
        Schema::table('ingredient_intake_batches', function (Blueprint $table): void {
            $table->dropColumn('allow_gap_research');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_intake_batches', function (Blueprint $table): void {
            $table->boolean('allow_gap_research')->default(false)->after('family_hint');
        });
    }
};
