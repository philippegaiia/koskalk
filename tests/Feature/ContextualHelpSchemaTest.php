<?php

use App\Enums\HelpContentExportReason;
use App\Enums\HelpContentExportStatus;
use App\Enums\HelpContentOrigin;
use App\Enums\HelpTopicDomain;
use App\Enums\HelpTranslationRequestStatus;
use App\Models\HelpContentExport;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates English and translated revisions with typed relationships and public identifiers', function (): void {
    $translation = HelpTopicRevision::factory()->translated()->create();
    $english = $translation->sourceEnglishRevision;
    $locale = $translation->topicLocale;
    $request = HelpTranslationRequest::factory()->create();
    $export = HelpContentExport::factory()->create();

    expect($english->topicLocale->locale)->toBe('en');
    expect($english->topicLocale->help_topic_id)->toBe($locale->help_topic_id);
    expect($locale->topic->locales)->toHaveCount(2);
    expect($locale->revisions->first()->is($translation))->toBeTrue();
    expect($locale->supportedLocale->code)->toBe('fr');
    expect($locale->topic->domain)->toBe(HelpTopicDomain::SharedWorkbench);
    expect($translation->origin)->toBe(HelpContentOrigin::Human);
    expect($request->status)->toBe(HelpTranslationRequestStatus::Pending);
    expect($export->status)->toBe(HelpContentExportStatus::Pending);
    expect($export->reason)->toBe(HelpContentExportReason::Manual);
    foreach ([$locale->topic, $translation, $request, $export] as $model) {
        expect(Str::isUuid($model->getRouteKey()))->toBeTrue();
    }
});

it('rejects duplicate topic keys', function (): void {
    $topic = HelpTopic::factory()->create();

    expect(fn () => HelpTopic::factory()->create(['key' => $topic->key]))->toThrow(QueryException::class);
});

it('rejects duplicate topic locales', function (): void {
    $locale = HelpTopicLocale::factory()->create();

    expect(fn () => HelpTopicLocale::factory()->create(['help_topic_id' => $locale->help_topic_id, 'locale' => $locale->locale]))->toThrow(QueryException::class);
});

it('rejects duplicate or nonpositive revision numbers', function (int $number): void {
    $revision = HelpTopicRevision::factory()->create();

    expect(fn () => HelpTopicRevision::factory()->create(['help_topic_locale_id' => $revision->help_topic_locale_id, 'revision_number' => $number]))->toThrow(QueryException::class);
})->with([0, -1, 1]);

it('rejects heads that belong to another locale', function (string $head): void {
    $revision = HelpTopicRevision::factory()->create();
    $foreign = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $revision->topicLocale->help_topic_id]);

    expect(fn () => DB::table('help_topic_locales')->where('id', $foreign->id)->update([$head => $revision->id]))->toThrow(QueryException::class);
})->with(['latest_revision_id', 'published_revision_id']);

it('accepts owned heads and resolves their revisions', function (): void {
    $revision = HelpTopicRevision::factory()->create();
    $locale = $revision->topicLocale;
    $locale->update(['latest_revision_id' => $revision->id, 'published_revision_id' => $revision->id]);

    expect($locale->fresh()->latestRevision->is($revision))->toBeTrue();
    expect($locale->fresh()->publishedRevision->is($revision))->toBeTrue();
});

it('rejects translation sources from another topic or a non-English locale', function (bool $foreignTopic): void {
    $source = $foreignTopic ? HelpTopicRevision::factory()->create() : HelpTopicRevision::factory()->translated()->create();
    $target = HelpTopicLocale::factory()->translated('de')->create(['help_topic_id' => $source->topicLocale->help_topic_id]);
    if ($foreignTopic) {
        $target = HelpTopicLocale::factory()->translated()->create();
    }

    expect(fn () => HelpTopicRevision::factory()->create(['help_topic_locale_id' => $target->id, 'source_english_revision_id' => $source->id]))->toThrow(QueryException::class);
})->with([true, false]);

it('rejects missing translation sources', function (): void {
    $locale = HelpTopicLocale::factory()->translated()->create();

    expect(fn () => HelpTopicRevision::factory()->create(['help_topic_locale_id' => $locale->id, 'source_english_revision_id' => null]))->toThrow(QueryException::class);
});

it('rejects sources attached to English revisions', function (): void {
    $source = HelpTopicRevision::factory()->create();

    expect(fn () => HelpTopicRevision::factory()->create(['help_topic_locale_id' => $source->help_topic_locale_id, 'source_english_revision_id' => $source->id]))->toThrow(QueryException::class);
});

it('rejects updates to immutable revision content and provenance', function (string $column, mixed $value): void {
    $revision = HelpTopicRevision::factory()->create();

    expect(fn () => DB::table('help_topic_revisions')->where('id', $revision->id)->update([$column => $value]))->toThrow(QueryException::class);
})->with([
    ['title', 'Changed'], ['summary', 'Changed'], ['body_markdown', null], ['revision_number', 2],
    ['origin', 'ai'], ['ai_model', 'other'], ['prompt_version', 'v2'], ['public_id', 'changed'],
]);

it('rejects direct deletion of revisions', function (): void {
    $revision = HelpTopicRevision::factory()->create();

    expect(fn () => DB::table('help_topic_revisions')->where('id', $revision->id)->delete())->toThrow(QueryException::class);
});

