<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workspace_ingredient_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('material_code', 64);
            $table->timestamps();

            $table->unique(['workspace_id', 'ingredient_id'], 'workspace_ingredient_codes_material_unique');
            $table->unique(['workspace_id', 'material_code'], 'workspace_ingredient_codes_code_unique');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX workspace_ingredient_codes_code_ci_unique ON workspace_ingredient_codes (workspace_id, LOWER(material_code))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS workspace_ingredient_codes_code_ci_unique');
        }

        Schema::dropIfExists('workspace_ingredient_codes');
    }
};
