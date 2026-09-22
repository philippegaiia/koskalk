<?php

use App\Actions\ContextualHelp\SaveHelpTopicDraft;
use App\Data\HelpContentInput;
use App\Enums\HelpContentImportMode;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\ContextualHelp\HelpContentImporter;
use App\Services\ContextualHelp\HelpContentManifest;
use App\Services\ContextualHelp\HelpTopicRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('installs all English drafts and never replaces owner edits on repeat installation', function () {
    SupportedLocale::factory()->create(['code' => 'en']);
    $manifest = app(HelpContentManifest::class)->decode(file_get_contents(database_path('seeders/data/contextual-help.en.json')));
    expect($manifest['topics'])->toHaveCount(32)
        ->and(array_diff(array_keys(app(HelpTopicRegistry::class)->definitions()), array_column($manifest['topics'], 'key')))->toBe([]);
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::Bootstrap);
    $importer->apply($manifest, HelpContentImportMode::Bootstrap, $preview['selection'], $preview['manifest_hash']);
    expect(HelpTopic::count())->toBe(32)->and(HelpTopicRevision::count())->toBe(32)
        ->and(HelpTopicLocale::whereNotNull('published_revision_id')->count())->toBe(0);
    $locale = HelpTopicLocale::query()->first();
    $draft = app(SaveHelpTopicDraft::class)->handle(User::factory()->admin()->create(), $locale, new HelpContentInput('Owner wording', 'My reviewed explanation.', null), $locale->lock_version);
    $preview = $importer->preview($manifest, HelpContentImportMode::Bootstrap);
    $importer->apply($manifest, HelpContentImportMode::Bootstrap, $preview['selection'], $preview['manifest_hash']);
    expect($locale->fresh()->latest_revision_id)->toBe($draft->id)->and(HelpTopicRevision::count())->toBe(33);
});
