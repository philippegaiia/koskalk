<?php

namespace App\Enums;

enum IngredientSourceTier: string
{
    case Official = 'official';
    case StructuredMirror = 'structured_mirror';
    case Editorial = 'editorial';
    case ApprovedSecondary = 'approved_secondary';
    case ReviewerSupplied = 'reviewer_supplied';

    public function label(): string
    {
        return __('ingredient_enrichment.source_tiers.'.$this->value);
    }
}
