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
        DB::table('ingredients')
            ->where('is_potentially_saponifiable', true)
            ->update(['is_soap_saponification_trusted' => true]);

        foreach ($this->legacyTaxonomyMappings() as $legacyCategory => $mapping) {
            DB::table('ingredients')
                ->where('category', $legacyCategory)
                ->update([
                    'category' => $mapping['category'],
                    'subcategory' => $mapping['subcategory'],
                ]);
        }

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->string('taxonomy_source')->default('workspace_user')->change();
            $table->dropColumn('is_potentially_saponifiable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->boolean('is_potentially_saponifiable')->default(false)->after('visibility');
            $table->string('taxonomy_source')->default('workspace_user')->change();
        });

        DB::table('ingredients')
            ->where('is_soap_saponification_trusted', true)
            ->update(['is_potentially_saponifiable' => true]);
    }

    /**
     * @return array<string, array{category:string, subcategory:?string}>
     */
    private function legacyTaxonomyMappings(): array
    {
        return [
            'carrier_oil' => ['category' => 'lipids', 'subcategory' => 'vegetable_oils'],
            'essential_oil' => ['category' => 'aromatic_materials', 'subcategory' => 'essential_oils'],
            'fragrance_oil' => ['category' => 'aromatic_materials', 'subcategory' => 'fragrance_blends'],
            'botanical_extract' => ['category' => 'botanicals_extracts', 'subcategory' => 'aqueous_glycerinated_extracts'],
            'co2_extract' => ['category' => 'aromatic_materials', 'subcategory' => 'co2_extracts'],
            'clay' => ['category' => 'minerals_salts_powders', 'subcategory' => 'clays'],
            'glycol' => ['category' => 'humectants_polyols', 'subcategory' => 'glycerin_glycols'],
            'colorant' => ['category' => 'colourants', 'subcategory' => 'mineral_pigments'],
            'preservative' => ['category' => 'preservation_stability', 'subcategory' => 'preservatives'],
            'alkali' => ['category' => 'soapmaking_alkalis', 'subcategory' => 'other_soap_alkalis'],
            'liquid' => ['category' => 'water_solvents_carriers', 'subcategory' => 'other_carriers'],
            'additive' => ['category' => 'other', 'subcategory' => null],
        ];
    }
};
