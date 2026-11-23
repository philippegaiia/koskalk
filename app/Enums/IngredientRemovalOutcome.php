<?php

namespace App\Enums;

enum IngredientRemovalOutcome: string
{
    case Deleted = 'deleted';
    case Deactivated = 'deactivated';
}
