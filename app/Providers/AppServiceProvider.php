<?php

namespace App\Providers;

use App\Listeners\CreateDefaultCompany;
use Filament\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(Registered::class, CreateDefaultCompany::class);
    }
}
