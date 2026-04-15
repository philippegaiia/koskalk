<?php

namespace App\Models;

use App\Models\Concerns\HasTenantOwnership;
use App\Models\Scopes\OwnedByCurrentTenantScope;
use App\OwnerType;
use App\Services\MediaStorage;
use App\Services\RecipeRichContentAttachmentProvider;
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
    'is_private',
    'created_by',
    'visibility',
    'name',
    'description',
    'manufacturing_instructions',
    'featured_image_path',
    'slug',
    'archived_at',
])]
class Recipe extends Model implements HasRichContent
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    use HasTenantOwnership;
    use InteractsWithRichContent;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected static array $pendingRichContentStateByRecipeId = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByCurrentTenantScope);
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

    public function currentSavedVersion(): HasOne
    {
        return $this->hasOne(RecipeVersion::class)
            ->ofMany(['version_number' => 'max'], function ($query): void {
                $query->where('is_draft', false);
            });
    }

    public function currentDraftVersion(): HasOne
    {
        return $this->hasOne(RecipeVersion::class)->where('is_draft', true);
    }

    public function featuredImageUrl(): ?string
    {
        return MediaStorage::publicUrl($this->featured_image_path);
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
            ->flatMap(fn (string $name): Collection => static::extractRichContentAttachmentPaths($this->getAttribute($name)))
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

                return static::extractRichContentAttachmentPaths($content);
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
            ->fileAttachmentsDisk(MediaStorage::publicDisk())
            ->fileAttachmentsVisibility(MediaStorage::publicVisibility())
            ->fileAttachmentProvider(app(RecipeRichContentAttachmentProvider::class));

        $this->registerRichContent('manufacturing_instructions')
            ->fileAttachmentsDisk(MediaStorage::publicDisk())
            ->fileAttachmentsVisibility(MediaStorage::publicVisibility())
            ->fileAttachmentProvider(app(RecipeRichContentAttachmentProvider::class));
    }

    protected function casts(): array
    {
        return [
            'owner_type' => OwnerType::class,
            'visibility' => Visibility::class,
            'is_private' => 'bool',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private static function extractRichContentAttachmentPaths(mixed $content): Collection
    {
        if (! is_string($content) || $content === '') {
            return collect();
        }

        preg_match_all('/data-id="([^"]+)"/', $content, $dataIdMatches);
        preg_match_all('/(?:src|href)="([^"]*recipes\/rich-content\/[^"]+)"/', $content, $sourceMatches);

        $sourcePaths = collect($sourceMatches[1] ?? [])
            ->map(function (string $path): string {
                $normalizedPath = parse_url($path, PHP_URL_PATH);

                if (is_string($normalizedPath) && preg_match('~recipes/rich-content/.*$~', $normalizedPath, $matches) === 1) {
                    return $matches[0];
                }

                return $path;
            });

        return collect($dataIdMatches[1] ?? [])
            ->merge($sourcePaths)
            ->filter(fn (mixed $value): bool => is_string($value) && str_contains($value, 'recipes/rich-content/'))
            ->unique()
            ->values();
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
