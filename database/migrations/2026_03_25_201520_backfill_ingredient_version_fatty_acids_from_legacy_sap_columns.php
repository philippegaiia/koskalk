<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $fattyAcidIds = DB::table('fatty_acids')->pluck('id', 'key');

        $legacyKeys = [
            'lauric',
            'myristic',
            'palmitic',
            'stearic',
            'ricinoleic',
            'oleic',
            'linoleic',
            'linolenic',
        ];

        DB::table('ingredient_sap_profiles')
            ->select(array_merge(['ingredient_version_id'], $legacyKeys))
            ->orderBy('id')
            ->each(function (object $profile) use ($fattyAcidIds, $legacyKeys): void {
                $rows = [];
                $timestamp = now();

                foreach ($legacyKeys as $key) {
                    $value = $profile->{$key} ?? null;

                    if ($value === null || ! isset($fattyAcidIds[$key])) {
                        continue;
                    }

                    $rows[] = [
                        'ingredient_version_id' => $profile->ingredient_version_id,
                        'fatty_acid_id' => $fattyAcidIds[$key],
                        'percentage' => $value,
                        'source_notes' => 'Backfilled from legacy ingredient_sap_profiles columns.',
                        'source_data' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                if ($rows !== []) {
                    DB::table('ingredient_version_fatty_acids')->upsert(
                        $rows,
                        ['ingredient_version_id', 'fatty_acid_id'],
                        ['percentage', 'source_notes', 'updated_at'],
                    );
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ingredient_version_fatty_acids')
            ->where('source_notes', 'Backfilled from legacy ingredient_sap_profiles columns.')
            ->delete();
    }
};
