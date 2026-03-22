<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'slug',
    'calculation_basis',
    'is_active',
    'description',
])]
class ProductFamily extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
        ];
    }
}
