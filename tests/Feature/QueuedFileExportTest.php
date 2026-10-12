<?php

use App\Enums\WorkspaceMemberRole;
use App\Jobs\GenerateFileExport;
use App\Models\FileExport;
use App\Models\Ingredient;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\QueuedFileExports;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
});

it('queues an export on the dedicated connection without writing the file in the request', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    Ingredient::factory()->create(['owner_type' => null, 'is_active' => true]);

    $response = $this->actingAs($actor)->postJson(route('ingredients.export'), [
        'workspace' => $workspace->public_id, 'format' => 'csv',
    ])->assertStatus(202)->assertJsonPath('status', 'pending')->assertJsonPath('download_url', null);

    $export = FileExport::query()->sole();
    Queue::assertPushed(GenerateFileExport::class, fn ($job): bool => $job->exportId === $export->id
        && $job->queue === 'exports' && $job->connection === 'database');
    Storage::disk('local')->assertDirectoryEmpty('exports');
    $this->get(route('file-exports.download', $export))->assertNotFound();
    queuedExportDownload($response)->assertDownload()->streamedContent();
    expect($export->fresh()->status)->toBe('ready');
    app()->call([new GenerateFileExport($export->id), 'handle']);
    Storage::disk('local')->assertCount('/exports/'.$export->public_id, 1);
});

it('hides another requesters export even from another workspace member', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    $export = FileExport::factory()->create(['user_id' => $actor->id, 'workspace_id' => $workspace->id]);
    $stranger = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($stranger, 'user')->create(['role' => WorkspaceMemberRole::Viewer]);

    $this->actingAs($stranger)->getJson(route('file-exports.status', $export))->assertNotFound();
    $this->get(route('file-exports.download', $export))->assertNotFound();
});

it('rechecks live membership before generation and download', function (bool $generateBeforeRevocation): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    UserEntitlement::factory()->for($owner, 'user')->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    $membership = WorkspaceMember::factory()->for($workspace)->for($viewer, 'user')->create(['role' => WorkspaceMemberRole::Viewer]);
    Ingredient::factory()->create(['owner_type' => null, 'is_active' => true]);
    $this->actingAs($viewer)->postJson(route('ingredients.export'), [
        'workspace' => $workspace->public_id, 'format' => 'csv',
    ])->assertStatus(202);
    $export = FileExport::query()->sole();
    if ($generateBeforeRevocation) {
        app()->call([new GenerateFileExport($export->id), 'handle']);
    }
    $membership->delete();

    $this->getJson(route('file-exports.status', $export))->assertForbidden();
    $this->get(route('file-exports.download', $export))->assertForbidden();
    if (! $generateBeforeRevocation) {
        expect(fn () => app()->call([new GenerateFileExport($export->id), 'handle']))->toThrow(HttpException::class);
        expect($export->fresh()->status)->toBe('failed');
        Storage::disk('local')->assertDirectoryEmpty('exports');
    }
})->with([false, true]);

it('rejects a download after switching the selected workspace', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    $export = FileExport::factory()->create(['user_id' => $actor->id, 'workspace_id' => $workspace->id]);
    $selected = Workspace::factory()->for($actor, 'owner')->create();
    $actor->forceFill(['active_workspace_id' => $selected->id])->save();

    $this->actingAs($actor)->getJson(route('file-exports.status', $export))->assertStatus(409);
    $this->get(route('file-exports.download', $export))->assertStatus(409);
});

it('expires files and prunes the private directory', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    $export = FileExport::factory()->create([
        'user_id' => $actor->id, 'workspace_id' => $workspace->id, 'expires_at' => now()->subMinute(),
    ]);
    Storage::disk('local')->put('exports/'.$export->public_id.'/download.csv', 'private');

    $this->actingAs($actor)->getJson(route('file-exports.status', $export))->assertStatus(410);
    $this->get(route('file-exports.download', $export))->assertStatus(410);
    app()->call([new GenerateFileExport($export->id), 'handle']);
    $this->artisan('exports:prune')->assertExitCode(0);

    expect(FileExport::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('exports');
});

it('bounds pending exports per workspace', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    Ingredient::factory()->create(['owner_type' => null, 'is_active' => true]);
    config(['exports.maximum_pending_per_workspace' => 1]);
    FileExport::factory()->create(['user_id' => $actor->id, 'workspace_id' => $workspace->id, 'status' => 'pending']);

    $this->actingAs($actor)->postJson(route('ingredients.export'), [
        'workspace' => $workspace->public_id, 'format' => 'csv',
    ])->assertUnprocessable()->assertJsonValidationErrors('export');
    Queue::assertNothingPushed();
});

