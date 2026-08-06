<?php

use Illuminate\Support\Facades\Blade;

it('renders a fixed accessible notification region', function () {
    $html = Blade::render('<x-app-notification message="Changes saved." type="success" />');

    expect($html)
        ->toContain('data-app-notification')
        ->toContain('fixed')
        ->toContain('sm:right-6')
        ->toContain('x-on:app-notification.window')
        ->toContain("type === 'error' ? 'alert' : 'status'")
        ->toContain("type === 'error' ? 'assertive' : 'polite'")
        ->toContain('Changes saved.')
        ->toContain(__('navigation.actions.dismiss_notification'));
});

it('mounts the shared notification once in the authenticated app shell', function () {
    $layout = file_get_contents(resource_path('views/layouts/app-shell.blade.php'));

    expect($layout)
        ->toContain('<x-app-notification')
        ->toContain(":message=\"session('error') ?? session('status')\"")
        ->toContain(":type=\"session('error') ? 'error' : 'success'\"");
});

it('does not render transient status messages inline in dashboard forms', function (string $view) {
    $contents = file_get_contents(resource_path("views/{$view}.blade.php"));

    expect($contents)->not->toContain('@if ($statusMessage)');
})->with([
    'ingredient editor' => 'livewire/dashboard/ingredient-editor',
    'packaging editor' => 'livewire/dashboard/packaging-item-editor',
    'ingredient index' => 'livewire/dashboard/ingredients-index',
    'packaging index' => 'livewire/dashboard/packaging-items-index',
    'media library' => 'livewire/dashboard/media-library-index',
]);

it('does not render redirect flash messages inline beneath page content', function (string $view) {
    $contents = file_get_contents(resource_path("views/{$view}.blade.php"));

    expect($contents)->not->toContain("@if (session('status'))");
})->with([
    'recipes index' => 'livewire/dashboard/recipes-index',
    'recipe workbench header' => 'livewire/dashboard/partials/recipe-workbench/header',
    'saved recipe' => 'recipes/version',
    'production batch' => 'production-batches/show',
]);
