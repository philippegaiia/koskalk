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
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->text('final_ingredient_list')->nullable()->after('notes');
            $table->string('final_ingredient_list_basis_hash', 64)->nullable()->after('final_ingredient_list');
            $table->text('final_plain_ingredient_list')->nullable()->after('final_ingredient_list_basis_hash');
            $table->string('final_plain_ingredient_list_basis_hash', 64)->nullable()->after('final_plain_ingredient_list');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->dropColumn([
                'final_ingredient_list',
                'final_ingredient_list_basis_hash',
                'final_plain_ingredient_list',
                'final_plain_ingredient_list_basis_hash',
            ]);
        });
    }
};
