<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 16)->default('pending');
            $table->json('parameters');
            $table->string('locale', 16);
            $table->string('disk');
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_exports');
    }
};
