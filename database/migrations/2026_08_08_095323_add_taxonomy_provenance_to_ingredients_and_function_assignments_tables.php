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
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->string('subcategory')->nullable()->after('category');
            $table->string('taxonomy_source')->default('workspace_user')->after('subcategory');
            $table->timestamp('taxonomy_reviewed_at')->nullable()->after('taxonomy_source');
            $table->foreignId('taxonomy_reviewed_by_user_id')
                ->nullable()
                ->after('taxonomy_reviewed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('cosing_reference')->nullable()->after('taxonomy_reviewed_by_user_id');
            $table->boolean('is_soap_saponification_trusted')->default(false)->after('is_potentially_saponifiable');
            $table->boolean('requires_aromatic_compliance')->default(false)->after('is_soap_saponification_trusted');

            $table->index(['category', 'subcategory']);
            $table->index('taxonomy_source');
        });

        Schema::table('ingredient_function_ingredient', function (Blueprint $table): void {
            $table->string('source')->default('manual')->after('ingredient_function_id');
            $table->string('source_reference')->nullable()->after('source');
            $table->timestamp('source_checked_at')->nullable()->after('source_reference');
            $table->foreignId('assigned_by_user_id')
                ->nullable()
                ->after('source_checked_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('source');
        });

        DB::table('ingredients')
            ->whereNull('owner_type')
            ->update(['taxonomy_source' => 'platform_curated']);

        DB::table('ingredients')
            ->whereNotNull('owner_type')
            ->update(['taxonomy_source' => 'workspace_user']);

        DB::statement('UPDATE ingredients SET is_soap_saponification_trusted = is_potentially_saponifiable WHERE is_potentially_saponifiable = true');

        DB::table('ingredients')
            ->whereIn('category', ['essential_oil', 'fragrance_oil', 'co2_extract'])
            ->update(['requires_aromatic_compliance' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_function_ingredient', function (Blueprint $table): void {
            $table->dropForeign(['assigned_by_user_id']);
            $table->dropIndex('ingredient_function_ingredient_source_index');
            $table->dropColumn([
                'source',
                'source_reference',
                'source_checked_at',
                'assigned_by_user_id',
            ]);
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropForeign(['taxonomy_reviewed_by_user_id']);
            $table->dropIndex('ingredients_category_subcategory_index');
            $table->dropIndex('ingredients_taxonomy_source_index');
            $table->dropColumn([
                'subcategory',
                'taxonomy_source',
                'taxonomy_reviewed_at',
                'taxonomy_reviewed_by_user_id',
                'cosing_reference',
                'is_soap_saponification_trusted',
                'requires_aromatic_compliance',
            ]);
        });
    }
};
