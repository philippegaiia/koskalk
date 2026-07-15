<?php

use App\Models\Plan;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\EntitlementService;
use Database\Seeders\PlanSeeder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Laravel\Paddle\Events\SubscriptionCanceled;
use Laravel\Paddle\Events\SubscriptionCreated;
use Laravel\Paddle\Events\SubscriptionPaused;
use Laravel\Paddle\Events\SubscriptionUpdated;
use Laravel\Paddle\Subscription;

uses(RefreshDatabase::class);

it('accepts Paddle webhook requests without a browser CSRF token', function () {
    $originalEnvironment = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $request = Request::create('/paddle/webhook', 'POST');
        $request->setLaravelSession(app('session')->driver());

        $response = app(PreventRequestForgery::class)->handle(
            $request,
            fn (): Response => response('accepted'),
        );

        expect($response->getContent())->toBe('accepted');
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
    }
});

it('rejects Paddle webhook requests without a valid Paddle signature', function () {
    config(['cashier.webhook_secret' => 'pdl_ntfset_test_secret']);

    $this->postJson(route('cashier.webhook'))
        ->assertForbidden();
});

it('accepts Paddle webhook requests with a valid Paddle signature', function () {
    $secret = 'pdl_ntfset_test_secret';
    $timestamp = time();
    $payload = json_encode(['event_type' => 'test.event'], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', "{$timestamp}:{$payload}", $secret);

    config(['cashier.webhook_secret' => $secret]);

    $this->call(
        'POST',
        route('cashier.webhook'),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PADDLE_SIGNATURE' => "ts={$timestamp};h1={$signature}",
        ],
        content: $payload,
    )
        ->assertSuccessful();
});

it('refuses to boot for production HTTP requests without a Paddle webhook secret', function () {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('isProduction')->andReturnTrue();
    $application->shouldReceive('runningInConsole')->andReturnFalse();
    config(['cashier.webhook_secret' => null]);

    (new AppServiceProvider($application))->boot();
})->throws(LogicException::class, 'PADDLE_WEBHOOK_SECRET must be configured in production.');

it('allows production console commands to boot before Paddle is configured', function () {
    $originalEnvironment = app()->environment();
    $originalWebhookSecret = config('cashier.webhook_secret');

    app()->detectEnvironment(fn (): string => 'production');
    config(['cashier.webhook_secret' => null]);

    try {
        expect(app()->runningInConsole())->toBeTrue();

        (new AppServiceProvider(app()))->boot();
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
        config(['cashier.webhook_secret' => $originalWebhookSecret]);
    }
});

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

it('does not start a payment method update when billing keys are missing', function () {
    config([
        'cashier.api_key' => null,
        'cashier.client_side_token' => null,
    ]);

    $user = User::factory()->create();
    billingSubscriptionFor($user, 'active', 'pri_growth_monthly', 'pro_growth');

    $this->actingAs($user)
        ->post(route('billing.payment-method.update'))
        ->assertRedirect(route('account'))
        ->assertSessionHas('billing_status', 'Paddle is installed, but payment method updates are disabled until the Paddle API key and client-side token are configured.');
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

it('does not restart an entitlement when Paddle replays a subscription creation event', function () {
    $this->seed(PlanSeeder::class);

    $user = User::factory()->create();
    $paidPlan = Plan::factory()
        ->billable('pri_growth_monthly', 'pro_growth')
        ->create(['slug' => 'growth']);
    $subscription = billingSubscriptionFor($user, 'active', 'pri_growth_monthly', 'pro_growth');

    $this->travelTo(Carbon::parse('2026-06-19 10:00:00'));
    event(new SubscriptionCreated($user, $subscription, []));
    $originalStartsAt = $user->entitlements()
        ->where('plan_id', $paidPlan->id)
        ->where('source', 'paddle')
        ->sole()
        ->starts_at;

    $this->travelTo(Carbon::parse('2026-06-19 11:00:00'));
    event(new SubscriptionCreated($user, $subscription, []));

    $paddleEntitlements = $user->entitlements()
        ->where('plan_id', $paidPlan->id)
        ->where('source', 'paddle')
        ->get();

    expect($paddleEntitlements)->toHaveCount(1)
        ->and($paddleEntitlements->sole()->starts_at->equalTo($originalStartsAt))->toBeTrue();
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

it('keeps paid access until a scheduled pause becomes effective', function () {
    $this->seed(PlanSeeder::class);

    $user = User::factory()->create();
    $freePlan = Plan::query()->where('slug', 'free-beta')->firstOrFail();
    $paidPlan = Plan::factory()
        ->billable('pri_growth_monthly', 'pro_growth')
        ->create(['slug' => 'growth']);
    $subscription = billingSubscriptionFor($user, 'active', 'pri_growth_monthly', 'pro_growth');

    $this->travelTo(Carbon::parse('2026-06-19 10:00:00'));
    $subscription->update(['paused_at' => now()->addDay()]);
    event(new SubscriptionUpdated($subscription->refresh(), []));

    expect(app(EntitlementService::class)->planFor($user->refresh())?->is($paidPlan))->toBeTrue();

    $this->travelTo(Carbon::parse('2026-06-20 10:01:00'));
    $subscription->update([
        'status' => 'paused',
        'paused_at' => now()->subMinute(),
    ]);
    event(new SubscriptionPaused($subscription->refresh(), []));

    expect(app(EntitlementService::class)->planFor($user->refresh())?->is($freePlan))->toBeTrue();
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
