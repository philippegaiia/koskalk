<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\WorkspaceMemberRole;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('provisions a verified owner with one workspace and the default entitlement', function () {
    $this->seed(PlanSeeder::class);

    $this->artisan('app:provision-workspace-owner', [
        'email' => 'owner@example.com',
        '--name' => 'Marie Maker',
        '--workspace' => 'Atelier Marie',
    ])
        ->expectsQuestion('Password', 'a-secure-launch-password')
        ->expectsQuestion('Confirm password', 'a-secure-launch-password')
        ->assertSuccessful();

    $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
    $workspace = Workspace::withoutGlobalScopes()->where('owner_user_id', $user->id)->firstOrFail();
    $membership = WorkspaceMember::withoutGlobalScopes()
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($user->name)->toBe('Marie Maker')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-secure-launch-password', $user->password))->toBeTrue()
        ->and($workspace->name)->toBe('Atelier Marie')
        ->and($workspace->default_currency)->toBe('EUR')
        ->and(WorkspaceMember::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and($membership->role)->toBe(WorkspaceMemberRole::Owner)
        ->and($user->entitlements()->with('plan')->firstOrFail()->plan->is_default)->toBeTrue();
});

it('refuses to provision a duplicate email without partial records', function () {
    $this->seed(PlanSeeder::class);
    User::factory()->create(['email' => 'owner@example.com']);

    $countsBefore = [
        User::query()->count(),
        Workspace::withoutGlobalScopes()->count(),
        WorkspaceMember::withoutGlobalScopes()->count(),
    ];

    $this->artisan('app:provision-workspace-owner', [
        'email' => 'OWNER@example.com',
        '--name' => 'Duplicate Owner',
        '--workspace' => 'Duplicate Workspace',
    ])->assertFailed();

    expect([
        User::query()->count(),
        Workspace::withoutGlobalScopes()->count(),
        WorkspaceMember::withoutGlobalScopes()->count(),
    ])->toBe($countsBefore);
});

it('rejects mismatched passwords without creating records', function () {
    $this->seed(PlanSeeder::class);

    $this->artisan('app:provision-workspace-owner', [
        'email' => 'owner@example.com',
        '--name' => 'Marie Maker',
        '--workspace' => 'Atelier Marie',
    ])
        ->expectsQuestion('Password', 'a-secure-launch-password')
        ->expectsQuestion('Confirm password', 'a-different-password')
        ->assertFailed();

    $this->assertDatabaseMissing(User::class, ['email' => 'owner@example.com']);
    expect(Workspace::withoutGlobalScopes()->count())->toBe(0);
});
