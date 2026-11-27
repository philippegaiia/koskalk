<?php

namespace Database\Seeders;

use App\Models\IngredientFunction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the COSING function vocabulary.
 *
 * The list mirrors the European Commission CosIng functions reference,
 * checked on 2026-11-27 at
 * https://ec.europa.eu/growth/tools-databases/cosing/reference/functions.
 * The last legally published function definitions are those of Decision
 * 2006/257/EC; CosIng has revised the list since, and the current version
 * exists only in CosIng, in English.
 * Names match CosIng case-insensitively because enrichment maps CosIng
 * function strings to keys by name; descriptions keep the CosIng wording with
 * obvious spelling slips corrected. "NOT REPORTED" is left out: it marks a
 * missing function, not a function.
 */
class IngredientFunctionSeeder extends Seeder
{
    /**
     * Functions from the pre-2019 CosIng list, keyed to their current CosIng
     * successor. A null successor has no current equivalent.
     *
     * @var array<string, string|null>
     */
    public const RETIRED_FUNCTIONS = [
        'antidandruff' => 'antiseborrhoeic',
        'cosmetic_biocide' => null,
        'emollient' => 'skin_conditioning_emollient',
        'emulsifying' => 'surfactant_emulsifying',
        'foam_boosting' => 'surfactant_foam_boosting',
        'hydrotrope' => 'surfactant_hydrotrope',
        'masking' => 'fragrance',
        'stabilising' => null,
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
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

            $this->retireLegacyFunctions();
        });
    }

    /**
     * Moves assignments from retired functions to their CosIng successor and
     * deactivates the retired rows so historic records keep resolving.
     */
    private function retireLegacyFunctions(): void
    {
        $functionIds = IngredientFunction::query()->pluck('id', 'key');

        foreach (self::RETIRED_FUNCTIONS as $retiredKey => $successorKey) {
            $retiredId = $functionIds->get($retiredKey);

            if ($retiredId === null) {
                continue;
            }

            $successorId = $successorKey === null ? null : $functionIds->get($successorKey);

            if ($successorId !== null) {
                $this->moveAssignments($retiredId, $successorId);
            }

            IngredientFunction::query()->whereKey($retiredId)->update(['is_active' => false]);
        }
    }

    private function moveAssignments(int $retiredId, int $successorId): void
    {
        $assignments = DB::table('ingredient_function_ingredient');

        $assignments->clone()
            ->where('ingredient_function_id', $retiredId)
            ->whereIn('ingredient_id', DB::table('ingredient_function_ingredient')
                ->select('ingredient_id')
                ->where('ingredient_function_id', $successorId))
            ->delete();

        $assignments->clone()
            ->where('ingredient_function_id', $retiredId)
            ->update([
                'ingredient_function_id' => $successorId,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<int, array{key:string, name:string, description:string}>
     */
    private function functions(): array
    {
        return [
            ['key' => 'abrasive', 'name' => 'Abrasive', 'description' => 'Removing unwanted tissue or foreign materials from various body surfaces, including mechanical tooth cleaning and/or gloss improvement.'],
            ['key' => 'absorbent', 'name' => 'Absorbent', 'description' => 'Taking up water- and/or oil-soluble (dissolved or finely dispersed) substances.'],
            ['key' => 'adhesive', 'name' => 'Adhesive', 'description' => 'Tending to unite/bind/bond surfaces together.'],
            ['key' => 'antiseborrhoeic', 'name' => 'Anti-seborrheic', 'description' => 'Preventing or relieving the symptoms of seborrhea and/or seborrheic dermatitis, including dandruff.'],
            ['key' => 'anti_sebum', 'name' => 'Anti-sebum', 'description' => 'Helping control sebum production.'],
            ['key' => 'anticaking', 'name' => 'Anticaking', 'description' => 'Preventing agglomeration of particulate solids into lumps or hard masses (cohesive cake), thus allowing free flow of particles.'],
            ['key' => 'anticorrosive', 'name' => 'Anticorrosive', 'description' => 'Preventing and/or inhibiting the corrosion of the packaging material.'],
            ['key' => 'antifoaming', 'name' => 'Antifoaming', 'description' => 'Suppressing foam during manufacturing or reducing the tendency of finished products to generate foam.'],
            ['key' => 'antimicrobial', 'name' => 'Antimicrobial', 'description' => 'Preventing and/or slowing down microbial growth.'],
            ['key' => 'antioxidant', 'name' => 'Antioxidant', 'description' => 'Inhibiting reactions promoted by oxygen, thus avoiding oxidation and rancidity.'],
            ['key' => 'antiperspirant', 'name' => 'Antiperspirant', 'description' => 'Reducing perspiration.'],
            ['key' => 'antiplaque', 'name' => 'Antiplaque', 'description' => 'Helping protect against plaque.'],
            ['key' => 'antistatic', 'name' => 'Antistatic', 'description' => 'Preventing and/or reducing static electricity by neutralising electrical charge on surfaces.'],
            ['key' => 'astringent', 'name' => 'Astringent', 'description' => 'Contracting and/or tightening the skin.'],
            ['key' => 'binding', 'name' => 'Binding', 'description' => 'Providing adhesive properties during and after compression in cosmetic tablets and/or cakes.'],
            ['key' => 'bleaching', 'name' => 'Bleaching', 'description' => 'Bleaching or lightening the shade of hair and/or skin.'],
            ['key' => 'buffering', 'name' => 'Buffering', 'description' => 'Stabilising the pH of an aqueous medium in a narrow range even if an acid or base is added. Altering and/or maintaining a cosmetic product\'s pH at the desired level.'],
            ['key' => 'bulking', 'name' => 'Bulking', 'description' => 'Non-reactive (chemically inert), solid ingredients that dilute other solids and/or increase the volume of cosmetic products.'],
            ['key' => 'chelating', 'name' => 'Chelating', 'description' => 'Forming complexes with metal ions which could affect the stability and/or appearance of cosmetics.'],
            ['key' => 'cleansing', 'name' => 'Cleansing', 'description' => 'Helping to keep the body surface clean.'],
            ['key' => 'colorant', 'name' => 'Colorant', 'description' => 'Exclusively or mainly intended to colour the cosmetic product, the body as a whole or certain parts thereof, by absorption or reflection of visible light; (precursors of oxidative hair colorants shall be deemed colorants). All authorised colorants are substances in the positive list of Annex IV to the Cosmetics Regulation 1223/2009.'],
            ['key' => 'denaturant', 'name' => 'Denaturant', 'description' => 'Rendering cosmetics unpalatable. Mostly added to cosmetics containing ethyl alcohol to make it unsuitable for ingestion.'],
            ['key' => 'deodorant', 'name' => 'Deodorant', 'description' => 'Reducing and/or eliminating unpleasant odour. Contributing against the formation of malodour on body surfaces.'],
            ['key' => 'depilatory', 'name' => 'Depilatory', 'description' => 'Breaking down the mechanical strength of hair fibers so that they can be removed by mild scraping/rubbing.'],
            ['key' => 'detangling', 'name' => 'Detangling', 'description' => 'Reducing or eliminating hair intertwining due to hair surface alteration or damage and, thus, helping combing.'],
            ['key' => 'dispersing_non_surfactant', 'name' => 'Dispersing non-surfactant', 'description' => 'Facilitating the dispersion of solids in liquids. They function primarily by coating the solid through the process of adsorption, thus changing the surface characteristic of the suspended solid.'],
            ['key' => 'emulsion_stabilising', 'name' => 'Emulsion stabilising', 'description' => 'Helping the process of emulsification and improving emulsion stability and shelf-life.'],
            ['key' => 'epilating', 'name' => 'Epilating', 'description' => 'Applied to the skin, and stripped off quickly to remove hair. With epilation the entire hair is removed from the follicle, whereas with depilation the hair is removed only to the surface of the skin.'],
            ['key' => 'exfoliating', 'name' => 'Exfoliating', 'description' => 'Initiating and/or accelerating removal of the layers of dead skin cells from the skin surface.'],
            ['key' => 'eyelash_conditioning', 'name' => 'Eyelash conditioning', 'description' => 'Conditioning and enhancing the appearance of eyelashes. Improving the gloss or sheen of eyelashes, coating the eyelash hair to increase the appearance of its diameter and length, or helping with the separation of the eyelash hair.'],
            ['key' => 'film_forming', 'name' => 'Film forming', 'description' => 'Producing (upon application) a continuous film on the skin, hair or nails.'],
            ['key' => 'flavouring', 'name' => 'Flavouring', 'description' => 'Imparting taste and/or flavour to a cosmetic product.'],
            ['key' => 'foaming', 'name' => 'Foaming', 'description' => 'Trapping numerous small bubbles of air or other gas within a small volume of liquid by modifying the surface tension of the liquid.'],
            ['key' => 'fragrance', 'name' => 'Fragrance', 'description' => 'Imparting an odour or taste. Creating a perceivable pleasant smell and/or masking a bad smell.'],
            ['key' => 'gel_forming', 'name' => 'Gel forming', 'description' => 'Giving the consistency of a gel (a semi-solid preparation with some elasticity) to a liquid preparation.'],
            ['key' => 'hair_conditioning', 'name' => 'Hair conditioning', 'description' => 'Enhancing the appearance and feel of hair. Leaving the hair easy to comb, supple, soft and shiny and/or imparting volume, lightness, gloss, texture, etc.'],
            ['key' => 'hair_dyeing', 'name' => 'Hair dyeing', 'description' => 'Imparting color to hair. Hair dyeing preparations may be temporary, semi-permanent, permanent, depending on the length of time the colorant remains on the hair.'],
            ['key' => 'hair_fixing', 'name' => 'Hair fixing', 'description' => 'Permitting physical control of hair style.'],
            ['key' => 'hair_waving_or_straightening', 'name' => 'Hair waving or straightening', 'description' => 'Modifying the chemical structure of the hair, allowing it to be set in the style required (permanent waves or hair straightening).'],
            ['key' => 'humectant', 'name' => 'Humectant', 'description' => 'Retaining and/or preserving the moisture in a product during use.'],
            ['key' => 'keratolytic', 'name' => 'Keratolytic', 'description' => 'Helping to eliminate the dead cells of the stratum corneum.'],
            ['key' => 'light_stabilizer', 'name' => 'Light stabilizer', 'description' => 'Protecting the cosmetic product from deterioration effects of light.'],
            ['key' => 'lytic', 'name' => 'Lytic', 'description' => 'Helping to break down lipids, proteins, and polysaccharides into smaller components, usually by the action of enzymes.'],
            ['key' => 'moisturising', 'name' => 'Moisturising', 'description' => 'Increasing the water content of the skin and keeping it soft and smooth.'],
            ['key' => 'nail_conditioning', 'name' => 'Nail conditioning', 'description' => 'Improving and/or enhancing the cosmetic characteristics of the nail (moisturizing, increasing sheen, reducing brittleness and flaking, etc.).'],
            ['key' => 'nail_sculpting', 'name' => 'Nail sculpting', 'description' => 'Forming a hard structure that resembles the nail plate.'],
            ['key' => 'occlusive', 'name' => 'Occlusive', 'description' => 'Preventing and/or slowing down the evaporation of water from the skin surface.'],
            ['key' => 'opacifying', 'name' => 'Opacifying', 'description' => 'Reducing transparency or translucency of cosmetics.'],
            ['key' => 'oral_care', 'name' => 'Oral care', 'description' => 'Providing cosmetic effects to the oral cavity, e.g. cleansing, deodorising, protecting.'],
            ['key' => 'oxidising', 'name' => 'Oxidising', 'description' => 'Changing the chemical nature of another ingredient by adding oxygen (or removing hydrogen).'],
            ['key' => 'pearlescent', 'name' => 'Pearlescent', 'description' => 'Imparting a nacreous appearance to cosmetics.'],
            ['key' => 'perfuming', 'name' => 'Perfuming', 'description' => 'Used for perfume and aromatic raw materials.'],
            ['key' => 'plasticiser', 'name' => 'Plasticiser', 'description' => 'Softening and making supple synthetic polymers that otherwise could not be easily deformed, spread or worked out.'],
            ['key' => 'preservative', 'name' => 'Preservative', 'description' => 'Exclusively or mainly intended to inhibit the development of micro-organisms in the cosmetic product. All authorised preservatives are substances in the positive list of Annex V to the Cosmetics Regulation 1223/2009.'],
            ['key' => 'propellant', 'name' => 'Propellant', 'description' => 'Generating pressure in an aerosol pack, expelling contents when the valve is opened. Some liquefied propellants can act as solvents.'],
            ['key' => 'reducing', 'name' => 'Reducing', 'description' => 'Changing the chemical nature of another ingredient by adding hydrogen (or removing oxygen).'],
            ['key' => 'refatting', 'name' => 'Refatting', 'description' => 'Replenishing the lipids of the hair or of the top layers of the skin.'],
            ['key' => 'refreshing', 'name' => 'Refreshing', 'description' => 'Imparting a pleasant freshness to the skin.'],
            ['key' => 'skin_conditioning', 'name' => 'Skin conditioning', 'description' => 'Maintaining the skin in good condition.'],
            ['key' => 'skin_conditioning_emollient', 'name' => 'Skin conditioning - emollient', 'description' => 'Acting as lubricants on the skin surface providing the skin with a soft and smooth appearance.'],
            ['key' => 'skin_conditioning_humectant', 'name' => 'Skin conditioning - humectant', 'description' => 'Increasing the water content of the top layers of the skin. Humectants are primarily hygroscopic agents drawing moisture from the surrounding air.'],
            ['key' => 'skin_conditioning_miscellaneous', 'name' => 'Skin conditioning - miscellaneous', 'description' => 'Enhancing the appearance of dry and/or damaged skin by reducing flaking and restoring suppleness.'],
            ['key' => 'skin_conditioning_occlusive', 'name' => 'Skin conditioning - occlusive', 'description' => 'Retarding the evaporation of water from the skin surface. Occlusives are generally lipids which tend to remain on the skin surface and thus increase the water content of skin.'],
            ['key' => 'skin_protecting', 'name' => 'Skin protecting', 'description' => 'Helping to avoid harmful effects to the skin from external factors.'],
            ['key' => 'slip_modifier', 'name' => 'Slip modifier', 'description' => 'Enhancing the flow properties of other ingredients without reacting chemically with them.'],
            ['key' => 'smoothing', 'name' => 'Smoothing', 'description' => 'Seeking to achieve an even skin surface by decreasing roughness or irregularities.'],
            ['key' => 'solvent', 'name' => 'Solvent', 'description' => 'Dissolving other components of cosmetics. Solvents are usually liquids (aqueous and nonaqueous).'],
            ['key' => 'soothing', 'name' => 'Soothing', 'description' => 'Lightening discomfort of the skin or of the scalp.'],
            ['key' => 'surface_modifier', 'name' => 'Surface modifier', 'description' => 'Applied to other cosmetic components to make them more hydrophilic or hydrophobic, or to modify their physical/chemical properties (in some cases, surface modifiers may form a covalent bond with substrates).'],
            ['key' => 'surfactant', 'name' => 'Surfactant', 'description' => 'Helping other ingredients that normally do not mix to dissolve or disperse in one another. Surfactants are also called surface active agents as they lower the surface tension of water or reduce the interfacial tension between immiscible components.'],
            ['key' => 'surfactant_cleansing', 'name' => 'Surfactant - cleansing', 'description' => 'Wetting body surfaces, emulsifying or solubilizing oils, and suspending soil (generally, such ingredients contribute to the foaming and lathering properties of cleansing products).'],
            ['key' => 'surfactant_dispersing', 'name' => 'Surfactant - dispersing', 'description' => 'Helping distribute an insoluble solid in a liquid phase.'],
            ['key' => 'surfactant_emulsifying', 'name' => 'Surfactant - emulsifying', 'description' => 'Helping suspensions or dispersions of liquids in a second liquid by reducing the surface tension of the substances to be emulsified.'],
            ['key' => 'surfactant_foam_boosting', 'name' => 'Surfactant - foam boosting', 'description' => 'Increasing foaming capacity and/or stabilising foams in general.'],
            ['key' => 'surfactant_hydrotrope', 'name' => 'Surfactant - hydrotrope', 'description' => 'Enhancing the water solubility of another surfactant.'],
            ['key' => 'surfactant_solubilizing', 'name' => 'Surfactant - solubilizing', 'description' => 'Helping another component (solute) to dissolve in a medium in which it is not normally soluble.'],
            ['key' => 'tanning', 'name' => 'Tanning', 'description' => 'Darkening the skin with or without exposure to UV.'],
            ['key' => 'tonic', 'name' => 'Tonic', 'description' => 'Producing a feeling of well-being on skin and hair.'],
            ['key' => 'uv_absorber', 'name' => 'UV absorber', 'description' => 'Protecting the cosmetic product from the effects of UV-light.'],
            ['key' => 'uv_filter', 'name' => 'UV filter', 'description' => 'Exclusively or mainly intended to protect the skin and/or hair against certain UV radiation by absorbing, reflecting or scattering UV radiation. All authorised UV filters are substances in the positive list of Annex VI to the Cosmetics Regulation 1223/2009.'],
            ['key' => 'viscosity_controlling', 'name' => 'Viscosity controlling', 'description' => 'Increasing or decreasing the viscosity (thickness) of cosmetics.'],
            ['key' => 'ph_adjusters', 'name' => 'pH adjusters', 'description' => 'Controlling the pH of cosmetic products.'],
        ];
    }
}
