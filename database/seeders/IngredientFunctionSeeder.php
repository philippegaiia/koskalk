<?php

namespace Database\Seeders;

use App\Models\IngredientFunction;
use Illuminate\Database\Seeder;

class IngredientFunctionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect($this->functions())
            ->each(function (array $function, int $index): void {
                IngredientFunction::query()->updateOrCreate(
                    ['key' => $function['key']],
                    [
                        'name' => $function['name'],
                        'description' => $function['description'],
                        'sort_order' => $index + 1,
                        'is_active' => true,
                    ],
                );
            });
    }

    /**
     * @return array<int, array{key:string, name:string, description:string}>
     */
    private function functions(): array
    {
        return [
            ['key' => 'abrasive', 'name' => 'Abrasive', 'description' => 'Removes material from body surfaces, aids mechanical tooth cleaning, or improves gloss.'],
            ['key' => 'absorbent', 'name' => 'Absorbent', 'description' => 'Takes up water-soluble or oil-soluble dissolved and finely dispersed substances.'],
            ['key' => 'anticaking', 'name' => 'Anticaking', 'description' => 'Keeps solid particles free flowing and helps prevent lump formation.'],
            ['key' => 'anticorrosive', 'name' => 'Anticorrosive', 'description' => 'Helps prevent corrosion of the packaging.'],
            ['key' => 'antidandruff', 'name' => 'Antidandruff', 'description' => 'Helps control dandruff.'],
            ['key' => 'antifoaming', 'name' => 'Antifoaming', 'description' => 'Suppresses foam during manufacturing or reduces foaming in the finished product.'],
            ['key' => 'antimicrobial', 'name' => 'Antimicrobial', 'description' => 'Helps control the growth of microorganisms on the skin.'],
            ['key' => 'antioxidant', 'name' => 'Antioxidant', 'description' => 'Inhibits oxygen-driven reactions and helps prevent oxidation or rancidity.'],
            ['key' => 'antiperspirant', 'name' => 'Antiperspirant', 'description' => 'Reduces perspiration.'],
            ['key' => 'antiplaque', 'name' => 'Antiplaque', 'description' => 'Helps protect against plaque.'],
            ['key' => 'antiseborrhoeic', 'name' => 'Antiseborrhoeic', 'description' => 'Helps control sebum production.'],
            ['key' => 'antistatic', 'name' => 'Antistatic', 'description' => 'Reduces static electricity by neutralising electrical charge on a surface.'],
            ['key' => 'astringent', 'name' => 'Astringent', 'description' => 'Contracts the skin.'],
            ['key' => 'binding', 'name' => 'Binding', 'description' => 'Provides cohesion in cosmetics.'],
            ['key' => 'bleaching', 'name' => 'Bleaching', 'description' => 'Lightens the shade of hair or skin.'],
            ['key' => 'buffering', 'name' => 'Buffering', 'description' => 'Stabilises the pH of cosmetics.'],
            ['key' => 'bulking', 'name' => 'Bulking', 'description' => 'Reduces bulk density.'],
            ['key' => 'chelating', 'name' => 'Chelating', 'description' => 'Forms complexes with metal ions that could affect stability or appearance.'],
            ['key' => 'cleansing', 'name' => 'Cleansing', 'description' => 'Helps keep the body, teeth, or hair clean.'],
            ['key' => 'colorant', 'name' => 'Colorant', 'description' => 'Colours the cosmetic product or imparts colour to skin, hair, or nails.'],
            ['key' => 'cosmetic_biocide', 'name' => 'Cosmetic biocide', 'description' => 'Helps preserve the cosmetic product by controlling harmful organisms in the formula.'],
            ['key' => 'denaturant', 'name' => 'Denaturant', 'description' => 'Makes the product unpleasant to taste, commonly for alcohol-containing products.'],
            ['key' => 'deodorant', 'name' => 'Deodorant', 'description' => 'Reduces or masks unpleasant body odours.'],
            ['key' => 'depilatory', 'name' => 'Depilatory', 'description' => 'Removes unwanted body hair.'],
            ['key' => 'detangling', 'name' => 'Detangling', 'description' => 'Reduces tangling of the hair and helps combing.'],
            ['key' => 'emollient', 'name' => 'Emollient', 'description' => 'Softens and smooths the skin.'],
            ['key' => 'emulsifying', 'name' => 'Emulsifying', 'description' => 'Promotes formation of fine mixtures of otherwise immiscible liquids.'],
            ['key' => 'emulsion_stabilising', 'name' => 'Emulsion stabilising', 'description' => 'Supports emulsification and improves emulsion stability and shelf life.'],
            ['key' => 'film_forming', 'name' => 'Film forming', 'description' => 'Forms a continuous film after application on skin, hair, or nails.'],
            ['key' => 'foaming', 'name' => 'Foaming', 'description' => 'Generates or supports foam formation.'],
            ['key' => 'foam_boosting', 'name' => 'Foam boosting', 'description' => 'Improves foam volume, texture, or stability.'],
            ['key' => 'gel_forming', 'name' => 'Gel forming', 'description' => 'Gives a liquid preparation a gel-like consistency.'],
            ['key' => 'hair_conditioning', 'name' => 'Hair conditioning', 'description' => 'Improves the cosmetic feel, manageability, softness, or shine of hair.'],
            ['key' => 'hair_dyeing', 'name' => 'Hair dyeing', 'description' => 'Colours the hair.'],
            ['key' => 'hair_fixing', 'name' => 'Hair fixing', 'description' => 'Provides physical control of the hairstyle.'],
            ['key' => 'hair_waving_or_straightening', 'name' => 'Hair waving or straightening', 'description' => 'Changes hair structure to produce waving, curling, or straightening.'],
            ['key' => 'humectant', 'name' => 'Humectant', 'description' => 'Helps maintain and retain moisture.'],
            ['key' => 'hydrotrope', 'name' => 'Hydrotrope', 'description' => 'Increases the solubility of ingredients that are only slightly soluble in water.'],
            ['key' => 'keratolytic', 'name' => 'Keratolytic', 'description' => 'Helps remove dead cells from the stratum corneum.'],
            ['key' => 'masking', 'name' => 'Masking', 'description' => 'Reduces or inhibits the base odour or taste of the product.'],
            ['key' => 'moisturising', 'name' => 'Moisturising', 'description' => 'Increases the water content of the skin and helps keep it soft and smooth.'],
            ['key' => 'nail_conditioning', 'name' => 'Nail conditioning', 'description' => 'Improves the cosmetic characteristics of nails.'],
            ['key' => 'opacifying', 'name' => 'Opacifying', 'description' => 'Reduces transparency or translucency.'],
            ['key' => 'oral_care', 'name' => 'Oral care', 'description' => 'Provides cosmetic effects to the oral cavity such as cleaning, deodorising, or protecting.'],
            ['key' => 'oxidising', 'name' => 'Oxidising', 'description' => 'Changes the chemical nature of another substance by adding oxygen or removing hydrogen.'],
            ['key' => 'pearlescent', 'name' => 'Pearlescent', 'description' => 'Gives a pearly appearance.'],
            ['key' => 'perfuming', 'name' => 'Perfuming', 'description' => 'Imparts or modifies odour and gives the product a pleasant scent.'],
            ['key' => 'plasticiser', 'name' => 'Plasticiser', 'description' => 'Softens and improves flexibility of another substance.'],
            ['key' => 'preservative', 'name' => 'Preservative', 'description' => 'Primarily inhibits the development of microorganisms in the cosmetic product.'],
            ['key' => 'propellant', 'name' => 'Propellant', 'description' => 'Provides pressure in an aerosol package to expel the contents.'],
            ['key' => 'reducing', 'name' => 'Reducing', 'description' => 'Changes the chemical nature of another substance by adding hydrogen or removing oxygen.'],
            ['key' => 'refatting', 'name' => 'Refatting', 'description' => 'Restores lipids to the skin or hair.'],
            ['key' => 'refreshing', 'name' => 'Refreshing', 'description' => 'Creates an immediate sensation of freshness.'],
            ['key' => 'skin_conditioning', 'name' => 'Skin conditioning', 'description' => 'Maintains the skin in good condition.'],
            ['key' => 'skin_protecting', 'name' => 'Skin protecting', 'description' => 'Helps protect the skin from harmful external factors.'],
            ['key' => 'smoothing', 'name' => 'Smoothing', 'description' => 'Helps create a more even skin surface by reducing roughness or irregularities.'],
            ['key' => 'soothing', 'name' => 'Soothing', 'description' => 'Helps relieve discomfort of the skin or scalp.'],
            ['key' => 'solvent', 'name' => 'Solvent', 'description' => 'Dissolves other substances.'],
            ['key' => 'stabilising', 'name' => 'Stabilising', 'description' => 'Improves ingredient or formulation stability and shelf life.'],
            ['key' => 'surfactant', 'name' => 'Surfactant', 'description' => 'Lowers surface tension and helps even product distribution.'],
            ['key' => 'tanning', 'name' => 'Tanning', 'description' => 'Darkens the skin with or without UV exposure.'],
            ['key' => 'tonic', 'name' => 'Tonic', 'description' => 'Provides a feeling of well-being on skin or hair.'],
            ['key' => 'uv_absorber', 'name' => 'UV absorber', 'description' => 'Protects the cosmetic product from the effects of UV light.'],
            ['key' => 'uv_filter', 'name' => 'UV filter', 'description' => 'Filters UV rays to help protect skin or hair from their harmful effects.'],
            ['key' => 'viscosity_controlling', 'name' => 'Viscosity controlling', 'description' => 'Increases or decreases viscosity.'],
        ];
    }
}
