<?php

use App\SoapSap;
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

        DB::table('ingredient_sap_profiles')
            ->select(['id', 'naoh_sap_value', 'koh_sap_value', 'fatty_acid_profile'])
            ->orderBy('id')
            ->each(function (object $profile): void {
                $fattyAcidProfile = json_decode($profile->fatty_acid_profile ?? 'null', true);

                DB::table('ingredient_sap_profiles')
                    ->where('id', $profile->id)
                    ->update([
                        'koh_sap_value' => $profile->koh_sap_value ?? (
                            $profile->naoh_sap_value === null
                                ? null
                                : SoapSap::deriveKohFromNaoh((float) $profile->naoh_sap_value)
                        ),
                        'lauric' => $fattyAcidProfile['lauric'] ?? null,
                        'myristic' => $fattyAcidProfile['myristic'] ?? null,
                        'palmitic' => $fattyAcidProfile['palmitic'] ?? null,
                        'stearic' => $fattyAcidProfile['stearic'] ?? null,
                        'ricinoleic' => $fattyAcidProfile['ricinoleic'] ?? null,
                        'oleic' => $fattyAcidProfile['oleic'] ?? null,
                        'linoleic' => $fattyAcidProfile['linoleic'] ?? null,
                        'linolenic' => $fattyAcidProfile['linolenic'] ?? null,
                    ]);
            });

        Schema::table('ingredient_sap_profiles', function (Blueprint $table) {
            $table->dropColumn(['naoh_sap_value', 'fatty_acid_profile', 'soap_quality_profile']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_sap_profiles', function (Blueprint $table) {
            $table->decimal('naoh_sap_value', 10, 6)->nullable()->after('ingredient_version_id');
            $table->json('fatty_acid_profile')->nullable()->after('linolenic');
            $table->json('soap_quality_profile')->nullable()->after('fatty_acid_profile');
        });

        DB::table('ingredient_sap_profiles')
            ->select([
                'id',
                'koh_sap_value',
                'lauric',
                'myristic',
                'palmitic',
                'stearic',
                'ricinoleic',
                'oleic',
                'linoleic',
                'linolenic',
            ])
            ->orderBy('id')
            ->each(function (object $profile): void {
                DB::table('ingredient_sap_profiles')
                    ->where('id', $profile->id)
                    ->update([
                        'naoh_sap_value' => $profile->koh_sap_value === null
                            ? null
                            : SoapSap::deriveNaohFromKoh((float) $profile->koh_sap_value),
                        'fatty_acid_profile' => json_encode([
                            'lauric' => $profile->lauric,
                            'myristic' => $profile->myristic,
                            'palmitic' => $profile->palmitic,
                            'stearic' => $profile->stearic,
                            'ricinoleic' => $profile->ricinoleic,
                            'oleic' => $profile->oleic,
                            'linoleic' => $profile->linoleic,
                            'linolenic' => $profile->linolenic,
                        ], JSON_THROW_ON_ERROR),
                        'soap_quality_profile' => null,
                    ]);
            });

        Schema::table('ingredient_sap_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'lauric',
                'myristic',
                'palmitic',
                'stearic',
                'ricinoleic',
                'oleic',
                'linoleic',
                'linolenic',
            ]);
        });
    }
};
