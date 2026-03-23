<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\OwnerType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasTenantOwnership
{
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function tenantOwnerType(): ?OwnerType
    {
        if ($this->owner_type instanceof OwnerType) {
            return $this->owner_type;
        }

        return $this->owner_type === null ? null : OwnerType::from($this->owner_type);
    }

    public function tenantOwnerId(): ?int
    {
        return $this->owner_id === null ? null : (int) $this->owner_id;
    }

    public function tenantWorkspaceId(): ?int
    {
        return $this->workspace_id === null ? null : (int) $this->workspace_id;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->tenantOwnerType() === OwnerType::User
            && $this->tenantOwnerId() === $user->id;
    }

    public function isWorkspaceAccessibleBy(User $user): bool
    {
        $workspaceId = $this->tenantWorkspaceId();

        if ($workspaceId === null && $this->tenantOwnerType() === OwnerType::Workspace) {
            $workspaceId = $this->tenantOwnerId();
        }

        return $workspaceId !== null && in_array($workspaceId, $user->accessibleWorkspaceIds(), true);
    }

    public function isAccessibleBy(User $user): bool
    {
        return $this->isOwnedBy($user) || $this->isWorkspaceAccessibleBy($user);
    }
}
