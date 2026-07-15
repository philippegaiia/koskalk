<?php

namespace App\Providers;

use App\Listeners\CreateDefaultCompany;
use App\Listeners\SyncPlanEntitlementFromPaddleSubscription;
use Filament\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Paddle\Events\SubscriptionCanceled;
use Laravel\Paddle\Events\SubscriptionCreated;
use Laravel\Paddle\Events\SubscriptionPaused;
use Laravel\Paddle\Events\SubscriptionUpdated;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(12)
            ->mixedCase()
            ->numbers()
            ->symbols());

        if ($this->app->isProduction()
            && ! $this->app->runningInConsole()
            && blank(config('cashier.webhook_secret'))) {
            throw new LogicException('PADDLE_WEBHOOK_SECRET must be configured in production.');
        }

        Event::listen(Registered::class, CreateDefaultCompany::class);
        Event::listen(SubscriptionCreated::class, SyncPlanEntitlementFromPaddleSubscription::class);
        Event::listen(SubscriptionUpdated::class, SyncPlanEntitlementFromPaddleSubscription::class);
        Event::listen(SubscriptionPaused::class, SyncPlanEntitlementFromPaddleSubscription::class);
        Event::listen(SubscriptionCanceled::class, SyncPlanEntitlementFromPaddleSubscription::class);

        if (str_contains(request()->getHost(), 'sharedwithexpose.com')) {
            URL::forceScheme('https');
        }
    }
}
