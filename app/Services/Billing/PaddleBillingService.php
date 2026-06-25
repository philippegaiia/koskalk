<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Support\Collection;
use Laravel\Paddle\Checkout;
use Laravel\Paddle\Subscription;

class PaddleBillingService
{
    public const SUBSCRIPTION_TYPE = 'default';

    public function __construct(private readonly EntitlementService $entitlementService) {}

    public function isConfigured(): bool
    {
        return filled(config('cashier.client_side_token'))
            && filled(config('cashier.api_key'));
    }

    /**
     * @return Collection<int, Plan>
     */
    public function billablePlans(): Collection
    {
        return Plan::query()
            ->where('is_active', true)
            ->whereNotNull('paddle_price_id')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    public function checkoutFor(User $user, Plan $plan): Checkout
    {
        return $user->checkout($plan->paddle_price_id)
            ->customData([
                'subscription_type' => self::SUBSCRIPTION_TYPE,
                'plan_id' => (string) $plan->id,
                'plan_slug' => $plan->slug,
            ])
            ->returnTo(route('account', ['checkout' => 'completed']));
    }

    public function currentSubscriptionFor(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->with('items')
            ->where('type', self::SUBSCRIPTION_TYPE)
            ->latest('created_at')
            ->get()
            ->first(fn (Subscription $subscription): bool => $this->subscriptionKeepsPaidAccess($subscription));
    }

    public function planForSubscription(Subscription $subscription): ?Plan
    {
        $subscription->loadMissing('items');

        $priceIds = $subscription->items
            ->pluck('price_id')
            ->filter()
            ->all();

        if ($priceIds !== []) {
            $plan = Plan::query()
                ->where('is_active', true)
                ->whereIn('paddle_price_id', $priceIds)
                ->orderBy('display_order')
                ->orderBy('id')
                ->first();

            if ($plan instanceof Plan) {
                return $plan;
            }
        }

        $productIds = $subscription->items
            ->pluck('product_id')
            ->filter()
            ->all();

        if ($productIds === []) {
            return null;
        }

        return Plan::query()
            ->where('is_active', true)
            ->whereIn('paddle_product_id', $productIds)
            ->orderBy('display_order')
            ->orderBy('id')
            ->first();
    }

    public function syncEntitlementFromSubscription(User $user, Subscription $subscription): ?Plan
    {
        if (! $this->subscriptionKeepsPaidAccess($subscription)) {
            $this->endPaddleEntitlements($user);

            return null;
        }

        $plan = $this->planForSubscription($subscription);

        if (! $plan instanceof Plan) {
            $this->endPaddleEntitlements($user);

            return null;
        }

        $endsAt = $subscription->ends_at ?? $subscription->paused_at;

        $user->entitlements()
            ->active()
            ->where('plan_id', '!=', $plan->id)
            ->update([
                'status' => 'ended',
                'ends_at' => now(),
            ]);

        $entitlement = $user->entitlements()->updateOrCreate(
            [
                'plan_id' => $plan->id,
                'source' => 'paddle',
            ],
            [
                'status' => 'active',
                'ends_at' => $endsAt,
            ],
        );

        if ($entitlement->wasRecentlyCreated) {
            $entitlement->update(['starts_at' => now()]);
        }

        return $plan;
    }

    public function endPaddleEntitlements(User $user): void
    {
        $user->entitlements()
            ->active()
            ->where('source', 'paddle')
            ->update([
                'status' => 'ended',
                'ends_at' => now(),
            ]);

        $this->entitlementService->assignDefaultPlan($user);
    }

    public function subscriptionKeepsPaidAccess(Subscription $subscription): bool
    {
        return $subscription->valid()
            || $subscription->onGracePeriod()
            || $subscription->onPausedGracePeriod();
    }
}
