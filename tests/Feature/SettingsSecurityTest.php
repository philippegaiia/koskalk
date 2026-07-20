<?php

use App\Livewire\Dashboard\SettingsIndex;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('does not expose workspace membership or invitation controls during the MVP', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->assertDontSee('Members')
        ->assertDontSee('Invite member')
        ->assertDontSee('Send invitation');

    expect(method_exists(SettingsIndex::class, 'inviteMember'))->toBeFalse()
        ->and(method_exists(SettingsIndex::class, 'removeMember'))->toBeFalse();
});

it('keeps the provisioned login email immutable while allowing profile changes', function () {
    $user = User::factory()->create([
        'name' => 'Original Owner',
        'email' => 'owner@example.com',
    ]);

    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->set('name', 'Updated Owner')
        ->set('email', 'attacker@example.com')
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSet('profileStatus', 'success');

    expect($user->refresh())
        ->name->toBe('Updated Owner')
        ->email->toBe('owner@example.com');
});

it('does not allow a locked company identifier to update another workspace', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $ownedWorkspace = Workspace::factory()->for($owner, 'owner')->create(['name' => 'Owned']);
    $otherWorkspace = Workspace::factory()->for($otherOwner, 'owner')->create(['name' => 'Other']);

    $this->actingAs($owner);

    expect(fn () => Livewire::test(SettingsIndex::class)
        ->assertSet('companyId', $ownedWorkspace->id)
        ->set('companyId', $otherWorkspace->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($otherWorkspace->refresh()->name)->toBe('Other');
});

it('accepts only current selectable currencies for company settings', function () {
    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create(['name' => 'Soap Studio']);

    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->set('companyCurrency', 'ZZZ')
        ->call('saveCompany')
        ->assertHasErrors(['companyCurrency'])
        ->set('companyCurrency', 'EUR')
        ->call('saveCompany')
        ->assertHasNoErrors()
        ->assertSet('companyStatus', 'success');

    expect($user->company()?->refresh()->default_currency)->toBe('EUR');
});

it('requires a strong password and rate limits current-password attempts', function () {
    $user = User::factory()->create([
        'password' => 'correct-password',
    ]);
    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->set('currentPassword', 'correct-password')
        ->set('newPassword', 'too-short')
        ->set('newPasswordConfirmation', 'too-short')
        ->call('updatePassword')
        ->assertHasErrors(['newPassword']);

    $component = Livewire::test(SettingsIndex::class)
        ->set('newPassword', 'SecureSettings1!')
        ->set('newPasswordConfirmation', 'SecureSettings1!');

    foreach (range(1, 5) as $attempt) {
        $component
            ->set('currentPassword', 'wrong-password-'.$attempt)
            ->call('updatePassword')
            ->assertHasErrors(['currentPassword']);
    }

    $component
        ->set('currentPassword', 'wrong-password-6')
        ->call('updatePassword')
        ->assertHasErrors(['currentPassword'])
        ->assertSee('Too many password attempts');
});

it('rejects settings passwords missing a required character class', function (string $password) {
    $user = User::factory()->create(['password' => 'correct-password']);
    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->set('currentPassword', 'correct-password')
        ->set('newPassword', $password)
        ->set('newPasswordConfirmation', $password)
        ->call('updatePassword')
        ->assertHasErrors(['newPassword']);
})->with([
    'missing uppercase' => 'securelaunch1!',
    'missing lowercase' => 'SECURELAUNCH1!',
    'missing number' => 'SecureLaunch!!',
    'missing symbol' => 'SecureLaunch12',
]);
