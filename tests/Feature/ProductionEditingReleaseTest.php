<?php

use App\Enums\WorkspaceMemberRole;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionEditingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ProductionEditingFixture;

uses(RefreshDatabase::class);

it('releases only matching ownership through an authenticated departure request', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $context = $fixture->lease($run);
    $this->actingAs($fixture->owner);

    $this->postJson(route('production-bench.production.editing.release'), ['token' => (string) Str::uuid(), 'production_ids' => [$run->public_id]])->assertOk()->assertJsonPath('ok', true);
    $this->assertDatabaseCount('production_edit_leases', 1);
    $this->postJson(route('production-bench.production.editing.release'), ['token' => $context->token, 'production_ids' => [$run->public_id]])->assertOk()->assertJsonPath('ok', true);
    $this->assertDatabaseCount('production_edit_leases', 0);
    expect($run->fresh()->edit_revision)->toBe(0);
});

it('rejects malformed and oversized release selections without changing ownership', function (array $ids, string $token): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $fixture->lease($run);
    $this->actingAs($fixture->owner);

    $this->postJson(route('production-bench.production.editing.release'), ['token' => $token, 'production_ids' => $ids])->assertUnprocessable();

    $this->assertDatabaseCount('production_edit_leases', 1);
})->with(['bad token' => [['00000000-0000-4000-8000-000000000001'], 'bad'], 'empty' => [[], '00000000-0000-4000-8000-000000000001'], 'too many' => [array_fill(0, 101, '00000000-0000-4000-8000-000000000001'), '00000000-0000-4000-8000-000000000001']]);

it('leaves foreign productions untouched and releases surviving group members after deletion', function (): void {
    $fixture = ProductionEditingFixture::create();
    [$first, $second] = ProductionRun::factory()->for($fixture->workspace)->count(2)->create()->all();
    $token = (string) Str::uuid();
    app(ProductionEditingService::class)->acquire($fixture->owner, $fixture->workspace->id, [$first->id => 0, $second->id => 0], $token);
    $foreign = ProductionRun::factory()->create();
    $first->delete();
    $this->actingAs($fixture->owner);

    $this->postJson(route('production-bench.production.editing.release'), ['token' => $token, 'production_ids' => [$first->public_id, $second->public_id, $foreign->public_id]])->assertOk()->assertJsonPath('ok', true);

    $this->assertDatabaseCount('production_edit_leases', 0);
    $this->assertModelExists($foreign);
});

it('requires an authenticated actor to release ownership', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $context = $fixture->lease($run);

    $this->postJson(route('production-bench.production.editing.release'), ['token' => $context->token, 'production_ids' => [$run->public_id]])->assertUnauthorized();

    $this->assertDatabaseCount('production_edit_leases', 1);
});

it('rejects a duplicated production inside one release selection', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $context = $fixture->lease($run);
    $this->actingAs($fixture->owner);

    $this->postJson(route('production-bench.production.editing.release'), ['token' => $context->token, 'production_ids' => [$run->public_id, $run->public_id]])->assertUnprocessable();

    $this->assertDatabaseCount('production_edit_leases', 1);
});

it('releases for a downgraded viewer because release is housekeeping', function (): void {
    $fixture = ProductionEditingFixture::create();
    $member = User::factory()->create(['active_workspace_id' => $fixture->workspace->id]);
    $membership = WorkspaceMember::factory()->for($fixture->workspace)->for($member)->create(['role' => WorkspaceMemberRole::Editor]);
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $token = (string) Str::uuid();
    app(ProductionEditingService::class)->acquire($member, $fixture->workspace->id, [$run->id => 0], $token);
    $membership->update(['role' => WorkspaceMemberRole::Viewer]);
    $this->actingAs($member);

    $this->postJson(route('production-bench.production.editing.release'), ['token' => $token, 'production_ids' => [$run->public_id]])->assertOk()->assertJsonPath('ok', true);

    $this->assertDatabaseCount('production_edit_leases', 0);
});

it('releases for a cancelled production bench because release is housekeeping', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $context = $fixture->lease($run);
    app(ProductionBenchAccess::class)->cancel($fixture->owner, $fixture->workspace);
    $this->actingAs($fixture->owner);

    $this->postJson(route('production-bench.production.editing.release'), ['token' => $context->token, 'production_ids' => [$run->public_id]])->assertOk()->assertJsonPath('ok', true);

    $this->assertDatabaseCount('production_edit_leases', 0);
});

it('refuses to release after the membership was revoked', function (): void {
    $fixture = ProductionEditingFixture::create();
    $member = User::factory()->create(['active_workspace_id' => $fixture->workspace->id]);
    $membership = WorkspaceMember::factory()->for($fixture->workspace)->for($member)->create(['role' => WorkspaceMemberRole::Editor]);
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $token = (string) Str::uuid();
    app(ProductionEditingService::class)->acquire($member, $fixture->workspace->id, [$run->id => 0], $token);
    $membership->delete();
    $this->actingAs($member);

    $this->postJson(route('production-bench.production.editing.release'), ['token' => $token, 'production_ids' => [$run->public_id]])->assertForbidden();

    $this->assertDatabaseCount('production_edit_leases', 1);
});
