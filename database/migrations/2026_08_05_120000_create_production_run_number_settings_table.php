<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_run_number_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_planning_serial')->default(1);
            $table->string('permanent_prefix', 32)->default('B-');
            $table->string('permanent_suffix', 32)->default('');
            $table->unsignedSmallInteger('permanent_padding')->default(5);
            $table->unsignedBigInteger('next_permanent_serial')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_run_number_settings');
    }
};
