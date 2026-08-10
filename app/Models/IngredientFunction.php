<?php

namespace App\Models;

use Database\Factories\IngredientFunctionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'key',
    'name',
    'description',
    'sort_order',
    'is_active',
])]
class IngredientFunction extends Model
{
    /** @use HasFactory<IngredientFunctionFactory> */
    use HasFactory;

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'ingredient_function_ingredient')
            ->withPivot([
                'source',
                'source_reference',
                'source_checked_at',
                'assigned_by_user_id',
            ])
            ->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'int',
            'is_active' => 'bool',
        ];
    }
}
