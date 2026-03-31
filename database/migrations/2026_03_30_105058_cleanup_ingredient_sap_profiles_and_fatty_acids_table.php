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
        Schema::rename('ingredient_version_fatty_acids', 'ingredient_fatty_acids');

        Schema::table('ingredient_sap_profiles', function (Blueprint $table) {
            $table->decimal('iodine_value', 8, 3)->nullable()->after('koh_sap_value');
            $table->decimal('ins_value', 8, 3)->nullable()->after('iodine_value');
        });

        $fattyAcidIdsByKey = DB::table('fatty_acids')
            ->whereIn('key', $this->coreFattyAcidKeys())
            ->pluck('id', 'key');

        DB::table('ingredient_sap_profiles')
            ->select(array_merge(['ingredient_id', 'source_notes'], $this->coreFattyAcidKeys()))
            ->orderBy('ingredient_id')
            ->get()
            ->each(function (object $profile) use ($fattyAcidIdsByKey): void {
                $rows = [];
                $timestamp = now();

                foreach ($this->coreFattyAcidKeys() as $key) {
                    $value = $profile->{$key} ?? null;

                    if ($value === null || ! $fattyAcidIdsByKey->has($key)) {
                        continue;
                    }

                    $rows[] = [
                        'ingredient_id' => $profile->ingredient_id,
                        'fatty_acid_id' => $fattyAcidIdsByKey->get($key),
                        'percentage' => $value,
                        'source_notes' => $profile->source_notes,
                        'source_data' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                if ($rows !== []) {
                    DB::table('ingredient_fatty_acids')->insertOrIgnore($rows);
                }
            });

        Schema::table('ingredient_sap_profiles', function (Blueprint $table) {
            $table->dropColumn($this->coreFattyAcidKeys());
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_sap_profiles', function (Blueprint $table) {
            $table->decimal('lauric', 5, 2)->nullable()->after('koh_sap_value');
            $table->decimal('myristic', 5, 2)->nullable()->after('lauric');
            $table->decimal('palmitic', 5, 2)->nullable()->after('myristic');
            $table->decimal('stearic', 5, 2)->nullable()->after('palmitic');
            $table->decimal('ricinoleic', 5, 2)->nullable()->after('stearic');
            $table->decimal('oleic', 5, 2)->nullable()->after('ricinoleic');
            $table->decimal('linoleic', 5, 2)->nullable()->after('oleic');
            $table->decimal('linolenic', 5, 2)->nullable()->after('linoleic');
        });

        $entriesByIngredient = DB::table('ingredient_fatty_acids')
            ->join('fatty_acids', 'fatty_acids.id', '=', 'ingredient_fatty_acids.fatty_acid_id')
            ->whereIn('fatty_acids.key', $this->coreFattyAcidKeys())
            ->select([
                'ingredient_fatty_acids.ingredient_id',
                'ingredient_fatty_acids.percentage',
                'fatty_acids.key',
            ])
            ->orderBy('ingredient_fatty_acids.ingredient_id')
            ->get()
            ->groupBy('ingredient_id');

        DB::table('ingredient_sap_profiles')
            ->select(['id', 'ingredient_id'])
            ->orderBy('ingredient_id')
            ->get()
            ->each(function (object $profile) use ($entriesByIngredient): void {
                $legacyValues = array_fill_keys($this->coreFattyAcidKeys(), null);

                foreach ($entriesByIngredient->get($profile->ingredient_id, collect()) as $entry) {
                    $legacyValues[$entry->key] = $entry->percentage;
                }

                DB::table('ingredient_sap_profiles')
                    ->where('id', $profile->id)
                    ->update($legacyValues);
            });

        Schema::table('ingredient_sap_profiles', function (Blueprint $table) {
            $table->dropColumn(['iodine_value', 'ins_value']);
        });

        Schema::rename('ingredient_fatty_acids', 'ingredient_version_fatty_acids');
    }

    /**
     * @return array<int, string>
     */
    private function coreFattyAcidKeys(): array
    {
        return [
            'lauric',
            'myristic',
            'palmitic',
            'stearic',
            'ricinoleic',
            'oleic',
            'linoleic',
            'linolenic',
        ];
    }
};
