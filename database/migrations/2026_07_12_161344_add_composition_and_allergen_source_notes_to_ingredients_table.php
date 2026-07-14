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
            $table->text('composition_source_notes')->nullable();
            $table->text('allergen_source_notes')->nullable();
        });

        $this->backfillParentSource('ingredient_components', 'composition_source_notes');
        $this->backfillParentSource('ingredient_allergen_entries', 'allergen_source_notes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropColumn(['composition_source_notes', 'allergen_source_notes']);
        });
    }

    /**
     * Copy the first non-empty per-row source_notes for each ingredient into the
     * given parent column. The per-row columns are intentionally left in place.
     */
    private function backfillParentSource(string $childTable, string $parentColumn): void
    {
        DB::table($childTable)
            ->select(['id', 'ingredient_id', 'source_notes'])
            ->whereNotNull('source_notes')
            ->where('source_notes', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($parentColumn): void {
                foreach ($rows->unique('ingredient_id') as $row) {
                    DB::table('ingredients')
                        ->where('id', $row->ingredient_id)
                        ->whereNull($parentColumn)
                        ->update([$parentColumn => $row->source_notes]);
                }
            });
    }
};
