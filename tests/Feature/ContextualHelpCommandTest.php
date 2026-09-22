<?php

use App\Models\HelpContentExport;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Services\ContextualHelp\HelpContentManifest;
use App\Services\ContextualHelp\HelpContentSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('registers identities idempotently without inventing content', function (): void {
    $this->artisan('help:register')->assertSuccessful();
    expect(HelpTopic::query()->count())->toBe(66);
    expect(HelpTopicLocale::query()->count())->toBe(0);
    $this->artisan('help:register')->assertSuccessful();
    expect(HelpTopic::query()->count())->toBe(66);
});

it('exports private JSON to a new local path and refuses an overwrite', function (): void {
    $path = sys_get_temp_dir().'/help-'.Str::uuid().'.json';
    try {
        $this->artisan('help:export', ['--output' => $path])->assertSuccessful();
        expect(app(HelpContentManifest::class)->decode(file_get_contents($path))['topics'])->toBe([]);
        expect(fileperms($path) & 0777)->toBe(0600);
        $this->artisan('help:export', ['--output' => $path])->assertFailed();
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('previews imports by default and requires force for production writes', function (): void {
    $topic = HelpTopic::factory()->create(['key' => 'shared.formula_basics']);
    $locale = HelpTopicLocale::factory()->create(['help_topic_id' => $topic->id]);
    $revision = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $locale->id]);
    $locale->update(['latest_revision_id' => $revision->id]);
    $manifest = app(HelpContentSnapshot::class)->capture();
    $path = sys_get_temp_dir().'/help-'.Str::uuid().'.json';
    file_put_contents($path, app(HelpContentManifest::class)->encode($manifest));
    try {
        $this->artisan('help:import', ['path' => $path])->expectsOutputToContain('Preview only')->assertSuccessful();
        expect($locale->fresh()->lock_version)->toBe(0);
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->artisan('help:import', ['path' => $path, '--apply' => true])->expectsOutputToContain('require --force')->assertFailed();
        $this->artisan('help:import', ['path' => $path, '--apply' => true, '--force' => true])->assertSuccessful();
        $this->artisan('help:import', ['path' => $path, '--mode' => 'invalid'])->assertFailed();
    } finally {
        unlink($path);
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
});

it('runs a verified scheduled snapshot and exports to the explicitly selected disk', function (): void {
    Storage::fake('r2_backups');
    Storage::fake('help-test');
    $this->artisan('help:snapshot')->assertSuccessful();
    expect(HelpContentExport::query()->where('reason', 'scheduled')->where('status', 'succeeded')->exists())->toBeTrue();
    $this->artisan('help:export', ['--disk' => 'help-test'])->assertSuccessful();
    expect(Storage::disk('help-test')->allFiles())->toHaveCount(1);
});
