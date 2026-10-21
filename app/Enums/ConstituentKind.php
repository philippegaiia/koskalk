<?php

namespace App\Enums;

enum ConstituentKind: string
{
    case Allergen = 'allergen';
    case Impurity = 'impurity';
    case RestrictedSubstance = 'restricted_substance';

    /** Fixed unit so values compare without conversion. */
    public function unit(): string
    {
        return $this === self::Impurity ? 'ppm' : 'percent';
    }

    public function usesAllergenCatalogue(): bool
    {
        return $this === self::Allergen;
    }
}
