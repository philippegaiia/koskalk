<?php

use App\Models\InterfaceTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;

uses(RefreshDatabase::class);

it('renders the public home page', function () {
    $this->get(route('home'))
        ->assertSuccessful()
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
        ->assertSeeText('Create a free account')
        ->assertSeeText('Use the free soap calculator')
        ->assertSeeText('Sign in')
        ->assertSeeText('Soap only. No account required.')
        ->assertDontSeeText('Start saving formulas')
        ->assertDontSeeText('Why use Soapkraft?')
        ->assertDontSeeText('From quick calculation to source of truth')
        ->assertDontSeeText('Keep your formulas in one workspace')
        ->assertDontSeeText('Track costing and batch details');
});

it('links authenticated homepage visitors to their workspace', function () {
    $user = User::factory()->make(['id' => 1]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertSuccessful()
        ->assertSeeText('Open workspace')
        ->assertSee(route('dashboard'), false)
        ->assertDontSeeText('Create a free account')
        ->assertDontSee(route('register'), false);
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

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('<html lang="fr"', false)
        ->assertSeeText('Construisez la formule. Conservez tout son dossier.')
        ->assertSeeText('Produit')
        ->assertDontSeeText('Make the formula. Keep the whole record.');

    App::setLocale('de');

    $this->get(route('home'))
        ->assertSuccessful()
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
    $response = $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('lg:items-stretch', false)
        ->assertSee('lg:sticky lg:top-0 lg:h-dvh lg:self-start', false)
        ->assertDontSee('min-h-screen', false);

    expect(substr_count($response->getContent(), 'min-h-dvh'))->toBe(3);
});
