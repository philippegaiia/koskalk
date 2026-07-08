<?php

it('allows the hamburger to open the sidebar below the desktop breakpoint', function () {
    $source = file_get_contents(resource_path('js/app.js'));
    $appShell = view('layouts.app-shell')->render();

    expect($source)
        ->toContain('const nextOpen = open;')
        ->toContain('if (persist && isDesktop) {')
        ->toContain('setSidebarState(sidebarIsDesktop() ? sidebarStoredState() : false, false);')
        ->toContain("overlay?.classList.toggle('hidden', !nextOpen || isDesktop)")
        ->not->toContain('const nextOpen = isDesktop ? open : false;')
        ->and($appShell)
        ->toContain('data-sidebar-header-toggle')
        ->toContain('bg-[var(--color-forest-deep)] text-[var(--color-inverse)]')
        ->toContain('hover:bg-[var(--color-forest-mid)]')
        ->not->toContain('bg-[var(--color-panel-strong)] text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]');
});
