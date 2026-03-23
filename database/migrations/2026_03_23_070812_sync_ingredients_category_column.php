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
        if (Schema::hasColumn('ingredients', 'ingredient_family') && ! Schema::hasColumn('ingredients', 'category')) {
            Schema::table('ingredients', function (Blueprint $table): void {
                $table->string('category')->nullable()->after('source_code_prefix');
            });
        }

        if (Schema::hasColumn('ingredients', 'ingredient_family') && Schema::hasColumn('ingredients', 'category')) {
            DB::table('ingredients')
                ->whereNull('category')
                ->update([
                    'category' => DB::raw('ingredient_family'),
                ]);

            DB::statement('CREATE INDEX IF NOT EXISTS ingredients_category_index ON ingredients (category)');

            Schema::table('ingredients', function (Blueprint $table): void {
                $table->dropColumn('ingredient_family');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ingredients', 'category') && ! Schema::hasColumn('ingredients', 'ingredient_family')) {
            Schema::table('ingredients', function (Blueprint $table): void {
                $table->string('ingredient_family')->nullable()->after('source_code_prefix');
            });

            DB::table('ingredients')
                ->whereNull('ingredient_family')
                ->update([
                    'ingredient_family' => DB::raw('category'),
                ]);

            DB::statement('DROP INDEX IF EXISTS ingredients_category_index');

            Schema::table('ingredients', function (Blueprint $table): void {
                $table->dropColumn('category');
            });
        }
    }
};
