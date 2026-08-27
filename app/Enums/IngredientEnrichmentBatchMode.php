<?php

namespace App\Enums;

enum IngredientEnrichmentBatchMode: string
{
    case FillMissing = 'fill_missing';
    case Intake = 'intake';
    case GuidanceRefresh = 'guidance_refresh';
    case GuidanceLocalization = 'guidance_localization';

    public function isGuidance(): bool
    {
        return in_array($this, [self::GuidanceRefresh, self::GuidanceLocalization], true);
    }

    public function isLocalizationOnly(): bool
    {
        return $this === self::GuidanceLocalization;
    }
}
