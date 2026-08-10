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
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->string('saponification_name')->nullable()->after('soap_inci_koh_name');
        });

        Schema::table('ingredient_translations', function (Blueprint $table): void {
            $table->string('saponification_name')->nullable()->after('display_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_translations', function (Blueprint $table): void {
            $table->dropColumn('saponification_name');
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropColumn('saponification_name');
        });
    }
};
