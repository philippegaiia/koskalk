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
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_family_id')->constrained()->cascadeOnDelete();
            $table->enum('owner_type', ['user', 'workspace']);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('visibility', ['private', 'workspace', 'shared_link', 'public'])->default('private');
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamp('archived_at')->nullable();
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
        Schema::dropIfExists('recipes');
    }
};
