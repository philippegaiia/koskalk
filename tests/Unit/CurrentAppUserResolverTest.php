<?php

use App\Models\User;
use App\Services\CurrentAppUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns only the user authenticated by the current request guard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(app(CurrentAppUserResolver::class)->resolve())->toBe($user);
});

it('does not authenticate a caller supplied user id', function () {
    $user = User::factory()->create();

    expect(app(CurrentAppUserResolver::class)->resolve($user->id))->toBeNull()
        ->and(Auth::check())->toBeFalse();
});
