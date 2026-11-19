<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A size removed from the workbench while saved history still uses it is
     * archived instead of deleted, so that history keeps its packaging.
     */
    public function up(): void
    {
        Schema::table('product_sizes', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_sizes', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
