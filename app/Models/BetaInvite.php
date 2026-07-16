<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'email',
    'workspace_name',
    'token_hash',
    'invited_by_user_id',
    'expires_at',
    'accepted_at',
    'revoked_at',
])]
#[Hidden(['token_hash'])]
class BetaInvite extends Model
{
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function statusLabel(): string
    {
        if ($this->accepted_at !== null) {
            return 'Accepted';
        }

        if ($this->revoked_at !== null) {
            return 'Revoked';
        }

        if ($this->expires_at === null || $this->expires_at->isPast()) {
            return 'Expired';
        }

        return 'Pending';
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
