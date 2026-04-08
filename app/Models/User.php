<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\WorkspaceMemberRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'is_admin', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var array<int, int>|null
     */
    private ?array $cachedAccessibleWorkspaceIds = null;

    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_user_id');
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

        $this->cachedAccessibleWorkspaceIds = array_values(array_unique(array_merge(
            Workspace::withoutGlobalScopes()
                ->where('owner_user_id', $this->id)
                ->pluck('id')
                ->all(),
            WorkspaceMember::withoutGlobalScopes()
                ->where('user_id', $this->id)
                ->pluck('workspace_id')
                ->all(),
        )));

        return $this->cachedAccessibleWorkspaceIds;
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
