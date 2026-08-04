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
        Schema::create('production_task_set_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_task_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_task_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->integer('days_after_production')->default(0);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->timestamps();

            $table->unique(['production_task_set_id', 'position']);
            $table->index(['production_task_set_id', 'days_after_production']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_task_set_items');
    }
};
