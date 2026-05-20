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
        if (! Schema::hasTable('regulatory_regime_substance_rules')) {
            return;
        }

        if (
            Schema::hasColumn('regulatory_regime_substance_rules', 'regulated_substance_id')
            && ! Schema::hasColumn('regulatory_regime_substance_rules', 'substance_id')
        ) {
            Schema::table('regulatory_regime_substance_rules', function (Blueprint $table): void {
                $table->renameColumn('regulated_substance_id', 'substance_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('regulatory_regime_substance_rules')) {
            return;
        }

        if (
            Schema::hasColumn('regulatory_regime_substance_rules', 'substance_id')
            && ! Schema::hasColumn('regulatory_regime_substance_rules', 'regulated_substance_id')
        ) {
            Schema::table('regulatory_regime_substance_rules', function (Blueprint $table): void {
                $table->renameColumn('substance_id', 'regulated_substance_id');
            });
        }
    }
};
