<?php

use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Illuminate\Support\Facades\Vite;

test('registering the admin panel does not require a built Vite manifest', function () {
    Vite::useHotFile('hot-file-does-not-exist')
        ->useBuildDirectory('build/does-not-exist');

    $provider = new AdminPanelProvider(app());

    $panel = null;

    try {
        $panel = $provider->panel(Panel::make());
    } catch (Throwable $exception) {
        $this->fail(get_class($exception).': '.$exception->getMessage());
    }

    expect($panel)->toBeInstanceOf(Panel::class);
})->after(fn () => Vite::useHotFile(null)->useBuildDirectory('build'));
