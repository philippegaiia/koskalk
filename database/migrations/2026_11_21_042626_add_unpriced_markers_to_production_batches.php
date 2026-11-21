<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recipe-bench batch may be recorded before every row is priced; these
     * markers keep a partial cost from reading as a complete one.
     */
    public function up(): void
    {
        Schema::table('production_batches', function (Blueprint $table): void {
            $table->boolean('has_unpriced_rows')->default(false);
        });

        Schema::table('production_batch_packaging_items', function (Blueprint $table): void {
            $table->boolean('is_unpriced')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_batch_packaging_items', function (Blueprint $table): void {
            $table->dropColumn('is_unpriced');
        });

        Schema::table('production_batches', function (Blueprint $table): void {
            $table->dropColumn('has_unpriced_rows');
        });
    }
};
