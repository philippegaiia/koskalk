<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record when and by whom an administrator signed off a platform ingredient.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->timestamp('catalog_reviewed_at')->nullable()->after('requires_admin_review');
            $table->foreignId('catalog_reviewed_by_user_id')
                ->nullable()
                ->after('catalog_reviewed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('catalog_reviewed_by_user_id');
            $table->dropColumn('catalog_reviewed_at');
        });
    }
};
