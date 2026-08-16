<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Practical subcategories used to narrow an ingredient family without making
 * users learn the complete CosIng function vocabulary.
 */
enum IngredientSubcategory: string implements HasLabel
{
    case VegetableOils = 'vegetable_oils';
    case Butters = 'butters';
    case AnimalFats = 'animal_fats';
    case FractionatedModifiedLipids = 'fractionated_modified_lipids';
    case PlantWaxes = 'plant_waxes';
    case AnimalWaxes = 'animal_waxes';
    case LiquidWaxes = 'liquid_waxes';
    case MineralWaxes = 'mineral_waxes';
    case SyntheticWaxes = 'synthetic_waxes';
    case MineralOils = 'mineral_oils';
    case PetrolatumOcclusives = 'petrolatum_occlusives';
    case HydrocarbonEmollients = 'hydrocarbon_emollients';
    case VolatileSilicones = 'volatile_silicones';
    case NonvolatileSilicones = 'nonvolatile_silicones';
    case SiliconeElastomers = 'silicone_elastomers';
    case FattyAcids = 'fatty_acids';
    case FattyAlcohols = 'fatty_alcohols';
    case CosmeticEsters = 'cosmetic_esters';
    case Anionic = 'anionic';
    case Amphoteric = 'amphoteric';
    case Nonionic = 'nonionic';
    case Cationic = 'cationic';
    case OilInWater = 'oil_in_water';
    case WaterInOil = 'water_in_oil';
    case CoEmulsifiers = 'co_emulsifiers';
    case Solubilizers = 'solubilizers';
    case GlycerinGlycols = 'glycerin_glycols';
    case SugarAlcohols = 'sugar_alcohols';
    case OtherHumectants = 'other_humectants';
    case Water = 'water';
    case Alcohols = 'alcohols';
    case OrganicSolvents = 'organic_solvents';
    case OtherCarriers = 'other_carriers';
    case Gums = 'gums';
    case CelluloseDerivatives = 'cellulose_derivatives';
    case SyntheticRheologyModifiers = 'synthetic_rheology_modifiers';
    case MineralThickeners = 'mineral_thickeners';
    case ConditioningPolymers = 'conditioning_polymers';
    case FilmFormingPolymers = 'film_forming_polymers';
    case HairFixativesResins = 'hair_fixatives_resins';
    case OtherFunctionalPolymers = 'other_functional_polymers';
    case Clays = 'clays';
    case Salts = 'salts';
    case StarchesAbsorbentPowders = 'starches_absorbent_powders';
    case FunctionalMineralPowders = 'functional_mineral_powders';
    case Vitamins = 'vitamins';
    case ExfoliatingAcids = 'exfoliating_acids';
    case ProteinsPeptidesAminoAcids = 'proteins_peptides_amino_acids';
    case UvFilters = 'uv_filters';
    case OtherActives = 'other_actives';
    case Hydrosols = 'hydrosols';
    case AqueousGlycerinatedExtracts = 'aqueous_glycerinated_extracts';
    case OilMacerates = 'oil_macerates';
    case DryExtracts = 'dry_extracts';
    case PlantPowders = 'plant_powders';
    case EssentialOils = 'essential_oils';
    case AbsolutesResinoids = 'absolutes_resinoids';
    case Co2Extracts = 'co2_extracts';
    case AromaCompounds = 'aroma_compounds';
    case FragranceBlends = 'fragrance_blends';
    case MineralPigments = 'mineral_pigments';
    case Micas = 'micas';
    case DyesLakes = 'dyes_lakes';
    case BotanicalColourants = 'botanical_colourants';
    case Preservatives = 'preservatives';
    case Antioxidants = 'antioxidants';
    case Chelators = 'chelators';
    case Acids = 'acids';
    case Bases = 'bases';
    case BufferSystems = 'buffer_systems';
    case SodiumHydroxide = 'sodium_hydroxide';
    case PotassiumHydroxide = 'potassium_hydroxide';
    case OtherSoapAlkalis = 'other_soap_alkalis';
    case NaturalParticles = 'natural_particles';
    case MineralAbrasives = 'mineral_abrasives';
    case SyntheticParticles = 'synthetic_particles';
    case ReadyMadeBases = 'ready_made_bases';
    case MeltAndPourSoapBases = 'melt_and_pour_soap_bases';
    case FunctionalBlends = 'functional_blends';
    case ProprietaryPremixes = 'proprietary_premixes';

