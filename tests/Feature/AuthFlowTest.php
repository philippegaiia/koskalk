<?php

use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('registers a free user and attaches the default free plan', function () {
    $this->seed(PlanSeeder::class);

    $this->post(route('register'), [
        'name' => 'Marie Maker',
        'email' => 'marie@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();
    $this->assertDatabaseHas(User::class, [
        'name' => 'Marie Maker',
        'email' => 'marie@example.com',
    ]);

    $user = User::query()
        ->where('email', 'marie@example.com')
        ->firstOrFail();

    expect($user->entitlements()->with('plan')->first()?->plan?->slug)->toBe('free-beta');
});

it('logs a registered user in and out', function () {
    $user = User::factory()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
});

it('shows the account page with current plan usage', function () {
    $this->seed(PlanSeeder::class);

    $user = User::factory()->create();
    $plan = Plan::query()->where('slug', 'free-beta')->firstOrFail();
    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('account'))
        ->assertSuccessful()
        ->assertSeeText('Free beta')
        ->assertSeeText('15')
        ->assertSeeText('20');
});

it('updates account profile details', function () {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);

    $this->actingAs($user)
        ->patch(route('account.profile.update'), [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ])
        ->assertRedirect(route('account'))
        ->assertSessionHas('profile_status', 'Profile updated.');

    expect($user->refresh())
        ->name->toBe('New Name')
        ->email->toBe('new@example.com');
});

it('rejects duplicate account email updates', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($user)
        ->from(route('account'))
        ->patch(route('account.profile.update'), [
            'name' => 'Owner',
            'email' => 'taken@example.com',
        ])
        ->assertRedirect(route('account'))
        ->assertSessionHasErrors('email');

    expect($user->refresh()->email)->toBe('owner@example.com');
});

it('updates account password with the current password', function () {
    $user = User::factory()->create([
        'password' => 'old-password',
    ]);

    $this->actingAs($user)
        ->patch(route('account.password.update'), [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect(route('account'))
        ->assertSessionHas('password_status', 'Password updated.');

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

it('requires the current password before changing account password', function () {
    $user = User::factory()->create([
        'password' => 'old-password',
    ]);

    $this->actingAs($user)
        ->from(route('account'))
        ->patch(route('account.password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect(route('account'))
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('old-password', $user->refresh()->password))->toBeTrue();
});

it('redirects guests away from dashboard app routes', function () {
    $dashboardRoutes = [
        ['GET', route('dashboard')],
        ['GET', route('recipes.index')],
        ['GET', route('recipes.create')],
        ['GET', route('ingredients.index')],
        ['GET', route('packaging-items.index')],
        ['GET', route('settings')],
        ['GET', route('billing.checkout', Plan::factory()->billable('pri_guest', 'pro_guest')->create())],
        ['PATCH', route('account.profile.update')],
        ['PATCH', route('account.password.update')],
        ['POST', route('billing.payment-method.update')],
        ['POST', route('ingredients.duplicate')],
    ];

    foreach ($dashboardRoutes as [$method, $uri]) {
        $this->call($method, $uri)
            ->assertRedirect(route('login'));
    }
});

it('throttles repeated failed login attempts', function () {
    $server = ['REMOTE_ADDR' => '10.50.0.10'];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->withServerVariables($server)
            ->post(route('login'), [
                'email' => 'missing@example.com',
                'password' => 'wrong-password',
            ])
            ->assertRedirect();
    }

    $this->withServerVariables($server)
        ->post(route('login'), [
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
        ])
        ->assertTooManyRequests();
});

it('throttles repeated registration attempts', function () {
    $server = ['REMOTE_ADDR' => '10.50.0.11'];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->withServerVariables($server)
            ->post(route('register'), [
                'name' => '',
                'email' => 'not-an-email',
                'password' => 'short',
            ])
            ->assertRedirect();
    }

    $this->withServerVariables($server)
        ->post(route('register'), [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
        ])
        ->assertTooManyRequests();
});
