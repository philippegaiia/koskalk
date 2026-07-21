<?php

use App\Models\InterfaceTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;

uses(RefreshDatabase::class);

it('keeps the public home page available as a WordPress reference', function () {
    $this->view('welcome')
        ->assertSee('data-public-nav-inner', false)
        ->assertSee('data-public-footer-inner', false)
        ->assertSee('data-hero-background', false)
        ->assertSee('object-cover object-center', false)
        ->assertDontSee('object-contain', false)
        ->assertSee('data-hero-veil', false)
        ->assertSee('bg-cream/34', false)
        ->assertSee('data-hero-title', false)
        ->assertSee('font-display', false)
        ->assertSee('images/public/soapkraft-hero-benches.webp', false)
        ->assertDontSee('images/public/soapkraft-hero-benches.png', false)
        ->assertSee('data-workspace-proof', false)
        ->assertSeeText('Make the formula. Keep the whole record.')
        ->assertSeeText('Create, save, cost, and prepare soap and cosmetic formulas for compliance review and production.')
        ->assertSeeText('Complete soap formulas')
        ->assertSeeText('Multiphase cosmetic formulas')
        ->assertSeeText('Formula portfolio')
        ->assertSeeText('Label and compliance context')
        ->assertSeeText('Production batches')
        ->assertSeeText('Your formula, your ingredients, your bench.')
        ->assertSeeText('All in one place.')
        ->assertSeeText('Build your formula portfolio')
        ->assertSeeText('Access is currently by invitation')
        ->assertSeeText('Sign in')
        ->assertDontSeeText('Create a free account')
        ->assertDontSeeText('Use the free soap calculator')
        ->assertDontSee('/register', false)
        ->assertDontSee('/calculator', false)
        ->assertDontSeeText('Start saving formulas')
        ->assertDontSeeText('Why use Soapkraft?')
        ->assertDontSeeText('From quick calculation to source of truth')
        ->assertDontSeeText('Keep your formulas in one workspace')
        ->assertDontSeeText('Track costing and batch details');
});

it('redirects guests from the application root to login', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'));
});

it('redirects authenticated homepage visitors to the formula workbench', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('recipes.create'));
});

it('renders public interface database translations with an English fallback', function () {
    InterfaceTranslation::query()->create([
        'group' => 'homepage',
        'key' => 'hero.title',
        'text' => ['fr' => 'Construisez la formule. Conservez tout son dossier.'],
    ]);

    InterfaceTranslation::query()->create([
        'group' => 'public',
        'key' => 'navigation.product',
        'text' => ['fr' => 'Produit'],
    ]);

    App::setLocale('fr');

    $this->view('welcome')
        ->assertSee('<html lang="fr"', false)
        ->assertSeeText('Construisez la formule. Conservez tout son dossier.')
        ->assertSeeText('Produit')
        ->assertDontSeeText('Make the formula. Keep the whole record.');

    App::setLocale('de');

    $this->view('welcome')
        ->assertSee('<html lang="de"', false)
        ->assertSeeText('Make the formula. Keep the whole record.')
        ->assertSeeText('Product')
        ->assertDontSeeText('homepage.hero.title');
});

it('redirects guests from the dashboard shell page', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

it('keeps the authenticated shell viewport bound while content can grow', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('class="min-h-dvh bg-[var(--color-surface)]', false)
        ->assertSee('class="relative mx-auto min-h-dvh w-full max-w-[2100px] lg:grid lg:grid-cols-[17rem_minmax(0,1fr)] lg:items-stretch', false)
        ->assertSee('w-72 overflow-x-hidden overflow-y-auto bg-[var(--color-sidebar)]', false)
        ->assertSee('lg:sticky lg:top-0 lg:h-dvh lg:self-start', false)
        ->assertSee('class="flex min-h-dvh min-w-0 flex-col"', false);
});
