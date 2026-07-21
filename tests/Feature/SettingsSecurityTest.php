<?php

use App\Livewire\Dashboard\SettingsIndex;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\WorkspaceMemberRole;
use Database\Seeders\SupportedLocaleSeeder;
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

it('leaves profile and password management exclusively on the account page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->assertDontSee('Save profile')
        ->assertDontSee('Current password')
        ->assertDontSee('Update password');

    expect(method_exists(SettingsIndex::class, 'saveProfile'))->toBeFalse()
        ->and(method_exists(SettingsIndex::class, 'updatePassword'))->toBeFalse()
        ->and(method_exists(SettingsIndex::class, 'savePreferences'))->toBeTrue();
});

it('saves display preferences without changing account identity', function () {
    $this->seed(SupportedLocaleSeeder::class);
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create([
        'name' => 'Original Owner',
        'email' => 'owner@example.com',
        'locale' => 'en',
        'number_locale' => 'en_US',
    ]);

    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->set('locale', 'fr')
        ->set('numberLocale', 'fr_FR')
        ->call('savePreferences')
        ->assertHasNoErrors()
        ->assertSet('preferencesStatus', 'success');

    expect($user->refresh())
        ->name->toBe('Original Owner')
        ->email->toBe('owner@example.com')
        ->locale->toBe('fr')
        ->number_locale->toBe('fr_FR');
});

it('does not allow a locked workspace identifier to update another workspace', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $ownedWorkspace = Workspace::factory()->for($owner, 'owner')->create(['name' => 'Owned']);
    $otherWorkspace = Workspace::factory()->for($otherOwner, 'owner')->create(['name' => 'Other']);

    $this->actingAs($owner);

    expect(fn () => Livewire::test(SettingsIndex::class)
        ->assertSet('workspaceId', $ownedWorkspace->id)
        ->set('workspaceId', $otherWorkspace->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($otherWorkspace->refresh()->name)->toBe('Other');
});

it('accepts only current selectable currencies for workspace settings', function () {
    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create(['name' => 'Soap Studio']);

    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->set('workspaceCurrency', 'ZZZ')
        ->call('saveWorkspace')
        ->assertHasErrors(['workspaceCurrency'])
        ->set('workspaceCurrency', 'EUR')
        ->call('saveWorkspace')
        ->assertHasNoErrors()
        ->assertSet('workspaceStatus', 'success');

    expect($user->company()?->refresh()->default_currency)->toBe('EUR');
});

it('denies workspace settings changes to non-owner members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['name' => 'Owner workspace']);

    WorkspaceMember::factory()->for($workspace)->for($member)->create([
        'role' => WorkspaceMemberRole::Admin,
    ]);
    $member->update(['active_workspace_id' => $workspace->id]);

    $this->actingAs($member);

    Livewire::test(SettingsIndex::class)
        ->assertSet('workspaceId', $workspace->id)
        ->set('workspaceName', 'Unauthorized change')
        ->call('saveWorkspace')
        ->assertForbidden();

    expect($workspace->refresh()->name)->toBe('Owner workspace');
});
