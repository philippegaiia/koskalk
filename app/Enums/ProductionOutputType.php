<?php

namespace App\Enums;

enum ProductionOutputType: string
{
    case FinishedProduct = 'finished_product';
    case ManufacturedIngredient = 'manufactured_ingredient';
}
