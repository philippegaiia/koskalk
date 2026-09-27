<?php

namespace App\Models;

use App\Enums\WorkspaceMemberRole;
use App\Models\Concerns\HasPublicId;
use Database\Factories\WorkspaceInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'email', 'role', 'token_hash', 'invited_by_user_id', 'expires_at', 'accepted_at', 'revoked_at', 'accepted_by_user_id', 'last_sent_at'])]
#[Hidden(['token_hash'])]
class WorkspaceInvitation extends Model
{
    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory;

    use HasPublicId;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    protected function casts(): array
    {
        return ['role' => WorkspaceMemberRole::class, 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime', 'last_sent_at' => 'datetime'];
    }
}
