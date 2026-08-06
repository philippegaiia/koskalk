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
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropUnique(['source_file', 'source_key']);
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->renameColumn('source_key', 'catalog_key');
            $table->dropColumn(['source_file', 'source_code_prefix']);
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->unique('catalog_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropUnique(['catalog_key']);
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->renameColumn('catalog_key', 'source_key');
            $table->string('source_file')->default('catalog');
            $table->string('source_code_prefix')->nullable();
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->unique(['source_file', 'source_key']);
        });
    }
};
