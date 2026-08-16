<?php

namespace App\Enums;

enum IngredientResearchFamily: string
{
    case Colourants = 'colourants';
    case Lipids = 'lipids';
    case AromaticMaterials = 'aromatic_materials';
    case Waxes = 'waxes';
    case Other = 'other';

    public function label(): string
    {
        return (string) __("ingredient_intake_admin.research_family.{$this->value}");
    }
}
