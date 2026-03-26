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
        Schema::create('fatty_acids', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->unsignedTinyInteger('chain_length')->nullable();
            $table->unsignedTinyInteger('double_bonds')->default(0);
            $table->string('saturation_class', 32)->nullable();
            $table->decimal('iodine_factor', 8, 3)->nullable();
            $table->string('default_group_key', 32)->nullable();
            $table->unsignedInteger('display_order')->default(1);
            $table->boolean('is_core')->default(false);
            $table->boolean('is_active')->default(true);
            $table->decimal('default_hidden_below_percent', 8, 3)->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->index('display_order');
            $table->index('is_core');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fatty_acids');
    }
};
