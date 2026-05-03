<?php

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
        ->assertSee('tracking-[0.015em]', false)
        ->assertSee('images/public/soapkraft-hero-benches.webp', false)
        ->assertDontSee('images/public/soapkraft-hero-benches.png', false)
        ->assertSeeText('Free soap calculator · soap & cosmetic formulation workspace')
        ->assertSeeText('Your formula, your ingredients, your bench — in one place.')
        ->assertSeeText('Start with a quick lye calculation, or build a complete soap or cosmetic formula with phases, ingredients, costs, label signals, and history kept together.')
        ->assertSeeText('Build soap and cosmetic formulas')
        ->assertSeeText('Save your soap and cosmetic formulas')
        ->assertSeeText('Start saving formulas')
        ->assertSeeText('No account needed for quick calculations. Create an account when you want to save formulas, ingredients, and history.')
        ->assertDontSeeText('Create free workspace');
});

it('renders the public dashboard shell page', function () {
    $this->get(route('dashboard'))
        ->assertSuccessful();
});
