<?php

namespace App\Models;

use App\Models\Scopes\OwnedByCurrentTenantScope;
use App\WorkspaceMemberRole;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'owner_user_id'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByCurrentTenantScope);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function hasMember(User $user): bool
    {
        return $this->owner_user_id === $user->id
            || WorkspaceMember::withoutGlobalScopes()
                ->where('workspace_id', $this->id)
                ->where('user_id', $user->id)
                ->exists();
    }

    public function roleFor(User $user): ?WorkspaceMemberRole
    {
        if ($this->owner_user_id === $user->id) {
            return WorkspaceMemberRole::Owner;
        }

        $role = WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $this->id)
            ->where('user_id', $user->id)
            ->value('role');

        return $role === null ? null : WorkspaceMemberRole::from($role);
    }
}
