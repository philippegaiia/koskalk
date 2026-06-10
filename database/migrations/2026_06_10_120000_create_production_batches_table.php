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
        Schema::create('production_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipe_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipe_name');
            $table->unsignedInteger('recipe_version_number');
            $table->string('product_family_slug', 64);
            $table->string('production_batch_number', 120)->nullable();
            $table->date('manufacture_date');
            $table->string('batch_basis_label', 64);
            $table->decimal('batch_basis_value', total: 12, places: 3);
            $table->string('batch_basis_unit', 16);
            $table->unsignedInteger('units_produced');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('ingredient_total', total: 18, places: 4)->default(0);
            $table->decimal('packaging_total', total: 18, places: 4)->default(0);
            $table->decimal('total_cost', total: 18, places: 4)->default(0);
            $table->decimal('cost_per_unit', total: 18, places: 4)->default(0);
            $table->text('production_notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'recipe_id', 'manufacture_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_batches');
    }
};