it('preserves help content and attribution references when an author is deleted', function (): void {
    $author = User::factory()->create();
    $revision = HelpTopicRevision::factory()->create(['created_by' => $author->id]);
    $revision->topicLocale->update(['latest_revision_id' => $revision->id, 'published_revision_id' => $revision->id, 'published_by' => $author->id]);
    $request = HelpTranslationRequest::factory()->create(['requested_by' => $author->id]);
    $export = HelpContentExport::factory()->create(['requested_by' => $author->id]);

    $author->delete();

    expect($revision->fresh()->created_by)->toBeNull();
    expect($revision->fresh()->title)->toBe($revision->title);
    expect($revision->topicLocale->fresh()->published_by)->toBeNull();
    expect($request->fresh()->requested_by)->toBeNull();
    expect($export->fresh()->requested_by)->toBeNull();
});

it('rejects changing the identity of a topic or locale with history', function (string $table, string $column, mixed $value): void {
    $revision = HelpTopicRevision::factory()->create();
    $id = $table === 'help_topics' ? $revision->topicLocale->help_topic_id : $revision->help_topic_locale_id;

    expect(fn () => DB::table($table)->where('id', $id)->update([$column => $value]))->toThrow(QueryException::class);
})->with([
    ['help_topics', 'key', 'changed'], ['help_topics', 'domain', 'soap_workbench'], ['help_topic_locales', 'locale', 'fr'],
]);

it('rejects deleting topics through the model so they must be archived', function (): void {
    $topic = HelpTopic::factory()->create();

    expect(fn () => $topic->delete())->toThrow(DomainException::class);
});

it('rejects a request with an unrelated English source', function (): void {
    $source = HelpTopicRevision::factory()->create();

    expect(fn () => HelpTranslationRequest::factory()->create(['source_english_revision_id' => $source->id]))->toThrow(QueryException::class);
});

it('rejects changing request identity or model configuration after insertion', function (string $column, mixed $value): void {
    $request = HelpTranslationRequest::factory()->create();

    expect(fn () => DB::table('help_translation_requests')->where('id', $request->id)->update([$column => $value]))->toThrow(QueryException::class);
})->with([
    ['expected_target_lock_version', 99], ['model', 'changed'], ['prompt_version', 'changed'], ['reasoning_effort', 'high'],
]);

it('rejects accepted revisions from another target locale', function (): void {
    $request = HelpTranslationRequest::factory()->create();
    $revision = HelpTopicRevision::factory()->translated()->create();

    expect(fn () => $request->update(['accepted_revision_id' => $revision->id]))->toThrow(QueryException::class);
});

it('reverses and reapplies the help migration with integrity guards intact', function (): void {
    $translation = HelpTopicRevision::factory()->translated()->create();
    $translation->topicLocale->update(['latest_revision_id' => $translation->id, 'published_revision_id' => $translation->id]);
    $migration = require database_path('migrations/2026_09_22_072454_create_contextual_help_tables.php');
    $migration->down();
    expect(Schema::hasTable('help_topics'))->toBeFalse();
    $migration->up();
    $revision = HelpTopicRevision::factory()->create();

    expect(fn () => DB::table('help_topic_revisions')->where('id', $revision->id)->update(['title' => 'Changed']))->toThrow(QueryException::class);
});

it('rejects negative help counters and nonpositive export format versions', function (string $modelClass, string $column, int $value): void {
    expect(fn () => $modelClass::factory()->create([$column => $value]))->toThrow(QueryException::class);
})->with([
    [HelpTopicLocale::class, 'lock_version', -1],
    [HelpTranslationRequest::class, 'expected_target_lock_version', -1],
    [HelpTranslationRequest::class, 'input_tokens', -1],
    [HelpTranslationRequest::class, 'output_tokens', -1],
    [HelpContentExport::class, 'format_version', 0],
    [HelpContentExport::class, 'size_bytes', -1],
]);

it('rejects invalid help counter updates', function (string $modelClass, string $column, int $value): void {
    $model = $modelClass::factory()->create();

    expect(fn () => $model->update([$column => $value]))->toThrow(QueryException::class);
})->with([
    [HelpTopicLocale::class, 'lock_version', -1],
    [HelpTranslationRequest::class, 'input_tokens', -1],
    [HelpTranslationRequest::class, 'output_tokens', -1],
    [HelpContentExport::class, 'format_version', 0],
    [HelpContentExport::class, 'size_bytes', -1],
]);

it('preserves translation and export audit records against model deletion', function (string $modelClass): void {
    $model = $modelClass::factory()->create();

    expect(fn () => $model->delete())->toThrow(DomainException::class);
})->with([[HelpTranslationRequest::class], [HelpContentExport::class]]);

it('rejects translation requests targeting English', function (): void {
    $source = HelpTopicRevision::factory()->create();

    expect(fn () => HelpTranslationRequest::factory()->create([
        'help_topic_locale_id' => $source->help_topic_locale_id,
        'source_english_revision_id' => $source->id,
    ]))->toThrow(QueryException::class);
});

it('rejects translation requests using a non-English source', function (): void {
    $source = HelpTopicRevision::factory()->translated()->create();
    $target = HelpTopicLocale::factory()->translated('de')->create(['help_topic_id' => $source->topicLocale->help_topic_id]);

    expect(fn () => HelpTranslationRequest::factory()->create([
        'help_topic_locale_id' => $target->id,
        'source_english_revision_id' => $source->id,
    ]))->toThrow(QueryException::class);
});
