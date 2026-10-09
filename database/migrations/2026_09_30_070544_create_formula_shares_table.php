<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formula_shares', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('source_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignId('recipient_workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('source_recipe_id')->nullable()->index()->constrained('recipes')->nullOnDelete();
            $table->foreignId('source_version_id')->nullable()->index()->constrained('recipe_versions')->nullOnDelete();
            $table->foreignId('sent_by_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_recipe_id')->nullable()->index()->constrained('recipes')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('schema_version');
            $table->json('snapshot')->nullable();
            $table->string('snapshot_hash', 64);
            $table->json('options')->nullable();
            $table->json('import_receipt')->nullable();
            $table->uuid('request_key');
            $table->string('sender_workspace_name');
            $table->timestamp('sent_at');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('payload_purged_at')->nullable();
            $table->timestamp('accepted_product_deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['source_workspace_id', 'request_key'], 'formula_share_request_unique');
            $table->index(['recipient_workspace_id', 'status', 'created_at', 'id'], 'formula_share_inbox');
            $table->index(['source_workspace_id', 'status', 'created_at', 'id'], 'formula_share_outbox');
            $table->index(['status', 'expires_at'], 'formula_share_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_shares');
    }
};