    public function getLabel(): string|Htmlable|null
    {
        return $this->localizedLabel();
    }

    public function localizedLabel(?string $locale = null): string|Htmlable|null
    {
        return __(sprintf('ingredients.subcategories.%s.label', $this->value), [], $locale);
    }

    public function getDescription(): string|Htmlable|null
    {
        return $this->localizedDescription();
    }

    public function localizedDescription(?string $locale = null): string|Htmlable|null
    {
        return __(sprintf('ingredients.subcategories.%s.description', $this->value), [], $locale);
    }

    public function category(): IngredientCategory
    {
        return match ($this) {
            self::VegetableOils, self::Butters, self::AnimalFats, self::FractionatedModifiedLipids => IngredientCategory::Lipids,
            self::PlantWaxes, self::AnimalWaxes, self::LiquidWaxes, self::MineralWaxes, self::SyntheticWaxes => IngredientCategory::Waxes,
            self::MineralOils, self::PetrolatumOcclusives, self::HydrocarbonEmollients => IngredientCategory::Hydrocarbons,
            self::VolatileSilicones, self::NonvolatileSilicones, self::SiliconeElastomers => IngredientCategory::Silicones,
            self::FattyAcids, self::FattyAlcohols, self::CosmeticEsters => IngredientCategory::FattyDerivatives,
            self::Anionic, self::Amphoteric, self::Nonionic, self::Cationic => IngredientCategory::Surfactants,
            self::OilInWater, self::WaterInOil, self::CoEmulsifiers, self::Solubilizers => IngredientCategory::Emulsifiers,
            self::GlycerinGlycols, self::SugarAlcohols, self::OtherHumectants => IngredientCategory::HumectantsPolyols,
            self::Water, self::Alcohols, self::OrganicSolvents, self::OtherCarriers => IngredientCategory::WaterSolventsCarriers,
            self::Gums, self::CelluloseDerivatives, self::SyntheticRheologyModifiers, self::MineralThickeners => IngredientCategory::RheologyModifiers,
            self::ConditioningPolymers, self::FilmFormingPolymers, self::HairFixativesResins, self::OtherFunctionalPolymers => IngredientCategory::FunctionalPolymers,
            self::Clays, self::Salts, self::StarchesAbsorbentPowders, self::FunctionalMineralPowders => IngredientCategory::MineralsSaltsPowders,
            self::Vitamins, self::ExfoliatingAcids, self::ProteinsPeptidesAminoAcids, self::UvFilters, self::OtherActives => IngredientCategory::Actives,
            self::Hydrosols, self::AqueousGlycerinatedExtracts, self::OilMacerates, self::DryExtracts, self::PlantPowders => IngredientCategory::BotanicalsExtracts,
            self::EssentialOils, self::AbsolutesResinoids, self::Co2Extracts, self::AromaCompounds, self::FragranceBlends => IngredientCategory::AromaticMaterials,
            self::MineralPigments, self::Micas, self::DyesLakes, self::BotanicalColourants => IngredientCategory::Colourants,
            self::Preservatives, self::Antioxidants, self::Chelators => IngredientCategory::PreservationStability,
            self::Acids, self::Bases, self::BufferSystems => IngredientCategory::PhAdjustersBuffers,
            self::SodiumHydroxide, self::PotassiumHydroxide, self::OtherSoapAlkalis => IngredientCategory::SoapmakingAlkalis,
            self::NaturalParticles, self::MineralAbrasives, self::SyntheticParticles => IngredientCategory::ExfoliantsAbrasives,
            self::ReadyMadeBases, self::MeltAndPourSoapBases, self::FunctionalBlends, self::ProprietaryPremixes => IngredientCategory::BasesBlendsPremixes,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function forCategory(IngredientCategory|string|null $category): array
    {
        $category = $category instanceof IngredientCategory
            ? $category
            : (is_string($category) ? IngredientCategory::tryFrom($category) : null);

        if (! $category instanceof IngredientCategory || $category === IngredientCategory::Other) {
            return [];
        }

        return array_values(array_filter(
            self::cases(),
            fn (self $subcategory): bool => $subcategory->category() === $category,
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function optionsFor(IngredientCategory|string|null $category): array
    {
        return collect(self::forCategory($category))
            ->mapWithKeys(fn (self $subcategory): array => [$subcategory->value => (string) $subcategory->getLabel()])
            ->all();
    }
}
