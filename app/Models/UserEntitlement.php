<?php

namespace App\Models;

use Database\Factories\UserEntitlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'plan_id', 'status', 'source', 'starts_at', 'ends_at'])]
class UserEntitlement extends Model
{
    /** @use HasFactory<UserEntitlementFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(function (Builder $startsAtQuery): void {
                $startsAtQuery
                    ->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $endsAtQuery): void {
                $endsAtQuery
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
