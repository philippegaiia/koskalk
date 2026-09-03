<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The user-facing ingredient taxonomy.
 *
 * Legacy database values are converted by the taxonomy migration bridge, so
 * runtime code handles only canonical values.
 */
enum IngredientCategory: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case Lipids = 'lipids';
    case Waxes = 'waxes';
    case Hydrocarbons = 'hydrocarbons';
    case Silicones = 'silicones';
    case FattyDerivatives = 'fatty_derivatives';
    case Surfactants = 'surfactants';
    case Emulsifiers = 'emulsifiers';
    case HumectantsPolyols = 'humectants_polyols';
    case WaterSolventsCarriers = 'water_solvents_carriers';
    case RheologyModifiers = 'rheology_modifiers';
    case FunctionalPolymers = 'functional_polymers';
    case MineralsSaltsPowders = 'minerals_salts_powders';
    case Actives = 'actives';
    case BotanicalsExtracts = 'botanicals_extracts';
    case AromaticMaterials = 'aromatic_materials';
    case Colourants = 'colourants';
    case PreservationStability = 'preservation_stability';
    case PhAdjustersBuffers = 'ph_adjusters_buffers';
    case SoapmakingAlkalis = 'soapmaking_alkalis';
    case ExfoliantsAbrasives = 'exfoliants_abrasives';
    case BasesBlendsPremixes = 'bases_blends_premixes';
    case Other = 'other';

    public function getLabel(): string|Htmlable|null
    {
        return $this->localizedLabel();
    }

    public function localizedLabel(?string $locale = null): string|Htmlable|null
    {
        return __(sprintf('ingredients.categories.%s.label', $this->value), [], $locale);
    }

    public function getDescription(): string|Htmlable|null
    {
        return $this->localizedDescription();
    }

    public function localizedDescription(?string $locale = null): string|Htmlable|null
    {
        return __(sprintf('ingredients.categories.%s.description', $this->value), [], $locale);
    }

    /**
     * Compact label for dense views where the full label would not fit.
     *
     * Rows show the full label in forms, filters, and detail views; tables and
     * chips use this shorter wording. Both read from `ingredients.categories`.
     */
    public function localizedShortLabel(?string $locale = null): string|Htmlable|null
    {
        return __(sprintf('ingredients.categories.%s.short_label', $this->value), [], $locale);
    }

    /**
     * Filament palette name. Changes here repaint Filament components only —
     * the user-shell badge colour comes from `badgeVariant()` and is keyed by
     * category, not by this grouping.
     */
    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Lipids, self::Waxes, self::Hydrocarbons => 'success',
            self::Silicones, self::FattyDerivatives => 'teal',
            self::Surfactants, self::Emulsifiers => 'info',
            self::HumectantsPolyols, self::WaterSolventsCarriers => 'blue',
            self::RheologyModifiers, self::FunctionalPolymers => 'primary',
            self::MineralsSaltsPowders, self::Colourants => 'gray',
            self::Actives, self::BotanicalsExtracts => 'emerald',
            self::AromaticMaterials => 'warning',
            self::PreservationStability, self::PhAdjustersBuffers, self::SoapmakingAlkalis => 'danger',
            self::ExfoliantsAbrasives, self::BasesBlendsPremixes, self::Other => 'gray',
        };
    }

    /**
     * Stylesheet modifier for a compact badge: `sk-badge-<value>`.
     *
     * Every category has its own hue, so the modifier is the canonical value
     * and the two cannot drift apart. Adding a case therefore needs one
     * matching `.sk-badge-<value>` rule; without it the badge renders
     * colourless, which the taxonomy test catches.
     *
     * This is deliberately *not* derived from `getColor()`. That method
     * implements Filament's `HasColor` and answers a different question: it
     * lumps categories into broad semantic buckets (`danger` for everything
     * corrosive, `gray` for everything inert) for Filament components that
     * ship their own palette. A per-category scale cannot be derived from a
     * grouping that collapses nine categories into one colour, so the badge
     * palette is defined in the stylesheet and keyed by value instead.
     */
    public function badgeVariant(): string
    {
        return $this->value;
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Lipids, self::Waxes, self::Hydrocarbons, self::FattyDerivatives => Heroicon::CubeTransparent,
            self::Silicones, self::Emulsifiers, self::RheologyModifiers, self::FunctionalPolymers => Heroicon::Beaker,
            self::Surfactants, self::PreservationStability => Heroicon::ShieldCheck,
            self::HumectantsPolyols, self::WaterSolventsCarriers => Heroicon::Cloud,
            self::MineralsSaltsPowders, self::Colourants, self::ExfoliantsAbrasives => Heroicon::Swatch,
            self::Actives, self::BotanicalsExtracts => Heroicon::Sun,
            self::AromaticMaterials => Heroicon::Sparkles,
            self::PhAdjustersBuffers, self::SoapmakingAlkalis => Heroicon::Beaker,
            self::BasesBlendsPremixes, self::Other => Heroicon::ArchiveBox,
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
     * Soapmaking alkalis are Koskalk-curated canonical materials; workspaces
     * may not author, reclassify, or duplicate them.
     */
    public function isWorkspaceAuthorable(): bool
    {
        return $this !== self::SoapmakingAlkalis;
    }

    /**
     * @return array<string, string>
     */
    public static function workspaceAuthorableOptions(): array
    {
        return collect(self::cases())
            ->filter(fn (self $category): bool => $category->isWorkspaceAuthorable())
            ->mapWithKeys(fn (self $category): array => [
                $category->value => (string) $category->getLabel(),
            ])
            ->all();
    }

    /**
     * @return array<int, IngredientSubcategory>
     */
    public function subcategories(): array
    {
        return IngredientSubcategory::forCategory($this);
    }
}
