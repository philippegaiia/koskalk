<?php

use App\Models\BetaInvite;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Notifications\BetaWorkspaceInvitation;
use App\Services\BetaInviteService;
use App\WorkspaceMemberRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('provisions a verified workspace owner from a single-use Free beta invitation', function () {
    $this->withoutVite();
    Notification::fake();

    $administrator = User::factory()->create(['is_admin' => true]);
    $plan = Plan::factory()
        ->hasLimit('saved_recipes', 15)
        ->hasLimit('private_ingredients', 20)
        ->create([
            'slug' => 'free-beta',
            'is_default' => true,
        ]);

    $token = app(BetaInviteService::class)->issue(
        $administrator,
        'beta.tester@example.com',
        'Beta Tester Studio',
    );

    $invite = BetaInvite::query()->sole();

    expect($invite->email)->toBe('beta.tester@example.com')
        ->and($invite->workspace_name)->toBe('Beta Tester Studio')
        ->and($invite->token_hash)->not->toBe($token)
        ->and($invite->isPending())->toBeTrue();

    Notification::assertSentOnDemand(
        BetaWorkspaceInvitation::class,
        fn (BetaWorkspaceInvitation $notification): bool => $notification->token === $token,
    );

    $this->get(route('beta-invites.show', ['token' => $token]))
        ->assertOk();

    $this->post(route('beta-invites.accept', ['token' => $token]), [
        'name' => 'Beta Tester',
        'password' => 'SecureBetaPassword1!',
        'password_confirmation' => 'SecureBetaPassword1!',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $user = User::query()->where('email', 'beta.tester@example.com')->sole();
    $workspace = Workspace::withoutGlobalScopes()
        ->where('owner_user_id', $user->id)
        ->sole();

    expect($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('SecureBetaPassword1!', $user->password))->toBeTrue()
        ->and($workspace->name)->toBe('Beta Tester Studio')
        ->and($user->active_workspace_id)->toBe($workspace->id)
        ->and(WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->value('role'))->toBe(WorkspaceMemberRole::Owner)
        ->and($user->entitlements()
            ->where('plan_id', $plan->id)
            ->where('status', 'active')
            ->exists())->toBeTrue()
        ->and($invite->refresh()->accepted_at)->not->toBeNull();

    Auth::logout();

    $this->get(route('beta-invites.show', ['token' => $token]))
        ->assertNotFound();
});

it('does not accept expired invitations', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    $token = app(BetaInviteService::class)->issue(
        $administrator,
        'expired.beta@example.com',
        'Expired Studio',
    );

    BetaInvite::query()->sole()->update(['expires_at' => now()->subMinute()]);

    $this->get(route('beta-invites.show', ['token' => $token]))
        ->assertNotFound();
});

it('rate limits invalid invitation acceptance attempts by IP address', function () {
    foreach (range(1, 5) as $attempt) {
        $token = str_pad(dechex($attempt), 64, 'a', STR_PAD_LEFT);

        $this->post(route('beta-invites.accept', ['token' => $token]), [
            'name' => 'Invalid Attempt',
            'password' => 'SecureBetaPassword1!',
            'password_confirmation' => 'SecureBetaPassword1!',
        ])->assertNotFound();
    }

    $this->post(route('beta-invites.accept', ['token' => str_repeat('b', 64)]), [
        'name' => 'Blocked Attempt',
        'password' => 'SecureBetaPassword1!',
        'password_confirmation' => 'SecureBetaPassword1!',
    ])->assertTooManyRequests();
});

it('does not issue beta invitations to an existing account', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    User::factory()->create(['email' => 'existing@example.com']);

    expect(fn () => app(BetaInviteService::class)->issue(
        $administrator,
        'existing@example.com',
        'Existing Studio',
    ))->toThrow(ValidationException::class, 'already has an account');
});
