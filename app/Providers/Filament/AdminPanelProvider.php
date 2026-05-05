<?php

namespace App\Providers\Filament;

use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->font('Instrument Sans')
            ->brandName((string) config('app.name', 'Soapkraft'))
            ->brandLogo(fn (): View => view('filament.admin.logo'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('images/app/brand/soapkraftlogo-beige.png'))
            ->defaultThemeMode(ThemeMode::Light)
            ->login()
            ->colors([
                'danger' => [
                    50 => '#fff1f2',
                    100 => '#ffe4e6',
                    200 => '#fecdd3',
                    300 => '#fda4af',
                    400 => '#fb7185',
                    500 => '#be123c',
                    600 => '#9f1239',
                    700 => '#881337',
                    800 => '#6f102d',
                    900 => '#4c0519',
                    950 => '#2c020d',
                ],
                'gray' => [
                    50 => '#fffefb',
                    100 => '#f6f4ee',
                    200 => '#ece9df',
                    300 => '#d8d0c2',
                    400 => '#b9afa0',
                    500 => '#8f8678',
                    600 => '#68635a',
                    700 => '#4d483f',
                    800 => '#3b372f',
                    900 => '#211f1a',
                    950 => '#14120f',
                ],
                'info' => [
                    50 => '#f4f6fa',
                    100 => '#e6ebf3',
                    200 => '#cfd8e8',
                    300 => '#aab9d2',
                    400 => '#5b6c8f',
                    500 => '#4b5a78',
                    600 => '#3e4a62',
                    700 => '#353f52',
                    800 => '#303746',
                    900 => '#202531',
                    950 => '#141722',
                ],
                'primary' => [
                    50 => '#fbf4f1',
                    100 => '#f7ece6',
                    200 => '#ecd1c3',
                    300 => '#dfad95',
                    400 => '#cf835e',
                    500 => '#a85530',
                    600 => '#8f4424',
                    700 => '#74361d',
                    800 => '#66331f',
                    900 => '#522c1d',
                    950 => '#2d160d',
                ],
                'success' => [
                    50 => '#e9f3ee',
                    100 => '#d3e7dc',
                    200 => '#a9ceb9',
                    300 => '#7db295',
                    400 => '#579174',
                    500 => '#2d6a4f',
                    600 => '#255b43',
                    700 => '#1b4332',
                    800 => '#173829',
                    900 => '#10271d',
                    950 => '#081711',
                ],
                'warning' => [
                    50 => '#fff7ed',
                    100 => '#f7ead7',
                    200 => '#efd2aa',
                    300 => '#e5b674',
                    400 => '#d7943e',
                    500 => '#b45309',
                    600 => '#8a3f06',
                    700 => '#723408',
                    800 => '#5f2d0b',
                    900 => '#4d250b',
                    950 => '#2d1303',
                ],
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->databaseNotifications();
    }
}
