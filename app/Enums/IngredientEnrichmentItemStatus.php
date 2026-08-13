<?php

namespace App\Enums;

enum IngredientEnrichmentItemStatus: string
{
    case Pending = 'pending';
    case Researching = 'researching';
    case Ready = 'ready';
    case Warning = 'warning';
    case Failed = 'failed';
    case Approved = 'approved';
    case Applying = 'applying';
    case Stale = 'stale';
    case Applied = 'applied';
    case Unchanged = 'unchanged';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return (string) __("ingredient_enrichment_admin.status.item.{$this->value}");
    }

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Pending, self::Researching, self::Applying], true);
    }
}
