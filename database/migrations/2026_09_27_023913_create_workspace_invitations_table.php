<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 24);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'email']);
            $table->index(['workspace_id', 'accepted_at', 'revoked_at', 'expires_at'], 'workspace_invitations_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
