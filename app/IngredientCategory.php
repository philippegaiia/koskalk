<?php

namespace App;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

enum IngredientCategory: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case CarrierOil = 'carrier_oil';
    case EssentialOil = 'essential_oil';
    case FragranceOil = 'fragrance_oil';
    case BotanicalExtract = 'botanical_extract';
    case Co2Extract = 'co2_extract';
    case Colorant = 'colorant';
    case Preservative = 'preservative';
    case Additive = 'additive';
    case Alkali = 'alkali';
    case Liquid = 'liquid';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::CarrierOil => 'Carrier Oil',
            self::EssentialOil => 'Essential Oil',
            self::FragranceOil => 'Fragrance Oil',
            self::BotanicalExtract => 'Botanical Extract',
            self::Co2Extract => 'CO2 Extract',
            self::Colorant => 'Colorant',
            self::Preservative => 'Preservative',
            self::Additive => 'Additive',
            self::Alkali => 'Alkali',
            self::Liquid => 'Liquid',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::CarrierOil => 'success',
            self::EssentialOil => 'warning',
            self::FragranceOil => 'danger',
            self::BotanicalExtract => 'emerald',
            self::Co2Extract => 'teal',
            self::Colorant => 'info',
            self::Preservative => 'primary',
            self::Additive => 'gray',
            self::Alkali => 'danger',
            self::Liquid => 'blue',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::CarrierOil => Heroicon::CubeTransparent,
            self::EssentialOil => Heroicon::Sparkles,
            self::FragranceOil => Heroicon::Fire,
            self::BotanicalExtract => Heroicon::Sun,
            self::Co2Extract => Heroicon::Beaker,
            self::Colorant => Heroicon::Swatch,
            self::Preservative => Heroicon::ShieldCheck,
            self::Additive => Heroicon::ArchiveBox,
            self::Alkali => Heroicon::Beaker,
            self::Liquid => Heroicon::Cloud,
        };
    }

    public function getDescription(): string|Htmlable|null
    {
        return match ($this) {
            self::CarrierOil => 'Carrier oils and butters used in the initial saponification calculation.',
            self::EssentialOil => 'Essential oils that may contribute allergen declarations.',
            self::FragranceOil => 'User-added fragrance oils that are not seeded in the platform catalog.',
            self::BotanicalExtract => 'Botanical extracts that may require allergen and IFRA context when aromatic.',
            self::Co2Extract => 'CO2 extracts and similar aromatic specialty extracts that require compliance data.',
            self::Colorant => 'Clays, micas, pigments, and other color additives.',
            self::Preservative => 'Preservatives used in non-soap cosmetic systems.',
            self::Additive => 'General additives such as salts, sugars, botanicals, and functional extras.',
            self::Alkali => 'Alkalis such as sodium hydroxide or potassium hydroxide.',
            self::Liquid => 'Water and other liquid carriers used in the formula.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $category): array => [$category->value => (string) $category->getLabel()])
            ->all();
    }

    /**
     * @return array<int, self>
     */
    public static function aromaticCases(): array
    {
        return [
            self::EssentialOil,
            self::FragranceOil,
            self::BotanicalExtract,
            self::Co2Extract,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function aromaticValues(): array
    {
        return array_map(
            fn (self $category): string => $category->value,
            self::aromaticCases(),
        );
    }
}
