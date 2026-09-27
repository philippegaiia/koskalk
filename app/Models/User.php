<?php

namespace App\Models;

use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Services\WorkspaceAuthorization;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
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

    public function packagingItems(): HasMany
    {
        return $this->hasMany(PackagingItem::class, 'created_by_user_id');
    }

    public function recipeVersionCostings(): HasMany
    {
        return $this->hasMany(RecipeVersionCosting::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(UserEntitlement::class);
    }

    public function createdMediaLabels(): HasMany
    {
        return $this->hasMany(MediaLabel::class, 'created_by_user_id');
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
        $workspace = app(WorkspaceAuthorization::class)->selectedWorkspace($this);

        return $workspace === null ? [] : [$workspace->id];
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

        $workspaceRole = $role instanceof WorkspaceMemberRole
            ? $role
            : ($role === null ? null : WorkspaceMemberRole::from($role));

        return $workspaceRole === WorkspaceMemberRole::Owner ? null : $workspaceRole;
    }

    /**
     * Get the workspace in which the user is currently working.
     */
    public function company(bool $fresh = false): ?Workspace
    {
        if (! $fresh && $this->hasResolvedCompany) {
            return $this->cachedCompany;
        }

        $workspace = Workspace::withoutGlobalScopes()
            ->where(function (Builder $query): void {
                $query->where('owner_user_id', $this->id)
                    ->orWhereIn('id', WorkspaceMember::withoutGlobalScopes()
                        ->where('user_id', $this->id)
                        ->whereIn('role', [WorkspaceMemberRole::Admin, WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer])
                        ->select('workspace_id'));
            })
            ->orderByRaw('case when id = (select active_workspace_id from users where id = ?) then 0 when owner_user_id = ? then 1 else 2 end', [$this->id, $this->id])
            ->orderBy('id')
            ->first();

        if (! $fresh) {
            $this->cachedCompany = $workspace;
            $this->hasResolvedCompany = true;
        }

        return $workspace;
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
