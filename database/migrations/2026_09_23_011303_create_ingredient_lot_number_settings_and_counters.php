<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_lot_number_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('prefix', 24)->default('SK');
            $table->string('suffix', 24)->default('');
            $table->string('date_format', 8)->default('ymd');
            $table->string('date_source', 16)->default('created');
            $table->string('separator', 1)->default('-');
            $table->boolean('include_material_code')->default(false);
            $table->unsignedSmallInteger('padding')->default(4);
            $table->string('reset_period', 16)->default('daily');
            $table->timestamps();
        });

        Schema::create('ingredient_lot_number_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('period', 24);
            $table->unsignedBigInteger('next_serial')->default(1);
            $table->timestamps();
            $table->unique(['workspace_id', 'period'], 'ingredient_lot_counter_workspace_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_lot_number_counters');
        Schema::dropIfExists('ingredient_lot_number_settings');
    }
};
