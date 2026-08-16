<?php

namespace App\Enums;

enum IngredientDuplicateResolution: string
{
    case ExistingIngredient = 'existing_ingredient';
    case DistinctMaterial = 'distinct_material';

    public function label(): string
    {
        return (string) __("ingredient_intake_admin.duplicate_resolution.{$this->value}");
    }
}
