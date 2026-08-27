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
        Schema::table('production_requirements', function (Blueprint $table) {
            $table->string('material_code_snapshot', 64)
                ->nullable()
                ->after('subject_name_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_requirements', function (Blueprint $table) {
            $table->dropColumn('material_code_snapshot');
        });
    }
};
