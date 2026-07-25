<?php

use App\MediaAssetStatus;
use App\Models\MediaAsset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fails stale processing assets while leaving recent processing assets alone', function () {
    $stale = MediaAsset::factory()->create([
        'status' => MediaAssetStatus::Processing,
        'progress' => 5,
        'processing_stage' => 'validating',
        'pending_disk' => 'local',
        'pending_path' => 'media-assets/pending/stale.png',
        'updated_at' => now()->subMinutes(16),
    ]);
    $recent = MediaAsset::factory()->create([
        'status' => MediaAssetStatus::Processing,
        'updated_at' => now()->subMinutes(4),
    ]);

    $this->artisan('media:fail-stale-assets')
        ->expectsOutputToContain('1 stale media asset marked failed')
        ->assertSuccessful();

    expect($stale->refresh())
        ->status->toBe(MediaAssetStatus::Failed)
        ->failure_code->toBe('processing_timeout')
        ->processing_stage->toBeNull()
        ->progress->toBe(0)
        ->pending_disk->toBe('local')
        ->pending_path->toBe('media-assets/pending/stale.png')
        ->and($recent->refresh()->status)->toBe(MediaAssetStatus::Processing);
});

it('rejects an unsafe stale age shorter than the media job timeout window', function () {
    $this->artisan('media:fail-stale-assets', ['--age' => 5])
        ->expectsOutputToContain('must be at least 6 minutes')
        ->assertFailed();
});

it('schedules stale media recovery every five minutes without overlap', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'media:fail-stale-assets'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
