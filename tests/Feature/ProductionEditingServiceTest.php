<?php

use App\Enums\WorkspaceMemberRole;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\ProductionEditingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProductionEditingFixture;

uses(RefreshDatabase::class);

it('keeps another production available while the first production is reserved', function (): void {
    $fixture = ProductionEditingFixture::create();
    [$first, $second] = ProductionRun::factory()->for($fixture->workspace)->count(2)->create()->all();
    $context = $fixture->lease($first);
    $service = app(ProductionEditingService::class);

    expect($service->status($fixture->owner, $fixture->workspace->id, [$second->id])['status'])->toBe('available');
    $secondToken = (string) Str::uuid();
    expect($service->acquire($fixture->owner, $fixture->workspace->id, [$second->id => 0], $secondToken)['status'])->toBe('acquired');
    expect($service->status($fixture->owner, $fixture->workspace->id, [$first->id], $context->token)['status'])->toBe('acquired');
    $this->assertDatabaseCount('production_edit_leases', 2);
    expect($first->fresh()->edit_revision)->toBe(0)->and($second->fresh()->edit_revision)->toBe(0);
});

it('observes without acquiring and blocks another token belonging to the same actor', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $service = app(ProductionEditingService::class);
    $token = (string) Str::uuid();

    expect($service->status($fixture->owner, $fixture->workspace->id, [$run->id])['status'])->toBe('available');
    $this->assertDatabaseCount('production_edit_leases', 0);
    expect($service->acquire($fixture->owner, $fixture->workspace->id, [$run->id => 0], $token)['status'])->toBe('acquired');
    expect($service->acquire($fixture->owner, $fixture->workspace->id, [$run->id => 0], (string) Str::uuid())['status'])->toBe('blocked');
    expect($run->fresh()->edit_revision)->toBe(0);
});

it('acquires none of a group with a held or stale production', function (string $obstacle): void {
    $fixture = ProductionEditingFixture::create();
    [$first, $second] = ProductionRun::factory()->for($fixture->workspace)->count(2)->create()->all();
    $service = app(ProductionEditingService::class);
    if ($obstacle === 'blocked') {
        $service->acquire($fixture->owner, $fixture->workspace->id, [$second->id => 0], (string) Str::uuid());
    } else {
        $second->forceFill(['edit_revision' => 1])->save();
    }

    $state = $service->acquire($fixture->owner, $fixture->workspace->id, [$first->id => 0, $second->id => 0], (string) Str::uuid());

    expect($state['status'])->toBe($obstacle);
    $this->assertDatabaseMissing('production_edit_leases', ['production_run_id' => $first->id]);
})->with(['held' => 'blocked', 'changed' => 'stale']);

it('renews existing ownership without resetting creation time and refuses an expired heartbeat', function (): void {
    $this->freezeTime();
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $service = app(ProductionEditingService::class);
    $token = (string) Str::uuid();
    $service->acquire($fixture->owner, $fixture->workspace->id, [$run->id => 0], $token);
    $created = DB::table('production_edit_leases')->value('created_at');
    $this->travel(80)->seconds();

    expect($service->heartbeat($fixture->owner, $fixture->workspace->id, [$run->id => 0], $token)['status'])->toBe('acquired');
    expect(DB::table('production_edit_leases')->value('created_at'))->toBe($created);
    $this->travel(90)->seconds();
    expect(fn () => $service->heartbeat($fixture->owner, $fixture->workspace->id, [$run->id => 0], $token))->toThrow(ValidationException::class);
    expect($service->status($fixture->owner, $fixture->workspace->id, [$run->id], $token)['status'])->toBe('available');
});

it('allows operational acquisition but restricts takeover to managers', function (WorkspaceMemberRole $role): void {
    $fixture = ProductionEditingFixture::create();
    $member = User::factory()->create(['active_workspace_id' => $fixture->workspace->id]);
    WorkspaceMember::factory()->for($fixture->workspace)->for($member)->create(['role' => $role]);
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $service = app(ProductionEditingService::class);
    $token = (string) Str::uuid();
    if ($role === WorkspaceMemberRole::Viewer) {
        expect(fn () => $service->acquire($member, $fixture->workspace->id, [$run->id => 0], $token))->toThrow(AuthorizationException::class);
        $this->assertDatabaseCount('production_edit_leases', 0);

        return;
    }
    expect($service->acquire($member, $fixture->workspace->id, [$run->id => 0], $token)['status'])->toBe('acquired');
    if ($role === WorkspaceMemberRole::Editor) {
        expect(fn () => $service->takeover($member, $fixture->workspace->id, [$run->id => 0], (string) Str::uuid(), 'Urgent'))->toThrow(AuthorizationException::class);
    } else {
        expect($service->takeover($member, $fixture->workspace->id, [$run->id => 0], (string) Str::uuid(), ' Urgent ')['status'])->toBe('acquired');
        $this->assertDatabaseHas('production_edit_takeovers', ['reason' => 'Urgent', 'previous_user_id' => $member->id]);
    }
})->with(['admin' => WorkspaceMemberRole::Admin, 'editor' => WorkspaceMemberRole::Editor, 'viewer' => WorkspaceMemberRole::Viewer]);

it('preserves a takeover against a delayed old-token release and makes matching release repeatable', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $service = app(ProductionEditingService::class);
    $old = (string) Str::uuid();
    $new = (string) Str::uuid();
    $service->acquire($fixture->owner, $fixture->workspace->id, [$run->id => 0], $old);
    $service->takeover($fixture->owner, $fixture->workspace->id, [$run->id => 0], $new, 'Recover another session');

    $service->release($fixture->owner, $fixture->workspace->id, [$run->id], $old);
    expect($service->status($fixture->owner, $fixture->workspace->id, [$run->id], $new)['status'])->toBe('acquired');
    $service->release($fixture->owner, $fixture->workspace->id, [$run->id], $new);
    $service->release($fixture->owner, $fixture->workspace->id, [$run->id], $new);
    $this->assertDatabaseCount('production_edit_leases', 0);
});

it('releases surviving records after a group member was deleted', function (): void {
    $fixture = ProductionEditingFixture::create();
    [$first, $second] = ProductionRun::factory()->for($fixture->workspace)->count(2)->create()->all();
    $service = app(ProductionEditingService::class);
    $token = (string) Str::uuid();
    $service->acquire($fixture->owner, $fixture->workspace->id, [$first->id => 0, $second->id => 0], $token);
    $first->delete();

    $service->release($fixture->owner, $fixture->workspace->id, [$first->id, $second->id], $token);

    $this->assertDatabaseCount('production_edit_leases', 0);
});

it('rejects invalid group sizes and tokens before writing', function (array $ids, string $token): void {
    $fixture = ProductionEditingFixture::create();
    $service = app(ProductionEditingService::class);

    expect(fn () => $service->acquire($fixture->owner, $fixture->workspace->id, array_fill_keys($ids, 0), $token))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('production_edit_leases', 0);
})->with(['empty' => [[], '00000000-0000-4000-8000-000000000001'], 'oversized' => [range(1, 101), '00000000-0000-4000-8000-000000000001'], 'malformed token' => [[1], 'bad']]);
