<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Billing\PaddleBillingService;
use Laravel\Paddle\Events\SubscriptionCanceled;
use Laravel\Paddle\Events\SubscriptionCreated;
use Laravel\Paddle\Events\SubscriptionPaused;
use Laravel\Paddle\Events\SubscriptionUpdated;

class SyncPlanEntitlementFromPaddleSubscription
{
    /**
     * Create the event listener.
     */
    public function __construct(private readonly PaddleBillingService $billing) {}

    /**
     * Handle the event.
     */
    public function handle(
        SubscriptionCreated|SubscriptionUpdated|SubscriptionPaused|SubscriptionCanceled $event
    ): void {
        $billable = $event instanceof SubscriptionCreated
            ? $event->billable
            : $event->subscription->billable;

        if (! $billable instanceof User) {
            return;
        }

        $this->billing->syncEntitlementFromSubscription($billable, $event->subscription);
    }
}
