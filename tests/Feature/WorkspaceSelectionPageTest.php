<?php

use App\Enums\WorkspaceMemberRole;
use App\Models\InterfaceTranslation;
use App\Models\Plan;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Cache::forget(InterfaceTranslation::getCacheKey('workspaces', 'fr'));
});

beforeEach(function (): void {
    config(['workspaces.collaboration_enabled' => true]);
});

it('shows the current company link in the shared header only while switching is enabled', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create(['name' => 'Selected header company']);
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    $this->actingAs($user)->get(route('dashboard'))->assertOk()
        ->assertSee('Selected header company')
        ->assertSee('href="'.route('workspace-selection.index').'"', false);
    config(['workspaces.collaboration_enabled' => false]);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('href="'.route('workspace-selection.index').'"', false);
    $this->get(route('workspace-selection.index'))->assertNotFound();
});

it('lists authorized companies without displaying foreign names or changing the current company', function (): void {
    $user = User::factory()->create();
    $current = Workspace::factory()->for($user, 'owner')->create(['name' => 'Current picker company']);
    Workspace::factory()->for($user, 'owner')->create(['name' => 'Other authorized company']);
    Workspace::factory()->create(['name' => 'Private unrelated company']);
    $user->forceFill(['active_workspace_id' => $current->id])->save();
    $response = $this->actingAs($user)->get(route('workspace-selection.index'))->assertOk()
        ->assertSee('Current picker company')
        ->assertSee('Other authorized company')
        ->assertDontSee('Private unrelated company')
        ->assertSee('action="'.route('workspace-selection.update').'"', false)
        ->assertSee('name="workspace_public_id"', false);
    expect(ltrim($response->getContent()))->toStartWith('<!DOCTYPE html>');
    expect($user->fresh()->active_workspace_id)->toBe($current->id);
});

it('uses the current database locale override in the company picker', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);
    Workspace::factory()->for($user, 'owner')->create(['name' => 'Picker locale company']);
    InterfaceTranslation::query()->create([
        'group' => 'workspaces',
        'key' => 'selection.page_heading',
        'text' => ['fr' => 'Choisir une entreprise'],
    ]);
    $this->actingAs($user)->get(route('workspace-selection.index'))
        ->assertOk()
        ->assertSeeText('Choisir une entreprise')
        ->assertDontSeeText('Choose a company');
});

it('retains company search through pagination and displays matching results only', function (): void {
    $user = User::factory()->create();
    Workspace::factory()->count(26)->for($user, 'owner')->sequence(fn ($sequence): array => ['name' => 'Searchable studio '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create();
    Workspace::factory()->for($user, 'owner')->create(['name' => 'Unmatched laboratory']);
    $this->actingAs($user)->get(route('workspace-selection.index', ['search' => 'Searchable studio']))->assertOk()
        ->assertViewHas('workspaces', fn (LengthAwarePaginator $workspaces): bool => $workspaces->count() === 25 && $workspaces->total() === 26 && str_contains((string) $workspaces->nextPageUrl(), 'search=Searchable'))
        ->assertSee('Searchable studio 00')
        ->assertDontSee('Unmatched laboratory');
    $this->get(route('workspace-selection.index', ['search' => 'Searchable studio', 'workspace-selection-page' => 2]))->assertOk()
        ->assertViewHas('search', 'Searchable studio')
        ->assertSee('Searchable studio 25')
        ->assertDontSee('Unmatched laboratory');
    expect($user->fresh()->active_workspace_id)->toBeNull();
});

it('keeps a choice available in the header and picker after the current company becomes inaccessible', function (): void {
    $user = User::factory()->create();
    $inaccessible = Workspace::factory()->create(['name' => 'Expired shared company']);
    WorkspaceMember::factory()->for($inaccessible)->for($user)->create(['role' => WorkspaceMemberRole::Viewer]);
    UserEntitlement::factory()->for($inaccessible->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create(['ends_at' => now()->subDay()]);
    Workspace::factory()->for($user, 'owner')->create(['name' => 'Recovery company']);
    $user->forceFill(['active_workspace_id' => $inaccessible->id])->save();
    $this->actingAs($user)->get(route('dashboard'))->assertOk()
        ->assertSee('href="'.route('workspace-selection.index').'"', false)
        ->assertSee(__('workspaces.selection.choose_workspace'));
    $this->get(route('workspace-selection.index'))->assertOk()
        ->assertViewHas('currentWorkspace', null)
        ->assertSee('Recovery company')
        ->assertDontSee('Expired shared company');
    expect($user->fresh()->active_workspace_id)->toBe($inaccessible->id);
});
