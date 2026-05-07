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
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->foreignId('regulatory_regime_id')
                ->nullable()
                ->after('regulatory_regime')
                ->constrained('regulatory_regimes')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('regulatory_regime_id');
        });
    }
};
