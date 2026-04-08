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
        ->assertSee('Tube 50 g');
});

it('allows signed-out visitors to open the packaging items page and shows the sign in prompt', function () {
    $this->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Sign in to manage packaging items');
});
