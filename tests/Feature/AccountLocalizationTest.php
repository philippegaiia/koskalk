<?php

use App\Models\InterfaceTranslation;
use App\Models\Plan;
use App\Models\SupportedLocale;
use App\Models\User;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the approved English account copy', function () {
    config([
        'cashier.api_key' => null,
        'cashier.client_side_token' => null,
    ]);

    $user = User::factory()->create();
    Plan::factory()
        ->billable('pri_account_monthly', 'pro_account')
        ->create();

    expect(config('interface-translations.sources.account'))->toBe(['*']);

    $this->actingAs($user)
        ->get(route('account'))
        ->assertSuccessful()
        ->assertSeeText('Manage your personal details, password, plan, and billing.')
        ->assertSeeText('Update the name associated with your account.')
        ->assertSeeText('Email address changes are not available from this page.')
        ->assertSeeText('Use a strong, unique password to protect your account.')
        ->assertSeeText('Current plan')
        ->assertSeeText('Workspace usage')
        ->assertSeeText('Products')
        ->assertSeeText('Your ingredients')
        ->assertSeeText('Production batches')
        ->assertSeeText('Free account')
        ->assertSeeText('Checkout unavailable')
        ->assertSeeText('Online checkout is not available yet.')
        ->assertDontSeeText('Saved recipes')
        ->assertDontSeeText('Private ingredients')
        ->assertDontSeeText('Contact the administrator to change the provisioned email address.')
        ->assertDontSeeText('Connect the Paddle API key and client-side token to enable checkout.');
});

it('loads account interface copy and status messages from the database', function () {
    $this->seed(SupportedLocaleSeeder::class);
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    foreach ([
        'page.intro' => 'Gérez vos informations personnelles, votre mot de passe, votre offre et votre facturation.',
        'profile.heading' => 'Profil',
        'profile.email_help' => 'L’adresse e-mail ne peut pas être modifiée depuis cette page.',
        'plan.usage_heading' => 'Utilisation de l’espace de travail',
        'usage.products' => 'Produits',
        'usage.used_unlimited' => ':used / Illimité',
        'usage.unlimited' => 'Illimité',
        'billing.heading' => 'Facturation',
        'status.profile_updated' => 'Profil mis à jour.',
    ] as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'account',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    $user = User::factory()->create(['locale' => 'fr']);

    $this->actingAs($user)
        ->get(route('account'))
        ->assertSuccessful()
        ->assertSeeText('Gérez vos informations personnelles, votre mot de passe, votre offre et votre facturation.')
        ->assertSeeText('Profil')
        ->assertSeeText('L’adresse e-mail ne peut pas être modifiée depuis cette page.')
        ->assertSeeText('Utilisation de l’espace de travail')
        ->assertSeeText('Produits')
        ->assertSeeText('0 / Illimité')
        ->assertSeeText('Facturation')
        ->assertDontSeeText('Manage your personal details, password, plan, and billing.');

    $this->actingAs($user)
        ->patch(route('account.profile.update'), ['name' => 'Nom actualisé'])
        ->assertRedirect(route('account'))
        ->assertSessionHas('profile_status', 'Profil mis à jour.');
});

it('keeps every account string in the account translation group', function () {
    $copy = require lang_path('en/account.php');

    expect($copy)->toHaveKeys([
        'page.title',
        'page.intro',
        'profile.heading',
        'profile.description',
        'profile.name',
        'profile.email',
        'profile.email_help',
        'security.heading',
        'security.description',
        'security.current_password',
        'security.new_password',
        'security.confirm_new_password',
        'plan.heading',
        'plan.none',
        'plan.usage_heading',
        'usage.products',
        'usage.ingredients',
        'usage.production_batches',
        'usage.used',
        'usage.used_unlimited',
        'usage.unlimited',
        'usage.remaining',
        'billing.heading',
        'billing.free_account',
        'billing.active_subscription',
        'billing.provider',
        'billing.status',
        'billing.active',
        'billing.no_payment_method',
        'billing.online_checkout_unavailable',
        'billing.payment_update_unavailable',
        'billing.no_active_subscription',
        'actions.sign_out',
        'actions.save_profile',
        'actions.update_password',
        'actions.choose_plan',
        'actions.checkout_unavailable',
        'actions.update_payment_method',
        'status.profile_updated',
        'status.password_updated',
    ]);
});
