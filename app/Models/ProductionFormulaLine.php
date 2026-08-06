<?php

namespace App\Models;

use App\ProductionFormulaComponent;
use Database\Factories\ProductionFormulaLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'production_run_id',
    'ingredient_id',
    'recipe_item_id',
    'component',
    'subject_name_snapshot',
    'phase_key_snapshot',
    'phase_name_snapshot',
    'basis_percentage_snapshot',
    'planned_mass_grams',
    'note_snapshot',
    'sort_order',
])]
class ProductionFormulaLine extends Model
{
    /** @use HasFactory<ProductionFormulaLineFactory> */
    use HasFactory;

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withoutGlobalScopes();
    }

    public function recipeItem(): BelongsTo
    {
        return $this->belongsTo(RecipeItem::class)->withoutGlobalScopes();
    }

    protected function casts(): array
    {
        return [
            'component' => ProductionFormulaComponent::class,
            'basis_percentage_snapshot' => 'decimal:9',
            'planned_mass_grams' => 'decimal:9',
            'sort_order' => 'integer',
        ];
    }
}
