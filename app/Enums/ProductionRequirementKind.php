<?php

namespace App\Enums;

enum ProductionRequirementKind: string
{
    case Ingredient = 'ingredient';
    case Packaging = 'packaging';
}
