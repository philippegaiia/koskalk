<?php

it('allows the hamburger to open the sidebar below the desktop breakpoint', function () {
    $source = file_get_contents(resource_path('js/app.js'));

    expect($source)
        ->toContain('const nextOpen = open;')
        ->toContain('if (persist && isDesktop) {')
        ->toContain('setSidebarState(sidebarIsDesktop() ? sidebarStoredState() : false, false);')
        ->toContain("overlay?.classList.toggle('hidden', !nextOpen || isDesktop)")
        ->not->toContain('const nextOpen = isDesktop ? open : false;');
});
