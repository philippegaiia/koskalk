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
        Schema::table('ingredient_market_labels', function (Blueprint $table): void {
            $table->string('source_tier', 32)->nullable();
            $table->string('confidence', 32)->nullable();
            $table->string('source_version', 100)->nullable();
            $table->date('source_updated_at')->nullable();
            $table->timestampTz('retrieved_at')->nullable();
        });

        Schema::table('ingredient_function_ingredient', function (Blueprint $table): void {
            $table->string('source_tier', 32)->nullable();
            $table->string('confidence', 32)->nullable();
            $table->string('source_version', 100)->nullable();
            $table->date('source_updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_function_ingredient', function (Blueprint $table): void {
            $table->dropColumn([
                'source_tier',
                'confidence',
                'source_version',
                'source_updated_at',
            ]);
        });

        Schema::table('ingredient_market_labels', function (Blueprint $table): void {
            $table->dropColumn([
                'source_tier',
                'confidence',
                'source_version',
                'source_updated_at',
                'retrieved_at',
            ]);
        });
    }
};
