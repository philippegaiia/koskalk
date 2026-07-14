<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

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
