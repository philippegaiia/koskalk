<?php

namespace App\Enums;

/**
 * The drawing shown for a Product Type wherever a Product has no image of its
 * own: the Product card thumbnail and the Product Type pickers.
 *
 * Product Types are admin-managed rows, so the drawing is resolved from the
 * type slug first, then its category, then its area. A new type added in the
 * admin therefore still gets a sensible drawing before it is mapped here. An
 * uploaded Product Type fallback image always takes precedence over these.
 */
enum ProductTypeIllustration: string
{
    case AerosolCan = 'aerosol_can';
    case BabyPowder = 'baby_powder';
    case BalmTin = 'balm_tin';
    case BarSoap = 'bar_soap';
    case BathBomb = 'bath_bomb';
    case Candle = 'candle';
    case Comb = 'comb';
    case Compact = 'compact';
    case ConditionerBottle = 'conditioner_bottle';
    case CottonPads = 'cotton_pads';
    case CreamJar = 'cream_jar';
    case DeodorantStick = 'deodorant_stick';
    case DishSoap = 'dish_soap';
    case DishwasherTablet = 'dishwasher_tablet';
    case DropperBottle = 'dropper_bottle';
    case DyeBowl = 'dye_bowl';
    case Footprint = 'footprint';
    case House = 'house';
    case Incense = 'incense';
    case LaundryBasin = 'laundry_basin';
    case LinenSpray = 'linen_spray';
    case Lipstick = 'lipstick';
    case MaskBowl = 'mask_bowl';
    case MassageCandle = 'massage_candle';
    case MassageOil = 'massage_oil';
    case MistBottle = 'mist_bottle';
    case NailPolish = 'nail_polish';
    case PerfumeBottle = 'perfume_bottle';
    case PillowSpray = 'pillow_spray';
    case PumpBottle = 'pump_bottle';
    case ReedDiffuser = 'reed_diffuser';
    case ScentBeads = 'scent_beads';
    case ShampooBottle = 'shampoo_bottle';
    case ShavingBrush = 'shaving_brush';
    case SoftenerJug = 'softener_jug';
    case Sparkle = 'sparkle';
    case SplashBottle = 'splash_bottle';
    case SqueezeTube = 'squeeze_tube';
    case StainedShirt = 'stained_shirt';
    case SunCare = 'sun_care';
    case Toilet = 'toilet';
    case Toothbrush = 'toothbrush';
    case TriggerSpray = 'trigger_spray';
    case WashingMachine = 'washing_machine';

    public static function resolve(?string $productTypeSlug, ?string $productCategorySlug = null, ?string $productAreaSlug = null): self
    {
        return self::forProductType($productTypeSlug)
            ?? self::forProductCategory($productCategorySlug)
            ?? self::forProductArea($productAreaSlug)
            ?? self::Sparkle;
    }

    public function url(): string
    {
        return asset('images/app/product-types/'.$this->value.'.svg');
    }

    private static function forProductType(?string $slug): ?self
    {
        return match ($slug) {
            'bar-soap-cleansing-bar' => self::BarSoap,
            'liquid-soap-body-wash' => self::PumpBottle,
            'face-cleanser', 'cleansing-non-saponified' => self::SqueezeTube,
            'bath-salts-soaks-bombs', 'bath-salts-soaks' => self::BathBomb,
            'shaving-soap-cream' => self::ShavingBrush,
            'body-cream-lotion-oil', 'cream-lotion' => self::CreamJar,
            'face-cream-serum-toner', 'oil-blend-serum' => self::DropperBottle,
            'hand-nail-care' => self::NailPolish,
            'foot-cream-powder' => self::Footprint,
            'face-mask', 'mask' => self::MaskBowl,
            'baby-cream-oil-powder' => self::BabyPowder,
            'massage-body-oil' => self::MassageOil,
            'skin-contact-massage-candle' => self::MassageCandle,
            'shampoo', 'hair-care' => self::ShampooBottle,
            'rinse-off-conditioner' => self::ConditionerBottle,
            'leave-in-styling-hair-oil' => self::Comb,
            'rinse-off-hair-chemical-treatment' => self::DyeBowl,
            'lip-product' => self::Lipstick,
            'toothpaste-mouthwash' => self::Toothbrush,
            'deodorant-antiperspirant', 'deodorant' => self::DeodorantStick,
            'body-mist-spray' => self::MistBottle,
            'fine-fragrance-solid-perfume' => self::PerfumeBottle,
            'aftershave-splash' => self::SplashBottle,
            'aftershave-cream-balm', 'balm-salve' => self::BalmTin,
            'face-eye-makeup' => self::Compact,
            'makeup-remover' => self::CottonPads,
            'body-sun-self-tan-care', 'face-sun-self-tan-care' => self::SunCare,
            'other-cosmetics', 'other' => self::Sparkle,
            'candle-wax-melt' => self::Candle,
            'reed-diffuser-refill' => self::ReedDiffuser,
            'room-air-freshener-spray' => self::AerosolCan,
            'pillow-spray' => self::PillowSpray,
            'fabric-linen-spray' => self::LinenSpray,
            'incense-passive-air-fragrance' => self::Incense,
            'hand-dishwashing-product' => self::DishSoap,
            'automatic-dishwasher-product' => self::DishwasherTablet,
            'hand-wash-laundry-product' => self::LaundryBasin,
            'machine-laundry-detergent' => self::WashingMachine,
            'laundry-pre-treatment' => self::StainedShirt,
            'fabric-softener' => self::SoftenerJug,
            'dryer-sheet-scent-beads' => self::ScentBeads,
            'hard-surface-cleaner' => self::TriggerSpray,
            'toilet-gel-rim-block' => self::Toilet,
            'other-home-product' => self::House,
            default => null,
        };
    }

    /**
     * Category slugs are only unique within an area ("other" exists in both),
     * so an unmapped category defers to the area.
     */
    private static function forProductCategory(?string $slug): ?self
    {
        return match ($slug) {
            'body-cleansing' => self::BarSoap,
            'skin-care' => self::CreamJar,
            'hair-care' => self::ShampooBottle,
            'lips-oral-care' => self::Lipstick,
            'deodorant-fragrance' => self::PerfumeBottle,
            'grooming' => self::ShavingBrush,
            'makeup' => self::Compact,
            'sun-tan-care' => self::SunCare,
            'home-fragrance' => self::Candle,
            'dish-care' => self::DishSoap,
            'laundry' => self::WashingMachine,
            'surface-toilet-care' => self::TriggerSpray,
            default => null,
        };
    }

    private static function forProductArea(?string $slug): ?self
    {
        return match ($slug) {
            'home-household' => self::House,
            default => null,
        };
    }
}
