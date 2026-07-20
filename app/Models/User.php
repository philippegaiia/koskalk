<?php

namespace App\Models;

use App\OwnerType;
use App\WorkspaceMemberRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Paddle\Billable;

#[Fillable(['name', 'email', 'is_admin', 'locale', 'number_locale', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, Notifiable;

    /**
     * @var array<int, int>|null
     */
    private ?array $cachedAccessibleWorkspaceIds = null;

    /**
     * @var array<int, int>|null
     */
    private ?array $cachedOwnedWorkspaceIds = null;

    private bool $hasResolvedCompany = false;

    private ?Workspace $cachedCompany = null;

    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_user_id');
    }

    public function activeWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'active_workspace_id');
    }

    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function ingredientPrices(): HasMany
    {
        return $this->hasMany(UserIngredientPrice::class);
    }

    public function packagingItems(): HasMany
    {
        return $this->hasMany(UserPackagingItem::class);
    }

    public function recipeVersionCostings(): HasMany
    {
        return $this->hasMany(RecipeVersionCosting::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(UserEntitlement::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'owner_id')
            ->where('owner_type', OwnerType::User->value);
    }

    public function privateIngredients(): HasMany
    {
        return $this->hasMany(Ingredient::class, 'owner_id')
            ->where('owner_type', OwnerType::User->value);
    }

    public function productionBatches(): HasMany
    {
        return $this->hasMany(ProductionBatch::class)
            ->latest('manufacture_date')
            ->latest('id');
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return array<int, int>
     */
    public function accessibleWorkspaceIds(): array
    {
        if ($this->cachedAccessibleWorkspaceIds !== null) {
            return $this->cachedAccessibleWorkspaceIds;
        }

        $ownedWorkspaces = Workspace::withoutGlobalScopes()
            ->selectRaw('id as workspace_id')
            ->where('owner_user_id', $this->id);

        $this->cachedAccessibleWorkspaceIds = WorkspaceMember::withoutGlobalScopes()
            ->select('workspace_id')
            ->where('user_id', $this->id)
            ->union($ownedWorkspaces)
            ->pluck('workspace_id')
            ->unique()
            ->values()
            ->all();

        return $this->cachedAccessibleWorkspaceIds;
    }

    /**
     * @return array<int, int>
     */
    public function ownedWorkspaceIds(): array
    {
        if ($this->cachedOwnedWorkspaceIds === null) {
            $this->cachedOwnedWorkspaceIds = Workspace::withoutGlobalScopes()
                ->where('owner_user_id', $this->id)
                ->pluck('id')
                ->all();
        }

        return $this->cachedOwnedWorkspaceIds;
    }

    public function forgetAccessibleWorkspaceIds(): void
    {
        $this->cachedAccessibleWorkspaceIds = null;
        $this->cachedOwnedWorkspaceIds = null;
        $this->hasResolvedCompany = false;
        $this->cachedCompany = null;
    }

    public function workspaceRoleFor(int $workspaceId): ?WorkspaceMemberRole
    {
        if (Workspace::withoutGlobalScopes()
            ->whereKey($workspaceId)
            ->where('owner_user_id', $this->id)
            ->exists()) {
            return WorkspaceMemberRole::Owner;
        }

        $role = WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $this->id)
            ->value('role');

        return $role === null ? null : WorkspaceMemberRole::from($role);
    }

    /**
     * Get the workspace in which the user is currently working.
     */
    public function company(): ?Workspace
    {
        if ($this->hasResolvedCompany) {
            return $this->cachedCompany;
        }

        $activeWorkspace = $this->active_workspace_id === null
            ? null
            : Workspace::withoutGlobalScopes()->find($this->active_workspace_id);

        if ($activeWorkspace instanceof Workspace && $activeWorkspace->hasMember($this)) {
            $this->cachedCompany = $activeWorkspace;
            $this->hasResolvedCompany = true;

            return $this->cachedCompany;
        }

        $this->cachedCompany = Workspace::withoutGlobalScopes()
            ->where('owner_user_id', $this->id)
            ->first()
            ?? Workspace::withoutGlobalScopes()
                ->whereIn('id', WorkspaceMember::withoutGlobalScopes()
                    ->where('user_id', $this->id)
                    ->select('workspace_id'))
                ->first();
        $this->hasResolvedCompany = true;

        return $this->cachedCompany;
    }

    /**
     * Get the default currency for this user's company.
     */
    public function defaultCurrency(): string
    {
        return $this->company()?->default_currency ?? config('currency.default', 'EUR');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() !== 'admin' || $this->is_admin;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'bool',
            'password' => 'hashed',
        ];
    }
}
