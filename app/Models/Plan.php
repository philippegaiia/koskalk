<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'description', 'paddle_product_id', 'paddle_price_id', 'billing_interval', 'price_label', 'is_default', 'is_active', 'display_order'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (Plan $plan): void {
            if (! $plan->is_default) {
                return;
            }

            static::withoutEvents(fn () => static::query()
                ->whereKeyNot($plan->getKey())
                ->update(['is_default' => false]));
        });
    }

    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(UserEntitlement::class);
    }

    public function isBillable(): bool
    {
        return filled($this->paddle_price_id);
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'bool',
            'is_active' => 'bool',
        ];
    }
}
