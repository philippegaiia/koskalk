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

it('installs all English drafts and never replaces owner edits on repeat installation', function (string $filename, int $count) {
    SupportedLocale::factory()->create(['code' => 'en']);
    $manifest = app(HelpContentManifest::class)->decode(file_get_contents(database_path('seeders/data/'.$filename)));
    expect($manifest['topics'])->toHaveCount($count);
    expect(array_diff(array_column($manifest['topics'], 'key'), array_keys(app(HelpTopicRegistry::class)->definitions())))->toBe([]);
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::Bootstrap);
    $importer->apply($manifest, HelpContentImportMode::Bootstrap, $preview['selection'], $preview['manifest_hash']);
    expect(HelpTopic::count())->toBe($count)->and(HelpTopicRevision::count())->toBe($count)
        ->and(HelpTopicLocale::whereNotNull('published_revision_id')->count())->toBe(0);
    $locale = HelpTopicLocale::query()->first();
    $draft = app(SaveHelpTopicDraft::class)->handle(User::factory()->admin()->create(), $locale, new HelpContentInput('Owner wording', 'My reviewed explanation.', null), $locale->lock_version);
    $preview = $importer->preview($manifest, HelpContentImportMode::Bootstrap);
    $importer->apply($manifest, HelpContentImportMode::Bootstrap, $preview['selection'], $preview['manifest_hash']);
    expect($locale->fresh()->latest_revision_id)->toBe($draft->id)->and(HelpTopicRevision::count())->toBe($count + 1);
})->with([
    ['contextual-help.en.json', 32],
    ['contextual-help.inventory.en.json', 10],
    ['contextual-help.purchasing.en.json', 11],
    ['contextual-help.production.en.json', 13],
    ['contextual-help.materials.en.json', 13],
]);
