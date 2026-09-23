<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'period', 'next_serial'])]
class IngredientLotNumberCounter extends Model
{
    protected function casts(): array
    {
        return ['next_serial' => 'integer'];
    }
}
