<?php

use App\Livewire\Dashboard\PackagingItemsIndex;
use App\Models\User;
use App\Models\UserPackagingItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

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
        ->assertSee('Create reusable packaging items here, then reuse them in recipe costing.')
        ->assertSee(route('packaging-items.index'))
        ->assertSee('Tube 50 g');
});

it('creates a packaging item directly from the packaging items page', function () {
    $user = User::factory()->create();

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->set('form.name', 'Kraft soap box')
        ->set('form.unit_cost', '0.4200')
        ->set('form.notes', '100g rectangle')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('form.name', '')
        ->assertSee('Kraft soap box');

    expect(UserPackagingItem::query()->where('user_id', $user->id)->first())
        ->not->toBeNull()
        ->name->toBe('Kraft soap box');
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

it('shows only the signed-in user packaging items on the page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Front sticker',
        'unit_cost' => 0.03,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    UserPackagingItem::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'Hidden competitor box',
        'unit_cost' => 0.99,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Front sticker')
        ->assertDontSee('Hidden competitor box')
        ->assertSee('Save packaging item');
});
