<?php

namespace App\Enums;

enum IngredientEvidenceConfidence: string
{
    case Verified = 'verified';
    case Supported = 'supported';
    case Conflicting = 'conflicting';
    case Unresolved = 'unresolved';

    public function label(): string
    {
        return __('ingredient_enrichment.confidence.'.$this->value);
    }
}
