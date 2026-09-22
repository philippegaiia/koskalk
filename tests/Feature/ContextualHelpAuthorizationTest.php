<?php

use App\Models\HelpContentExport;
use App\Models\HelpTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('restricts help topic administration to administrators', function (bool $isAdmin): void {
    $user = User::factory()->make(['is_admin' => $isAdmin]);
    $topic = HelpTopic::factory()->make();

    foreach (['viewAny', 'create', 'import', 'export'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, HelpTopic::class))->toBe($isAdmin);
    }
    foreach (['view', 'update', 'restore', 'publish', 'archive', 'translate'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, $topic))->toBe($isAdmin);
    }
    expect(Gate::forUser($user)->allows('delete', $topic))->toBeFalse();
    expect(Gate::forUser($user)->allows('forceDelete', $topic))->toBeFalse();
})->with([true, false]);

it('restricts help exports to administrators', function (bool $isAdmin): void {
    $user = User::factory()->make(['is_admin' => $isAdmin]);
    $export = HelpContentExport::factory()->make();

    foreach (['viewAny', 'create'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, HelpContentExport::class))->toBe($isAdmin);
    }
    foreach (['view', 'download'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, $export))->toBe($isAdmin);
    }
    expect(Gate::forUser($user)->allows('delete', $export))->toBeFalse();
})->with([true, false]);

it('denies guests access to help administration', function (): void {
    expect(Gate::allows('viewAny', HelpTopic::class))->toBeFalse();
    expect(Gate::allows('create', HelpContentExport::class))->toBeFalse();
});
