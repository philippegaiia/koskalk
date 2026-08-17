<?php

namespace App\Enums;

enum NominalContentUnit: string
{
    case Gram = 'g';
    case Kilogram = 'kg';
    case Millilitre = 'ml';
    case Litre = 'l';
    case Ounce = 'oz';
    case Pound = 'lb';
    case FluidOunce = 'fl_oz';
}
