<?php

namespace App\Enums;

enum IngredientValueProvenance: string
{
    case SourceConfirmed = 'source_confirmed';
    case AiProposed = 'ai_proposed';
    case ReviewerSupplied = 'reviewer_supplied';
    case Unresolved = 'unresolved';

    public function label(): string
    {
        return __('ingredient_enrichment.value_provenance.'.$this->value);
    }
}
