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
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_phase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_version_id')->constrained()->cascadeOnDelete();
            $table->enum('owner_type', ['user', 'workspace']);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('visibility', ['private', 'workspace', 'shared_link', 'public'])->default('private');
            $table->unsignedInteger('position')->default(1);
            $table->decimal('percentage', 8, 4)->nullable();
            $table->decimal('weight', 12, 4)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index('workspace_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
    }
};
