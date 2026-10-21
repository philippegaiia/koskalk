<?php

namespace App\Models;

use Database\Factories\WorkspaceDossierGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform-admin grant that turns the Product Information File (DIP) module on for one workspace.
 */
#[Fillable([
    'workspace_id',
    'granted_by_user_id',
    'granted_at',
    'revoked_at',
])]
class WorkspaceDossierGrant extends Model
{
    /** @use HasFactory<WorkspaceDossierGrantFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @param  Builder<WorkspaceDossierGrant>  $query
     * @return Builder<WorkspaceDossierGrant>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
