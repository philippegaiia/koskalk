<?php

namespace App\Models;

use App\Models\Concerns\HasTenantOwnership;
use App\Models\Scopes\OwnedByCurrentTenantScope;
use App\OwnerType;
use App\Services\MediaStorage;
use App\Visibility;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'product_family_id',
    'owner_type',
    'owner_id',
    'workspace_id',
    'visibility',
    'name',
    'description',
    'featured_image_path',
    'slug',
    'archived_at',
])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    use HasTenantOwnership;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByCurrentTenantScope);
    }

    public function productFamily(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class);
    }

    public function publishedVersions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class)
            ->where('is_draft', false)
            ->orderByDesc('version_number');
    }

    public function currentDraftVersion(): HasOne
    {
        return $this->hasOne(RecipeVersion::class)->where('is_draft', true);
    }

    public function featuredImageUrl(): ?string
    {
        return MediaStorage::publicUrl($this->featured_image_path);
    }

    protected function casts(): array
    {
        return [
            'owner_type' => OwnerType::class,
            'visibility' => Visibility::class,
            'archived_at' => 'datetime',
        ];
    }
}
