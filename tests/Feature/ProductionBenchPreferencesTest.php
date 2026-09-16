<?php

use App\Actions\Production\SaveProductionBenchPreferences;
use App\Enums\WorkspaceMemberRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('defaults both optional location features off and limits productions to one per day', function (): void {
    $workspace = Workspace::factory()->create();

    $savedWorkspace = $workspace->fresh();

    expect($savedWorkspace->uses_production_locations)->toBeFalse()
        ->and($savedWorkspace->uses_storage_locations)->toBeFalse()
        ->and($savedWorkspace->production_daily_limit)->toBe(1);
});

it('saves each location feature independently with a bounded daily limit', function (): void {
    $fixture = productionBenchPreferencesFixture();

    $savedWorkspace = app(SaveProductionBenchPreferences::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        usesProductionLocations: true,
        usesStorageLocations: false,
        productionDailyLimit: '24',
    );

    expect($savedWorkspace->uses_production_locations)->toBeTrue()
        ->and($savedWorkspace->uses_storage_locations)->toBeFalse()
        ->and($savedWorkspace->production_daily_limit)->toBe(24);

    $savedWorkspace = app(SaveProductionBenchPreferences::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        usesProductionLocations: false,
        usesStorageLocations: true,
        productionDailyLimit: 1000,
    );

    expect($savedWorkspace->fresh()->uses_production_locations)->toBeFalse()
        ->and($savedWorkspace->fresh()->uses_storage_locations)->toBeTrue()
        ->and($savedWorkspace->fresh()->production_daily_limit)->toBe(1000);
});

it('rejects a daily limit outside the positive whole number range without changing preferences', function (string $invalidLimit): void {
    $fixture = productionBenchPreferencesFixture();
    $fixture['workspace']->update([
        'uses_production_locations' => true,
        'uses_storage_locations' => true,
        'production_daily_limit' => 12,
    ]);

    try {
        app(SaveProductionBenchPreferences::class)->handle(
            actor: $fixture['owner'],
            workspace: $fixture['workspace'],
            usesProductionLocations: false,
            usesStorageLocations: false,
            productionDailyLimit: $invalidLimit,
        );
    } catch (ValidationException $exception) {
        expect($exception->errors()['production_daily_limit'])
            ->toContain(__('locations.validation.production_daily_limit'));
    }

    expect($fixture['workspace']->fresh()->uses_production_locations)->toBeTrue()
        ->and($fixture['workspace']->fresh()->uses_storage_locations)->toBeTrue()
        ->and($fixture['workspace']->fresh()->production_daily_limit)->toBe(12);
})->with([
    'zero' => '0',
    'negative' => '-1',
    'fractional' => '1.5',
    'above maximum' => '1001',
]);

it('rejects preference changes for an inactive or cancelled production bench', function (): void {
    $fixture = productionBenchPreferencesFixture(active: false);

    expect(fn (): Workspace => app(SaveProductionBenchPreferences::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        usesProductionLocations: true,
        usesStorageLocations: true,
        productionDailyLimit: 5,
    ))->toThrow(ValidationException::class);

    WorkspaceProductionEntitlement::factory()
        ->for($fixture['workspace'])
        ->cancelled()
        ->create();

    expect(fn (): Workspace => app(SaveProductionBenchPreferences::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        usesProductionLocations: true,
        usesStorageLocations: true,
        productionDailyLimit: 5,
    ))->toThrow(ValidationException::class);

    expect($fixture['workspace']->fresh()->uses_production_locations)->toBeFalse()
        ->and($fixture['workspace']->fresh()->uses_storage_locations)->toBeFalse()
        ->and($fixture['workspace']->fresh()->production_daily_limit)->toBe(1);
});

it('forbids viewers and foreign users from changing preferences', function (): void {
    $fixture = productionBenchPreferencesFixture();
    $viewer = User::factory()->create();
    $outsider = User::factory()->create();
    WorkspaceMember::factory()->for($fixture['workspace'])->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    expect(fn (): Workspace => app(SaveProductionBenchPreferences::class)->handle(
        actor: $viewer,
        workspace: $fixture['workspace'],
        usesProductionLocations: true,
        usesStorageLocations: false,
        productionDailyLimit: 5,
    ))->toThrow(AuthorizationException::class);

    expect(fn (): Workspace => app(SaveProductionBenchPreferences::class)->handle(
        actor: $outsider,
        workspace: $fixture['workspace'],
        usesProductionLocations: true,
        usesStorageLocations: false,
        productionDailyLimit: 5,
    ))->toThrow(AuthorizationException::class);

    expect($fixture['workspace']->fresh()->uses_production_locations)->toBeFalse()
        ->and($fixture['workspace']->fresh()->uses_storage_locations)->toBeFalse()
        ->and($fixture['workspace']->fresh()->production_daily_limit)->toBe(1);
});

/** @return array{owner: User, workspace: Workspace} */
function productionBenchPreferencesFixture(bool $active = true): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    if ($active) {
        WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    }

    return compact('owner', 'workspace');
}
