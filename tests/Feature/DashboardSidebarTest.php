<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders accessible sidebar controls and navigation groups', function () {
    $appShell = file_get_contents(resource_path('views/layouts/app-shell.blade.php'));

    expect($appShell)
        ->toContain('data-sidebar-header-toggle')
        ->toContain('aria-controls="app-sidebar"')
        ->toContain('aria-expanded="true"')
        ->toContain('aria-labelledby="work-navigation-title"')
        ->toContain('aria-labelledby="account-navigation-title"')
        ->toContain('aria-current="page"')
        ->toContain('gap-0.5')
        ->toContain('lg:py-2')
        ->toContain('pointer-coarse:py-3')
        ->toContain('border-l-[var(--color-sidebar-active-text)]')
        ->not->toContain('ring-[var(--color-sidebar-active-ring)]')
        ->toContain("__('navigation.items.overview')")
        ->toContain("__('navigation.items.formulas')")
        ->toContain("__('production_bench.title')")
        ->toContain("__('navigation.actions.sign_out')")
        ->toContain('transition-[grid-template-columns] duration-150 ease-[cubic-bezier(0.2,0,0,1)] motion-reduce:transition-none')
        ->toContain('transition-[width,opacity,padding,translate] duration-150 ease-[cubic-bezier(0.2,0,0,1)] motion-reduce:transition-none')
        ->toContain('bg-transparent text-[var(--color-ink-strong)]')
        ->toContain('transition-[color,background-color,translate,scale,opacity] duration-150 ease-[cubic-bezier(0.2,0,0,1)]')
        ->toContain('hover:bg-[var(--color-field-muted)]')
        ->toContain('focus-visible:outline-[var(--color-active)]')
        ->toContain('active:scale-[0.96]')
        ->not->toContain('bg-[var(--color-forest-deep)] text-[var(--color-inverse)]')
        ->not->toContain('hover:bg-[var(--color-forest-mid)]')
        ->not->toContain('lg:transition-none');
});

it('marks the current page and renders compliance as visible noninteractive status text', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('aria-current="page"', false)
        ->assertSeeText('Compliance')
        ->assertSeeText('Coming soon')
        ->assertDontSee('javascript:void(0)', false)
        ->assertDontSee('aria-disabled="true"', false);
});

it('shows the admin navigation only to administrators', function () {
    $adminUrl = route('filament.admin.pages.dashboard');

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee($adminUrl, false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee($adminUrl, false)
        ->assertSeeText('Admin');
});
