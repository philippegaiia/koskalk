<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('loads trusted proxies from cacheable configuration', function () {
    $environment = Env::getRepository();
    $originalTrustedProxies = $environment->get('TRUSTED_PROXIES');

    $environment->set('TRUSTED_PROXIES', '*');

    try {
        $trustedProxyConfigPath = config_path('trustedproxy.php');

        expect(file_exists($trustedProxyConfigPath))->toBeTrue()
            ->and(file_get_contents(base_path('bootstrap/app.php')))
            ->not->toContain("env('TRUSTED_PROXIES')")
            ->and(require $trustedProxyConfigPath)
            ->toMatchArray(['proxies' => '*']);
    } finally {
        if ($originalTrustedProxies === null) {
            $environment->clear('TRUSTED_PROXIES');
        } else {
            $environment->set('TRUSTED_PROXIES', $originalTrustedProxies);
        }
    }
});

it('uses the forwarded client IP when the calling proxy is trusted', function () {
    config(['trustedproxy.proxies' => '*']);

    Route::get('/_trusted-proxy-ip', fn (Request $request): string => $request->ip());

    $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.1'])
        ->withHeader('X-Forwarded-For', '203.0.113.10')
        ->get('/_trusted-proxy-ip')
        ->assertOk()
        ->assertSeeText('203.0.113.10');
});

it('adds baseline browser security headers', function () {
    $this->get('https://koskalk.test/')
        ->assertRedirect(route('login'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('rate limits sensitive mutations and confidential exports', function () {
    expect(Route::getRoutes()->getByName('account.password.update')?->gatherMiddleware())
        ->toContain('throttle:5,1')
        ->and(Route::getRoutes()->getByName('recipes.export.xlsx')?->gatherMiddleware())
        ->toContain('throttle:10,1')
        ->and(Route::getRoutes()->getByName('recipes.production-batches.store')?->gatherMiddleware())
        ->toContain('throttle:30,1');
});

it('prevents authenticated responses from being cached', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-cache, no-store, private');
});
