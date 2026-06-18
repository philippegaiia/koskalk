<?php

use App\Models\Plan;
use App\Models\User;
use App\Services\EntitlementService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Paddle\Events\SubscriptionCanceled;
use Laravel\Paddle\Events\SubscriptionCreated;
use Laravel\Paddle\Subscription;

uses(RefreshDatabase::class);

it('shows billable plans with checkout disabled until Paddle keys are configured', function () {
    config([
        'cashier.api_key' => null,
        'cashier.client_side_token' => null,
    ]);

    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    Plan::factory()
        ->billable('pri_growth_monthly', 'pro_growth')
        ->create([
            'name' => 'Growth',
            'slug' => 'growth',
            'display_order' => 20,
        ]);

    $this->actingAs($user)
        ->get(route('account'))
        ->assertSuccessful()
        ->assertSeeText('Paddle')
        ->assertSeeText('Growth')
        ->assertSeeText('Checkout disabled')
        ->assertSeeText('Connect the Paddle API key and client-side token to enable checkout.');
});

it('does not start Paddle checkout when billing keys are missing', function () {
    config([
        'cashier.api_key' => null,
        'cashier.client_side_token' => null,
    ]);

    $user = User::factory()->create();
    $plan = Plan::factory()
        ->billable('pri_growth_monthly', 'pro_growth')
        ->create(['slug' => 'growth']);

    $this->actingAs($user)
        ->get(route('billing.checkout', $plan))
        ->assertRedirect(route('account'))
        ->assertSessionHas('billing_status', 'Paddle is installed, but checkout is disabled until the Paddle API key and client-side token are configured.');
});

it('syncs the app entitlement when Paddle creates a paid subscription', function () {
    $this->seed(PlanSeeder::class);

    $user = User::factory()->create();
    $freePlan = Plan::query()->where('slug', 'free-beta')->firstOrFail();
    $paidPlan = Plan::factory()
        ->billable('pri_growth_monthly', 'pro_growth')
        ->create([
            'name' => 'Growth',
            'slug' => 'growth',
            'display_order' => 20,
        ]);

    $user->entitlements()->create([
        'plan_id' => $freePlan->id,
        'status' => 'active',
        'source' => 'registration',
        'starts_at' => now(),
    ]);

    $subscription = billingSubscriptionFor($user, 'active', 'pri_growth_monthly', 'pro_growth');

    event(new SubscriptionCreated($user, $subscription, []));

    expect(app(EntitlementService::class)->planFor($user->refresh())?->is($paidPlan))->toBeTrue()
        ->and($user->entitlements()->where('source', 'paddle')->where('status', 'active')->exists())->toBeTrue()
        ->and($user->entitlements()->where('plan_id', $freePlan->id)->where('status', 'ended')->exists())->toBeTrue();
});

it('falls back to the default plan when a Paddle subscription no longer grants access', function () {
    $this->seed(PlanSeeder::class);

    $user = User::factory()->create();
    $freePlan = Plan::query()->where('slug', 'free-beta')->firstOrFail();
    $paidPlan = Plan::factory()
        ->billable('pri_growth_monthly', 'pro_growth')
        ->create(['slug' => 'growth']);

    $user->entitlements()->create([
        'plan_id' => $paidPlan->id,
        'status' => 'active',
        'source' => 'paddle',
        'starts_at' => now()->subMonth(),
    ]);

    $subscription = billingSubscriptionFor($user, 'canceled', 'pri_growth_monthly', 'pro_growth');

    event(new SubscriptionCanceled($subscription, []));

    expect(app(EntitlementService::class)->planFor($user->refresh())?->is($freePlan))->toBeTrue()
        ->and($user->entitlements()->where('source', 'paddle')->where('status', 'ended')->exists())->toBeTrue();
});

function billingSubscriptionFor(User $user, string $status, string $priceId, string $productId): Subscription
{
    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'paddle_id' => 'sub_'.strtolower(fake()->bothify('????####')),
        'status' => $status,
    ]);

    $subscription->items()->create([
        'product_id' => $productId,
        'price_id' => $priceId,
        'status' => $status,
        'quantity' => 1,
    ]);

    return $subscription->refresh()->load('items');
}
