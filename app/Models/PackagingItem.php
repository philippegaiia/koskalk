<?php

namespace App\Models;

use App\Casts\OriginalFilename;
use App\Enums\MediaAssetUsageRole;
use App\Enums\PackagingCategory;
use App\Models\Concerns\HasMediaAssetUsages;
use App\Models\Concerns\HasPublicId;
use App\Services\MediaStorage;
use Database\Factories\PackagingItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable([
    'public_id',
    'workspace_id',
    'created_by_user_id',
    'name',
    'material_code',
    'category',
    'notes',
    'is_active',
    'featured_image_path',
    'featured_image_original_name',
])]
/**
 * Stores reusable packaging items that can be pulled into a formula costing.
 *
 * Each workspace keeps one shared catalogue of boxes, labels, jars, and other components.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by_user_id
 * @property string $name
 * @property string|null $material_code
 * @property PackagingCategory $category
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $createdBy
 * @property-read Collection<int, RecipeVersionCostingPackagingItem> $costingItems
 * @property-read Collection<int, RecipeVersionPackagingItem> $recipeVersionPackagingItems
 */
class PackagingItem extends Model
{
    /** @use HasFactory<PackagingItemFactory> */
    use HasFactory;

    use HasMediaAssetUsages;
    use HasPublicId;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** All costing rows that reference this catalog item. */
    public function costingItems(): HasMany
    {
        return $this->hasMany(RecipeVersionCostingPackagingItem::class);
    }

    /** Recipe-version packaging plan rows that reference this catalog item. */
    public function recipeVersionPackagingItems(): HasMany
    {
        return $this->hasMany(RecipeVersionPackagingItem::class);
    }

    public function currentPrice(): HasOne
    {
        return $this->hasOne(CurrentMaterialPrice::class);
    }

    public function materialSetting(): HasOne
    {
        return $this->hasOne(WorkspaceMaterialSetting::class);
    }

    protected function casts(): array
    {
        return [
            'category' => PackagingCategory::class,
            'is_active' => 'boolean',
            'featured_image_original_name' => OriginalFilename::class,
        ];
    }

    protected function unitCost(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->currentPrice?->price_per_canonical_unit);
    }

    protected function currency(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->currentPrice?->currency);
    }

    public function featuredImageUrl(): ?string
    {
        $mediaAsset = $this->mediaAssetForRole(MediaAssetUsageRole::PackagingMain);

        if ($mediaAsset instanceof MediaAsset) {
            return route('media.show', [$mediaAsset, 'catalog']);
        }

        return MediaStorage::packagingItemUrl($this, $this->featured_image_path);
    }

    public function iconImageUrl(): ?string
    {
        $mediaAsset = $this->mediaAssetForRole(MediaAssetUsageRole::PackagingMain);

        if ($mediaAsset instanceof MediaAsset) {
            return route('media.show', [$mediaAsset, 'icon']);
        }

        return $this->featuredImageUrl();
    }
}
