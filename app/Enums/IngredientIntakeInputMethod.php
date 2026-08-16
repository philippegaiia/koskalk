<?php

namespace App\Enums;

enum IngredientIntakeInputMethod: string
{
    case Paste = 'paste';
    case Csv = 'csv';

    public function label(): string
    {
        return (string) __("ingredient_intake_admin.input_method.{$this->value}");
    }
}
