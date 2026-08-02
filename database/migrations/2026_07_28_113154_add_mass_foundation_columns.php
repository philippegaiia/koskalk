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
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->decimal('batch_mass_grams', total: 20, places: 9)
                ->nullable()
                ->after('batch_unit');
        });

        Schema::table('recipe_version_costings', function (Blueprint $table) {
            $table->decimal('oil_mass_grams_for_costing', total: 20, places: 9)
                ->nullable()
                ->after('oil_unit_for_costing');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('mass_display_system', 24)
                ->default('metric')
                ->after('country');
        });

        $this->backfillCanonicalMass(
            table: 'recipe_versions',
            quantityColumn: 'batch_size',
            unitColumn: 'batch_unit',
            canonicalColumn: 'batch_mass_grams',
        );

        $this->backfillCanonicalMass(
            table: 'recipe_version_costings',
            quantityColumn: 'oil_weight_for_costing',
            unitColumn: 'oil_unit_for_costing',
            canonicalColumn: 'oil_mass_grams_for_costing',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('mass_display_system');
        });

        Schema::table('recipe_version_costings', function (Blueprint $table) {
            $table->dropColumn('oil_mass_grams_for_costing');
        });

        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->dropColumn('batch_mass_grams');
        });
    }

    private function backfillCanonicalMass(
        string $table,
        string $quantityColumn,
        string $unitColumn,
        string $canonicalColumn,
    ): void {
        $gramsPerUnit = [
            'g' => '1',
            'kg' => '1000',
            'oz' => '28.349523125',
            'lb' => '453.59237',
        ];

        DB::table($table)
            ->select(['id', $quantityColumn, $unitColumn])
            ->whereNull($canonicalColumn)
            ->whereNotNull($quantityColumn)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (
                $canonicalColumn,
                $gramsPerUnit,
                $quantityColumn,
                $table,
                $unitColumn,
            ): void {
                foreach ($rows as $row) {
                    $factor = $gramsPerUnit[(string) $row->{$unitColumn}] ?? $gramsPerUnit['g'];
                    $grams = bcmul((string) $row->{$quantityColumn}, $factor, 12);

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([$canonicalColumn => $this->roundPositive($grams, 9)]);
                }
            });
    }

    private function roundPositive(string $quantity, int $scale): string
    {
        $roundingIncrement = '0.'.str_repeat('0', $scale).'5';
        $adjusted = bcadd($quantity, $roundingIncrement, $scale + 1);

        return bcadd($adjusted, '0', $scale);
    }
};
