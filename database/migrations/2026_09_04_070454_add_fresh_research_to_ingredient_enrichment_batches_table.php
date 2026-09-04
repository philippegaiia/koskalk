<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredient_enrichment_batches', function (Blueprint $table): void {
            $table->boolean('fresh_research')->default(false)->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('ingredient_enrichment_batches', function (Blueprint $table): void {
            $table->dropColumn('fresh_research');
        });
    }
};
