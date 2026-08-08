<?php

namespace App\Enums;

enum ProductionFormulaComponent: string
{
    case Ingredient = 'ingredient';
    case Naoh = 'naoh';
    case Koh = 'koh';
    case Water = 'water';
}