it('replaces the oldest retained files without blocking another export', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    Ingredient::factory()->create(['owner_type' => null, 'is_active' => true]);
    config(['exports.maximum_retained_per_workspace' => 2]);
    $previous = FileExport::factory()->count(2)->create([
        'user_id' => $actor->id, 'workspace_id' => $workspace->id, 'status' => 'ready',
    ]);
    foreach ($previous as $export) {
        Storage::disk('local')->put('exports/'.$export->public_id.'/download.csv', 'previous');
    }

    $response = $this->actingAs($actor)->postJson(route('ingredients.export'), [
        'workspace' => $workspace->public_id, 'format' => 'csv',
    ]);
    queuedExportDownload($response)->assertDownload()->streamedContent();

    $this->assertModelMissing($previous->first());
    $this->assertModelExists($previous->last());
    expect(FileExport::query()->where('status', 'ready')->count())->toBe(2);
    Storage::disk('local')->assertMissing('exports/'.$previous->first()->public_id.'/download.csv');
    Storage::disk('local')->assertExists('exports/'.$previous->last()->public_id.'/download.csv');
});

it('recovers interrupted exports while preserving current and waiting jobs', function (): void {
    $this->freezeTime();
    $stale = FileExport::factory()->create(['status' => 'processing', 'updated_at' => now()->subMinutes(4)]);
    $active = FileExport::factory()->create(['status' => 'processing']);
    $pending = FileExport::factory()->create(['status' => 'pending', 'updated_at' => now()->subHour()]);
    Storage::disk('local')->put('exports/'.$stale->public_id.'/download.csv', 'partial');
    Storage::disk('local')->put('export-tmp/'.$stale->public_id.'/worksheet.xml', 'partial');
    Storage::disk('local')->put('export-tmp/'.$active->public_id.'/worksheet.xml', 'active');

    $this->artisan('exports:prune')->assertExitCode(0);

    expect($stale->fresh()->status)->toBe('failed')
        ->and($stale->fresh()->error)->toBe(__('ingredients.exports.ui.background_failed'));
    expect($active->fresh()->status)->toBe('processing');
    expect($pending->fresh()->status)->toBe('pending');
    Storage::disk('local')->assertMissing('exports/'.$stale->public_id.'/download.csv');
    Storage::disk('local')->assertMissing('export-tmp/'.$stale->public_id.'/worksheet.xml');
    Storage::disk('local')->assertExists('export-tmp/'.$active->public_id.'/worksheet.xml');
});

it('preserves an export completed by another worker during retention cleanup', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    config(['exports.maximum_retained_per_workspace' => 2]);
    $previous = FileExport::factory()->count(3)->create([
        'user_id' => $actor->id, 'workspace_id' => $workspace->id, 'status' => 'ready',
    ]);
    $concurrent = FileExport::factory()->create([
        'user_id' => $actor->id, 'workspace_id' => $workspace->id, 'status' => 'processing',
    ]);
    Storage::disk('local')->put('exports/'.$concurrent->public_id.'/download.csv', 'complete');
    $completed = false;
    DB::listen(function (QueryExecuted $query) use ($concurrent, &$completed): void {
        if (! $completed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'file_exports')
            && in_array('ready', $query->bindings, true)) {
            $completed = true;
            $concurrent->update(['status' => 'ready']);
        }
    });

    app(QueuedFileExports::class)->trim($previous->last());

    expect($completed)->toBeTrue();
    $this->assertModelMissing($previous->first());
    $this->assertModelExists($previous->last());
    $this->assertModelExists($concurrent);
    Storage::disk('local')->assertExists('exports/'.$concurrent->public_id.'/download.csv');
});

it('cleans up and reports a file size limit without offering a partial download', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    Ingredient::factory()->create(['owner_type' => null, 'is_active' => true]);
    $this->actingAs($actor)->postJson(route('ingredients.export'), [
        'workspace' => $workspace->public_id, 'format' => 'csv',
    ])->assertStatus(202);
    $export = FileExport::query()->sole();
    config(['exports.maximum_bytes' => 1]);

    app()->call([new GenerateFileExport($export->id), 'handle']);

    $this->getJson(route('file-exports.status', $export))->assertJsonPath('status', 'failed')
        ->assertJsonPath('error', __('ingredients.exports.ui.too_large'))->assertJsonPath('download_url', null);
    $this->get(route('file-exports.download', $export))->assertNotFound();
    Storage::disk('local')->assertDirectoryEmpty('exports');
});

it('marks a terminated job failed and removes a partial stored file without exposing exception details', function (): void {
    $export = FileExport::factory()->create(['status' => 'processing']);
    Storage::disk('local')->put('exports/'.$export->public_id.'/download.csv', 'partial');

    (new GenerateFileExport($export->id))->failed(new RuntimeException('secret connection details'));

    expect($export->fresh()->status)->toBe('failed')
        ->and($export->fresh()->error)->toBe(__('ingredients.exports.ui.background_failed'));
    Storage::disk('local')->assertDirectoryEmpty('exports');
});

it('prunes orphaned files left by a deleted requester or workspace', function (): void {
    Storage::disk('local')->put('exports/deleted-export/download.csv', 'private');
    Storage::disk('local')->put('export-tmp/deleted-export/partial.csv', 'partial');

    $this->artisan('exports:prune')->assertExitCode(0);

    Storage::disk('local')->assertDirectoryEmpty('exports');
    Storage::disk('local')->assertDirectoryEmpty('export-tmp');
});
