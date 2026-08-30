<?php

namespace App\Providers;

use App\Contracts\ExchangeRateProvider;
use App\Contracts\IngredientEditorialClient;
use App\Contracts\IngredientGuidanceAuthoringClient;
use App\Contracts\IngredientGuidanceLocalizationClient;
use App\Contracts\IngredientGuidanceResearchClient;
use App\Contracts\IngredientResearchClient;
use App\Listeners\CreateDefaultCompany;
use App\Listeners\SyncPlanEntitlementFromPaddleSubscription;
use App\Services\FrankfurterExchangeRateProvider;
use App\Services\IngredientEnrichment\OpenAiIngredientEditorialClient;
use App\Services\IngredientEnrichment\OpenAiIngredientGuidanceClient;
use App\Services\IngredientEnrichment\OpenAiIngredientGuidanceLocalizationClient;
use App\Services\IngredientEnrichment\OpenAiIngredientGapResearchClient;
use App\Services\IngredientEnrichment\OpenAiIngredientResearchClient;
use App\Services\LocalePreferenceResolver;
use Filament\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->scoped(LocalePreferenceResolver::class);
        $this->app->bind(ExchangeRateProvider::class, FrankfurterExchangeRateProvider::class);
        $this->app->bind(IngredientEditorialClient::class, OpenAiIngredientEditorialClient::class);
        $this->app->bind(IngredientGuidanceAuthoringClient::class, OpenAiIngredientGuidanceClient::class);
        $this->app->bind(IngredientGuidanceLocalizationClient::class, OpenAiIngredientGuidanceLocalizationClient::class);
        $this->app->bind(IngredientGuidanceResearchClient::class, OpenAiIngredientGapResearchClient::class);
        $this->app->bind(IngredientResearchClient::class, OpenAiIngredientResearchClient::class);
    }

    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(12)
            ->mixedCase()
            ->numbers()
            ->symbols());

        RateLimiter::for('beta-invite-accept', function (Request $request): array {
            return [
                Limit::perMinute(5)->by('beta-invite-ip:'.$request->ip()),
                Limit::perMinute(5)->by('beta-invite-token:'.(string) $request->route('token').'|'.$request->ip()),
            ];
        });

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
