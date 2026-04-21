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
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'source_file',
    'source_key',
    'source_code_prefix',
    'category',
    'display_name',
    'inci_name',
    'supplier_name',
    'supplier_reference',
    'soap_inci_naoh_name',
    'soap_inci_koh_name',
    'cas_number',
    'ec_number',
    'is_organic',
    'unit',
    'owner_type',
    'owner_id',
    'workspace_id',
    'visibility',
    'is_potentially_saponifiable',
    'requires_admin_review',
    'is_active',
    'is_manufactured',
    'source_data',
    'info_markdown',
    'featured_image_path',
    'icon_image_path',
])]
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    use HasTenantOwnership {
        isAccessibleBy as tenantIsAccessibleBy;
    }

    public function sapProfile(): HasOne
    {
        return $this->hasOne(IngredientSapProfile::class);
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

    public function fattyAcidEntries(): HasMany
    {
        return $this->hasMany(IngredientFattyAcid::class);
    }

    public function allergenEntries(): HasMany
    {
        return $this->hasMany(IngredientAllergenEntry::class);
    }

    public function functions(): BelongsToMany
    {
        return $this->belongsToMany(IngredientFunction::class, 'ingredient_function_ingredient')
            ->withTimestamps()
            ->orderBy('ingredient_functions.sort_order')
            ->orderBy('ingredient_functions.name');
    }

    public function ifraCertificates(): HasMany
    {
        return $this->hasMany(IfraCertificate::class);
    }

    public function userPrices(): HasMany
    {
        return $this->hasMany(UserIngredientPrice::class);
    }

    protected function userPricePerKg(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->userPrices->first()?->price_per_kg,
            set: fn () => [],
        );
    }

    public function costingItems(): HasMany
    {
        return $this->hasMany(RecipeVersionCostingItem::class);
    }

    public function featuredImageUrl(): ?string
    {
        return MediaStorage::publicUrl($this->featured_image_path);
    }

    public function iconImageUrl(): ?string
    {
        return MediaStorage::publicUrl($this->icon_image_path);
    }

    public function pickerImageUrl(): ?string
    {
        return $this->iconImageUrl() ?? $this->featuredImageUrl();
    }

    /**
     * @return array<string, float>
     */
    public function normalizedFattyAcidProfile(): array
    {
        $fattyAcidEntries = $this->relationLoaded('fattyAcidEntries')
            ? $this->fattyAcidEntries->loadMissing('fattyAcid:id,key,display_order')
            : $this->fattyAcidEntries()
                ->with('fattyAcid:id,key,display_order')
                ->get();

        $profile = $fattyAcidEntries
            ->sortBy(fn (IngredientFattyAcid $entry): int => $entry->fattyAcid?->display_order ?? PHP_INT_MAX)
            ->mapWithKeys(function (IngredientFattyAcid $entry): array {
                $key = $entry->fattyAcid?->key;

                return $key === null ? [] : [$key => round((float) $entry->percentage, 5)];
            })
            ->all();

        if ($profile !== []) {
            return $profile;
        }

        return [];
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
            'is_organic' => 'bool',
            'is_potentially_saponifiable' => 'bool',
            'requires_admin_review' => 'bool',
            'is_active' => 'bool',
            'is_manufactured' => 'bool',
            'source_data' => 'array',
        ];
    }
}
