<?php

namespace App\Enums;

/**
 * Where a platform ingredient stands in the administrator's catalogue review.
 * Every ingredient sits in exactly one step: a signed-off ingredient is Reviewed,
 * otherwise it is at the first unfinished step.
 */
enum IngredientCatalogReviewStep: string
{
    case MissingGuidance = 'missing_guidance';
    case TranslationsIncomplete = 'translations_incomplete';
    case AwaitingReview = 'awaiting_review';
    case Reviewed = 'reviewed';

    public function label(): string
    {
        return (string) __("ingredient_admin.catalog_review.steps.{$this->value}");
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $step): array => [$step->value => $step->label()])
            ->all();
    }
}
