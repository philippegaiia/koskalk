<?php

use App\Models\InterfaceTranslation;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('does not expose public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Marie Maker',
        'email' => 'marie@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertNotFound();

    $this->assertDatabaseMissing(User::class, [
        'email' => 'marie@example.com',
    ]);
});

it('logs a registered user in and out', function () {
    $user = User::factory()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSeeText('Sign out');

    $this->post(route('logout'))
        ->assertRedirect(route('login'));

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
        ])
        ->assertRedirect(route('account'))
        ->assertSessionHas('profile_status', 'Profile updated.');

    expect($user->refresh())
        ->name->toBe('New Name')
        ->email->toBe('old@example.com');
});

it('rejects account email changes during the invite-only MVP', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($user)
        ->from(route('account'))
        ->patch(route('account.profile.update'), [
            'name' => 'Owner',
            'email' => 'changed@example.com',
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
            'password' => 'NewSecurePass1!',
            'password_confirmation' => 'NewSecurePass1!',
        ])
        ->assertRedirect(route('account'))
        ->assertSessionHas('password_status', 'Password updated.');

    expect(Hash::check('NewSecurePass1!', $user->refresh()->password))->toBeTrue();
});

it('requires the current password before changing account password', function () {
    $user = User::factory()->create([
        'password' => 'old-password',
    ]);

    $this->actingAs($user)
        ->from(route('account'))
        ->patch(route('account.password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'NewSecurePass1!',
            'password_confirmation' => 'NewSecurePass1!',
        ])
        ->assertRedirect(route('account'))
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('old-password', $user->refresh()->password))->toBeTrue();
});

it('rejects account passwords that do not meet the launch policy', function (string $password) {
    $user = User::factory()->create(['password' => 'old-password']);

    $this->actingAs($user)
        ->from(route('account'))
        ->patch(route('account.password.update'), [
            'current_password' => 'old-password',
            'password' => $password,
            'password_confirmation' => $password,
        ])
        ->assertRedirect(route('account'))
        ->assertSessionHasErrors('password');

    expect(Hash::check('old-password', $user->refresh()->password))->toBeTrue();
})->with([
    'fewer than twelve characters' => 'Short1!',
    'missing uppercase' => 'securelaunch1!',
    'missing lowercase' => 'SECURELAUNCH1!',
    'missing number' => 'SecureLaunch!!',
    'missing symbol' => 'SecureLaunch12',
]);

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

it('redirects unverified users away from private application routes', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertSuccessful()
        ->assertSeeText('This account is not verified.');
});

it('lets unverified users sign out from the verification notice', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertSuccessful()
        ->assertSeeText('Sign out')
        ->assertSee('action="'.route('logout').'"', escape: false)
        ->assertSee('name="_token"', escape: false);

    $this->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('renders the account verification notice using interface translations', function () {
    $translations = [
        'page_title' => 'Vérification du compte',
        'eyebrow' => 'Sécurité du compte',
        'heading' => 'Ce compte n’est pas vérifié.',
        'body' => 'Contactez l’administrateur avant d’ouvrir votre espace privé.',
        'sign_out' => 'Se déconnecter',
    ];

    foreach ($translations as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'auth',
            'key' => "verification.{$key}",
            'text' => ['fr' => $translation],
        ]);
    }

    App::setLocale('fr');

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertSuccessful()
        ->assertSeeTextInOrder(array_values($translations))
        ->assertDontSeeText('This account is not verified.');
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

it('describes access as restricted on the login page', function () {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSeeText('Sign in to your workspace')
        ->assertSeeText('Access is provisioned by invitation.')
        ->assertSee('bg-forest-deep', escape: false)
        ->assertSee('bg-accent', escape: false)
        ->assertSee('accent-accent', escape: false)
        ->assertDontSee('bg-accent-soft p-5', escape: false)
        ->assertDontSeeText('Your formulation workspace')
        ->assertDontSeeText('Your formulas, library, and costings')
        ->assertDontSeeText('Create an account');
});

it('renders the login page using interface translations', function () {
    $translations = [
        'heading' => 'Connectez-vous à votre espace de travail',
        'email' => 'Adresse e-mail',
        'password' => 'Mot de passe',
        'remember_me' => 'Se souvenir de moi',
        'submit' => 'Se connecter',
        'invitation_only' => 'Accès accordé uniquement sur invitation.',
    ];

    foreach ($translations as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'auth',
            'key' => "login.{$key}",
            'text' => ['fr' => $translation],
        ]);
    }

    App::setLocale('fr');

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSeeTextInOrder(array_values($translations))
        ->assertDontSeeText('Sign in to your workspace');
});

it('provides Laravel Lang authentication sources for supported languages', function (
    string $locale,
    string $loginHeading,
    string $verificationHeading,
) {
    App::setLocale($locale);

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSeeText($loginHeading)
        ->assertDontSeeText('Sign in to your workspace');

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertSuccessful()
        ->assertSeeText($verificationHeading)
        ->assertDontSeeText('This account is not verified.');

    expect(__('validation.required'))->not->toBe('The :attribute field is required.');
})->with([
    'French' => ['fr', 'Connectez-vous à votre espace de travail', 'Ce compte n’est pas vérifié.'],
    'Spanish' => ['es', 'Inicia sesión en tu espacio de trabajo', 'Esta cuenta no está verificada.'],
    'German' => ['de', 'Melden Sie sich bei Ihrem Arbeitsbereich an', 'Dieses Konto ist nicht verifiziert.'],
    'Italian' => ['it', 'Accedi al tuo spazio di lavoro', 'Questo account non è verificato.'],
]);
