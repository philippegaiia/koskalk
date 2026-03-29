<?php

namespace App\Models;

use App\IngredientCategory;
use App\Models\Concerns\HasTenantOwnership;
use App\OwnerType;
use App\Services\MediaStorage;
use App\Visibility;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'source_file',
    'source_key',
    'source_code_prefix',
    'category',
    'owner_type',
    'owner_id',
    'workspace_id',
    'visibility',
    'is_potentially_saponifiable',
    'requires_admin_review',
    'is_active',
    'source_data',
    'info_markdown',
    'featured_image_path',
])]
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    use HasTenantOwnership {
        isAccessibleBy as tenantIsAccessibleBy;
    }

    public function versions(): HasMany
    {
        return $this->hasMany(IngredientVersion::class);
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(IngredientVersion::class)->where('is_current', true);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(IngredientComponent::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function featuredImageUrl(): ?string
    {
        return MediaStorage::publicUrl($this->featured_image_path);
    }

    public function isAvailableForInitialSoapCalculation(): bool
    {
        return $this->canDriveSoapSaponification();
    }

    public function canDriveSoapSaponification(): bool
    {
        return $this->category === IngredientCategory::CarrierOil
            && $this->is_potentially_saponifiable;
    }

    public function isPublicCatalog(): bool
    {
        return $this->visibility === Visibility::Public || $this->owner_type === null;
    }

    public function requiresAromaticCompliance(): bool
    {
        return $this->category !== null
            && in_array($this->category->value, IngredientCategory::aromaticValues(), true);
    }

    /**
     * @return array<int, string>
     */
    public function availableWorkbenchPhases(): array
    {
        if ($this->requiresAromaticCompliance()) {
            return ['fragrance'];
        }

        if ($this->category === IngredientCategory::CarrierOil) {
            return $this->canDriveSoapSaponification()
                ? ['saponified_oils', 'additives']
                : ['additives'];
        }

        if (in_array($this->category, [
            IngredientCategory::BotanicalExtract,
            IngredientCategory::Clay,
            IngredientCategory::Glycol,
            IngredientCategory::Colorant,
            IngredientCategory::Preservative,
            IngredientCategory::Additive,
        ], true)) {
            return ['additives'];
        }

        return [];
    }

    public function preferredWorkbenchPhase(): ?string
    {
        return $this->availableWorkbenchPhases()[0] ?? null;
    }

    public function isAccessibleBy(User $user): bool
    {
        return $this->isPublicCatalog() || $this->tenantIsAccessibleBy($user);
    }

    public function scopeAccessibleTo(Builder $query, ?User $user): Builder
    {
        return $query->where(function (Builder $accessibleQuery) use ($user): void {
            $accessibleQuery->where('visibility', Visibility::Public->value)
                ->orWhereNull('owner_type');

            if (! $user instanceof User) {
                return;
            }

            $accessibleQuery->orWhere(function (Builder $ownedQuery) use ($user): void {
                $ownedQuery
                    ->where('owner_type', OwnerType::User->value)
                    ->where('owner_id', $user->id);
            });

            $workspaceIds = $user->accessibleWorkspaceIds();

            if ($workspaceIds !== []) {
                $accessibleQuery->orWhere(function (Builder $workspaceQuery) use ($workspaceIds): void {
                    $workspaceQuery
                        ->where('owner_type', OwnerType::Workspace->value)
                        ->whereIn('owner_id', $workspaceIds);
                })->orWhereIn('workspace_id', $workspaceIds);
            }
        });
    }

    public function scopeOwnedByUser(Builder $query, User $user): Builder
    {
        return $query
            ->where('owner_type', OwnerType::User->value)
            ->where('owner_id', $user->id);
    }

    protected function casts(): array
    {
        return [
            'category' => IngredientCategory::class,
            'owner_type' => OwnerType::class,
            'visibility' => Visibility::class,
            'is_potentially_saponifiable' => 'bool',
            'requires_admin_review' => 'bool',
            'is_active' => 'bool',
            'source_data' => 'array',
        ];
    }
}
