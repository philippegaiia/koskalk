<?php

namespace App\Models;

use App\Casts\OriginalFilename;
use App\MediaAssetUsageRole;
use App\Models\Concerns\HasMediaAssetUsages;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasTenantOwnership;
use App\Models\Scopes\OwnedByCurrentTenantScope;
use App\OwnerType;
use App\Services\MediaStorage;
use App\Services\RecipeRichContentAttachmentProvider;
use App\Support\RichContentAttachmentPaths;
use App\Visibility;
use Database\Factories\RecipeFactory;
use Filament\Forms\Components\RichEditor\Models\Concerns\InteractsWithRichContent;
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

#[Fillable([
    'product_family_id',
    'product_type_id',
    'owner_type',
    'owner_id',
    'workspace_id',
    'brand_id',
    'created_by',
    'visibility',
    'name',
    'description',
    'manufacturing_instructions',
    'featured_image_path',
    'featured_image_original_name',
    'slug',
    'archived_at',
    'locked_at',
    'locked_by',
])]
class Recipe extends Model implements HasRichContent
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    use HasMediaAssetUsages;
    use HasPublicId;
    use HasTenantOwnership;
    use InteractsWithRichContent;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected static array $pendingRichContentStateByRecipeId = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByCurrentTenantScope);

        static::deleting(function (self $recipe): void {
            $versionIds = RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->pluck('id');

            MediaAssetUsage::query()
                ->where('usable_type', (new RecipeVersion)->getMorphClass())
                ->whereIn('usable_id', $versionIds)
                ->delete();
        });
    }

    public function productFamily(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class);
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ownerUser(): ?User
    {
        if ($this->workspace_id !== null) {
            return $this->workspace()
                ->withoutGlobalScopes()
                ->first()?->owner;
        }

        if ($this->tenantOwnerType() !== OwnerType::User || $this->owner_id === null) {
            return null;
        }

        return User::query()->find((int) $this->owner_id);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class);
    }

    public function publishedVersions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class)
            ->where('is_current', false)
            ->orderByDesc('version_number');
    }

    public function productionBatches(): HasMany
    {
        return $this->hasMany(ProductionBatch::class)
            ->latest('manufacture_date')
            ->latest('id');
    }

    public function productionRuns(): HasMany
    {
        return $this->hasMany(ProductionRun::class);
    }

    public function latestPublishedVersion(): HasOne
    {
        return $this->hasOne(RecipeVersion::class)
            ->ofMany(['version_number' => 'max'], function ($query): void {
                $query->where('is_current', false);
            });
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(RecipeVersion::class)->where('is_current', true);
    }

    public function featuredImageUrl(): ?string
    {
        $mediaAsset = $this->mediaAssetForRole(MediaAssetUsageRole::RecipeFeatured);

        if ($mediaAsset instanceof MediaAsset) {
            return route('media.show', [$mediaAsset, 'catalog']);
        }

        return MediaStorage::recipeUrl($this, $this->featured_image_path);
    }

    public function indexImageUrl(): ?string
    {
        $mediaAsset = $this->mediaAssetForRole(MediaAssetUsageRole::RecipeFeatured);

        if ($mediaAsset instanceof MediaAsset) {
            return route('media.show', [$mediaAsset, 'recipe-index']);
        }

        return $this->featuredImageUrl();
    }

    /**
     * @return Collection<int, string>
     */
    public function richContentAttachmentPaths(?string $attribute = null): Collection
    {
        $attributeNames = $attribute === null
            ? array_keys($this->getRichContentAttributes())
            : [$attribute];

        return collect($attributeNames)
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->flatMap(fn (string $name): Collection => RichContentAttachmentPaths::extract($this->getAttribute($name)))
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    public function otherRichContentAttachmentPaths(string $attribute): Collection
    {
        return collect(array_keys($this->getRichContentAttributes()))
            ->reject(fn (string $name): bool => $name === $attribute)
            ->flatMap(function (string $name): Collection {
                $pendingRichContentState = $this->pendingRichContentState();
                $content = array_key_exists($name, $pendingRichContentState)
                    ? $pendingRichContentState[$name]
                    : $this->getAttribute($name);

                return RichContentAttachmentPaths::extract($content);
            })
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    public function mediaPaths(): Collection
    {
        return collect([$this->featured_image_path])
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->merge($this->richContentAttachmentPaths())
            ->unique()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function setPendingRichContentState(array $state): void
    {
        $recipeId = $this->getKey();

        if (! is_int($recipeId)) {
            return;
        }

        static::$pendingRichContentStateByRecipeId[$recipeId] = collect($state)
            ->only(array_keys($this->getRichContentAttributes()))
            ->all();
    }

    public function clearPendingRichContentState(): void
    {
        $recipeId = $this->getKey();

        if (! is_int($recipeId)) {
            return;
        }

        unset(static::$pendingRichContentStateByRecipeId[$recipeId]);
    }

    public function hasPendingRichContentState(): bool
    {
        return $this->pendingRichContentState() !== [];
    }

    protected function setUpRichContent(): void
    {
        $this->registerRichContent('description')
            ->fileAttachmentsDisk(MediaStorage::recipeDisk())
            ->fileAttachmentsVisibility(MediaStorage::recipeVisibility())
            ->fileAttachmentProvider(app(RecipeRichContentAttachmentProvider::class));

        $this->registerRichContent('manufacturing_instructions')
            ->fileAttachmentsDisk(MediaStorage::recipeDisk())
            ->fileAttachmentsVisibility(MediaStorage::recipeVisibility())
            ->fileAttachmentProvider(app(RecipeRichContentAttachmentProvider::class));
    }

    protected function casts(): array
    {
        return [
            'owner_type' => OwnerType::class,
            'visibility' => Visibility::class,
            'archived_at' => 'datetime',
            'locked_at' => 'datetime',
            'featured_image_original_name' => OriginalFilename::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingRichContentState(): array
    {
        $recipeId = $this->getKey();

        if (! is_int($recipeId)) {
            return [];
        }

        return static::$pendingRichContentStateByRecipeId[$recipeId] ?? [];
    }
}
