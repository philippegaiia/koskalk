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
            $table->enum('owner_type', ['user', 'workspace'])->nullable()->after('category');
            $table->unsignedBigInteger('owner_id')->nullable()->after('owner_type');
            $table->foreignId('workspace_id')->nullable()->after('owner_id')->constrained()->nullOnDelete();
            $table->enum('visibility', ['private', 'workspace', 'shared_link', 'public'])->default('public')->after('workspace_id');

            $table->index(['owner_type', 'owner_id']);
            $table->index('workspace_id');
            $table->index('visibility');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropIndex(['owner_type', 'owner_id']);
            $table->dropIndex(['workspace_id']);
            $table->dropIndex(['visibility']);
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn([
                'owner_type',
                'owner_id',
                'visibility',
            ]);
        });
    }
};
