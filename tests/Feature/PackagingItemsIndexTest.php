<?php

use App\Models\User;
use App\Models\UserPackagingItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets a signed-in user open the packaging items page and see saved items', function () {
    $user = User::factory()->create();

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Tube 50 g',
        'unit_cost' => 0.1200,
        'currency' => 'EUR',
        'notes' => 'Reusable catalog item',
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Packaging Items')
        ->assertSee('Reusable packaging catalog')
        ->assertSee(route('packaging-items.index'))
        ->assertSee('Tube 50 g');
});

it('only shows the signed-in users packaging items in stable name then id order', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Alpha Box',
        'unit_cost' => 0.1000,
        'currency' => 'EUR',
        'notes' => 'First alpha entry',
    ]);

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Alpha Box',
        'unit_cost' => 0.2000,
        'currency' => 'EUR',
        'notes' => 'Second alpha entry',
    ]);

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Beta Box',
        'unit_cost' => 0.3000,
        'currency' => 'EUR',
        'notes' => 'Beta entry',
    ]);

    UserPackagingItem::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'Alpha Box',
        'unit_cost' => 0.4000,
        'currency' => 'EUR',
        'notes' => 'Other user entry',
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Alpha Box',
            'First alpha entry',
            'Alpha Box',
            'Second alpha entry',
            'Beta Box',
            'Beta entry',
        ])
        ->assertDontSee('Other user entry');
});

it('allows signed-out visitors to open the packaging items page and shows the sign in prompt', function () {
    $this->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Sign in to manage packaging items');
});
