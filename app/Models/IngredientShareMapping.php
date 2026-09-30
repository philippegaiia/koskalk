<?php

namespace App\Models;

use Database\Factories\IngredientShareMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'lineage_key', 'fingerprint_version', 'incoming_fingerprint', 'ingredient_id', 'local_fingerprint', 'resolution'])]
class IngredientShareMapping extends Model
{
    /** @use HasFactory<IngredientShareMappingFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withoutGlobalScopes();
    }

    /** Internal mapping decisions have no browser-facing representation. */
    public function toArray(): array
    {
        return [];
    }

    protected function casts(): array
    {
        return ['fingerprint_version' => 'integer'];
    }
}
