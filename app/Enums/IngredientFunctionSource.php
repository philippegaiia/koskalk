<?php

namespace App\Enums;

enum IngredientFunctionSource: string
{
    case CosIng = 'cosing';
    case Manual = 'manual';
    case Inherited = 'inherited';
}
