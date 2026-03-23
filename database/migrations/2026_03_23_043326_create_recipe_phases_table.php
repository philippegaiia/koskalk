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
        Schema::create('recipe_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_version_id')->constrained()->cascadeOnDelete();
            $table->enum('owner_type', ['user', 'workspace']);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('visibility', ['private', 'workspace', 'shared_link', 'public'])->default('private');
            $table->string('name');
            $table->string('slug');
            $table->string('phase_type')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index('workspace_id');
            $table->unique(['recipe_version_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_phases');
    }
};
