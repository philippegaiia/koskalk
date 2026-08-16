<?php

namespace App\Enums;

enum IngredientIntakeBatchStatus: string
{
    case Draft = 'draft';
    case Researching = 'researching';
    case ReadyForReview = 'ready_for_review';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return (string) __("ingredient_intake_admin.status.batch.{$this->value}");
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::ReadyForReview, self::Completed, self::Cancelled], true);
    }
}
