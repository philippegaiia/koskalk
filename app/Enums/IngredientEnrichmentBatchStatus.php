<?php

namespace App\Enums;

enum IngredientEnrichmentBatchStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case ReadyForReview = 'ready_for_review';
    case PartiallyFailed = 'partially_failed';
    case Applied = 'applied';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return (string) __("ingredient_enrichment_admin.status.batch.{$this->value}");
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::ReadyForReview, self::PartiallyFailed, self::Applied, self::Cancelled], true);
    }
}
