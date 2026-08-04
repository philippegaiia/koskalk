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
        Schema::create('production_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_task_set_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('production_task_set_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_snapshot', 120);
            $table->integer('days_after_production')->default(0);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->date('scheduled_for');
            $table->string('scheduling_mode', 16)->default('automatic');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'scheduled_for']);
            $table->index(['production_run_id', 'scheduled_for']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_tasks');
    }
};
