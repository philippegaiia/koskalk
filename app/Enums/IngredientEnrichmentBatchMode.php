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

    /**
     * @return list<IngredientEnrichmentResearchStage>
     */
    public function guidanceStages(): array
    {
        return match ($this) {
            self::GuidanceRefresh => [
                IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
                IngredientEnrichmentResearchStage::AiGuidanceLocalization,
                IngredientEnrichmentResearchStage::Validation,
            ],
            self::GuidanceLocalization => [
                IngredientEnrichmentResearchStage::AiGuidanceLocalization,
                IngredientEnrichmentResearchStage::Validation,
            ],
            default => [],
        };
    }

    public function isLocalizationOnly(): bool
    {
        return $this === self::GuidanceLocalization;
    }
}
