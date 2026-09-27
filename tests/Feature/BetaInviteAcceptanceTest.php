<?php

use App\Enums\ProductionBenchEntitlementStatus;
use App\Enums\WorkspaceMemberRole;
use App\Models\BetaInvite;
use App\Models\InterfaceTranslation;
use App\Models\Plan;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use App\Notifications\BetaWorkspaceInvitation;
use App\Services\BetaInviteService;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
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
            'allows_collaboration' => true,
            'allows_production_bench' => true,
        ]);
    Plan::factory()->create(['slug' => 'free', 'is_default' => true]);

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

    expect(WorkspaceProductionEntitlement::query()->whereBelongsTo($workspace)->sole()->status)
        ->toBe(ProductionBenchEntitlementStatus::Active);

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

it('accepts a beta invitation without implicitly granting undeclared production access', function (): void {
    Notification::fake();
    $administrator = User::factory()->create(['is_admin' => true]);
    $plan = Plan::factory()->create(['slug' => 'free-beta', 'is_default' => false]);
    $service = app(BetaInviteService::class);
    $token = $service->issue($administrator, 'legacy.beta@example.com', 'Legacy Beta');

    $user = $service->accept($token, ['name' => 'Tester', 'password' => 'SecureBetaPassword1!']);

    expect($user->entitlements()->sole()->plan_id)->toBe($plan->id)
        ->and(WorkspaceProductionEntitlement::query()->count())->toBe(0);
});

it('rolls back beta acceptance when the explicit beta plan is unavailable', function (bool $hasInactivePlan): void {
    Notification::fake();
    $administrator = User::factory()->create(['is_admin' => true]);
    Plan::factory()->create(['slug' => 'free', 'is_default' => true]);
    if ($hasInactivePlan) {
        Plan::factory()->create(['slug' => 'free-beta', 'is_active' => false]);
    }
    $service = app(BetaInviteService::class);
    $token = $service->issue($administrator, 'unavailable.beta@example.com', 'Unavailable Beta');
    $userCount = User::query()->count();
    $workspaceCount = Workspace::withoutGlobalScopes()->count();
    $membershipCount = WorkspaceMember::withoutGlobalScopes()->count();

    expect(fn () => $service->accept($token, ['name' => 'Tester', 'password' => 'SecureBetaPassword1!']))
        ->toThrow(RuntimeException::class, 'No active Free beta plan is configured.');

    expect(User::query()->count())->toBe($userCount)
        ->and(Workspace::withoutGlobalScopes()->count())->toBe($workspaceCount)
        ->and(WorkspaceMember::withoutGlobalScopes()->count())->toBe($membershipCount)
        ->and(BetaInvite::query()->sole()->accepted_at)->toBeNull()
        ->and(WorkspaceProductionEntitlement::query()->count())->toBe(0);
})->with(['missing' => false, 'inactive' => true]);

it('renders the complete password policy and every failed native rule when accepting an invitation', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    $token = app(BetaInviteService::class)->issue(
        $administrator,
        'password.policy@example.com',
        'Password Policy Studio',
    );

    $this->followingRedirects()
        ->from(route('beta-invites.show', ['token' => $token]))
        ->post(route('beta-invites.accept', ['token' => $token]), [
            'name' => 'Password Policy Tester',
            'password' => 'toto',
            'password_confirmation' => 'toto',
        ])
        ->assertSuccessful()
        ->assertSeeText(__('auth.password_requirements'))
        ->assertSeeText('The password field must be at least 12 characters.')
        ->assertSeeText('The password field must contain at least one uppercase and one lowercase letter.')
        ->assertSeeText('The password field must contain at least one number.')
        ->assertSeeText('The password field must contain at least one symbol.');
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

it('renders the beta invitation page from localized database copy and escapes placeholders', function () {
    $this->withoutVite();
    Notification::fake();
    $this->seed(SupportedLocaleSeeder::class);
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);
    InterfaceTranslation::query()->create([
        'group' => 'auth',
        'key' => 'beta_invitation.introduction',
        'text' => ['fr' => 'Créez :workspace pour :email.'],
    ]);

    $token = app(BetaInviteService::class)->issue(
        User::factory()->create(['is_admin' => true]),
        'beta.tester@example.com',
        '<img src=x onerror=alert(1)>',
    );

    $this->withSession(['locale' => 'fr'])
        ->get(route('beta-invites.show', ['token' => $token]))
        ->assertSuccessful()
        ->assertSee('Créez &lt;img src=x onerror=alert(1)&gt; pour beta.tester@example.com.', false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false);
});

it('renders beta invitation email copy from localized database overrides', function () {
    $this->seed(SupportedLocaleSeeder::class);
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    foreach ([
        'beta_invitation.email.subject' => 'Votre invitation bêta Soapkraft',
        'beta_invitation.email.greeting' => 'Bienvenue dans Soapkraft',
        'beta_invitation.email.invitation' => 'Vous pouvez créer l’espace de travail :workspace.',
        'beta_invitation.email.action' => 'Créer mon espace de travail',
        'beta_invitation.email.expires' => 'Cette invitation expire :expiry.',
        'beta_invitation.email.ignore' => 'Ignorez ce message si vous ne l’attendiez pas.',
    ] as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'auth',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    app()->setLocale('fr');

    $mail = (new BetaWorkspaceInvitation(
        str_repeat('a', 64),
        'Atelier Démo',
        now()->addDays(2),
    ))->toMail(new AnonymousNotifiable);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Votre invitation bêta Soapkraft')
        ->and($html)->toContain('Bienvenue dans Soapkraft')
        ->and($html)->toContain('Vous pouvez créer l’espace de travail Atelier Démo.')
        ->and($html)->toContain('Créer mon espace de travail')
        ->and($html)->toContain('Cette invitation expire')
        ->and($html)->toContain('Ignorez ce message si vous ne l’attendiez pas.');
});
