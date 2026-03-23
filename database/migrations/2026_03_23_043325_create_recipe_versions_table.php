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
        Schema::create('recipe_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->enum('owner_type', ['user', 'workspace']);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('visibility', ['private', 'workspace', 'shared_link', 'public'])->default('private');
            $table->unsignedInteger('version_number');
            $table->boolean('is_draft')->default(true);
            $table->string('name');
            $table->decimal('batch_size', 12, 3)->default(1000);
            $table->string('batch_unit', 16)->default('g');
            $table->text('notes')->nullable();
            $table->json('water_settings')->nullable();
            $table->json('calculation_context')->nullable();
            $table->timestamp('saved_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'version_number']);
            $table->index(['owner_type', 'owner_id']);
            $table->index('workspace_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_versions');
    }
};
