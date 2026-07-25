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
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('processing');
            $table->string('original_filename');
            $table->string('original_mime_type')->nullable();
            $table->unsignedBigInteger('original_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('pending_disk')->nullable();
            $table->string('pending_path')->nullable();
            $table->decimal('focal_x', 5, 2)->default(50);
            $table->decimal('focal_y', 5, 2)->default(50);
            $table->unsignedSmallInteger('progress')->default(0);
            $table->string('processing_stage')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_reason')->nullable();
            $table->uuid('processing_token');
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
