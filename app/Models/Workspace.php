<?php

namespace App\Models;

use App\MassDisplaySystem;
use App\Models\Concerns\HasPublicId;
use App\Models\Scopes\OwnedByCurrentTenantScope;
use App\WorkspaceMemberRole;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'slug', 'owner_user_id', 'default_currency', 'country', 'mass_display_system'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    use HasPublicId;

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

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function mediaLabels(): HasMany
    {
        return $this->hasMany(MediaLabel::class);
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function packagingItems(): HasMany
    {
        return $this->hasMany(PackagingItem::class);
    }

    public function currentMaterialPrices(): HasMany
    {
        return $this->hasMany(CurrentMaterialPrice::class);
    }

    public function productionEntitlement(): HasOne
    {
        return $this->hasOne(WorkspaceProductionEntitlement::class);
    }

    public function productionRuns(): HasMany
    {
        return $this->hasMany(ProductionRun::class);
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

        return $role instanceof WorkspaceMemberRole
            ? $role
            : ($role === null ? null : WorkspaceMemberRole::from($role));
    }

    protected function casts(): array
    {
        return [
            'mass_display_system' => MassDisplaySystem::class,
        ];
    }
}
