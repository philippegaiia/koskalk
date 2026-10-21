<?php

use App\Actions\ContextualHelp\RequestHelpTranslation;
use App\Actions\ContextualHelp\RunHelpTopicBulkAction;
use App\Contracts\HelpTranslationClient;
use App\Data\HelpTranslationResponse;
use App\Enums\HelpContentImportMode;
use App\Enums\HelpTopicDomain;
use App\Enums\HelpTranslationRequestStatus as Status;
use App\Filament\Resources\HelpTopics\Pages\ListHelpTopics;
use App\Jobs\TranslateHelpTopic;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\ContextualHelp\HelpContentImporter;
use App\Services\ContextualHelp\HelpContentManifest;
use App\Services\ContextualHelp\HelpContentSnapshot;
use App\Services\ContextualHelp\HelpTopicBulkOperations;
use App\Services\ContextualHelp\HelpTranslationService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function bulkHelpEnglish(string $key = 'soap.superfat', bool $published = false): HelpTopicLocale
{
    $english = HelpTopicLocale::factory()->for(HelpTopic::factory()->create(['key' => $key, 'domain' => HelpTopicDomain::SoapWorkbench]), 'topic')->create();
    $revision = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id]);
    $english->update(['latest_revision_id' => $revision->id, 'published_revision_id' => $published ? $revision->id : null]);

    return $english;
}

it('publishes only selected English drafts through the topic checkboxes', function (): void {
    Queue::fake();
    $first = bulkHelpEnglish();
    $second = bulkHelpEnglish('soap.water_mode');
    $excluded = bulkHelpEnglish('soap.output_basis');
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListHelpTopics::class)->loadTable()
        ->selectTableRecords([$first->help_topic_id, $second->help_topic_id])
        ->callAction(TestAction::make('publishSelected')->table()->bulk())
        ->assertHasNoFormErrors()->assertNotified();

    expect($first->fresh()->published_revision_id)->toBe($first->latest_revision_id)
        ->and($second->fresh()->published_revision_id)->toBe($second->latest_revision_id)
        ->and($excluded->fresh()->published_revision_id)->toBeNull();
});

it('does not publish an English revision changed after bulk confirmation opened', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish();
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test(ListHelpTopics::class)->loadTable()
        ->selectTableRecords([$english->help_topic_id])
        ->mountAction(TestAction::make('publishSelected')->table()->bulk());
    $changed = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id, 'revision_number' => 2]);
    $english->update(['latest_revision_id' => $changed->id, 'lock_version' => 1]);

    $page->callMountedAction()->assertHasNoFormErrors();

    expect($english->fresh()->published_revision_id)->toBeNull();
});

it('queues selected languages with the chosen publication mode and defaults to manual review', function (bool $review): void {
    Queue::fake();
    $english = bulkHelpEnglish();
    SupportedLocale::factory()->create(['code' => 'fr', 'is_active' => true]);
    SupportedLocale::factory()->create(['code' => 'es', 'is_active' => true]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListHelpTopics::class)->loadTable()
        ->selectTableRecords([$english->help_topic_id])
        ->callAction(TestAction::make('translateSelected')->table()->bulk(), $review ? ['locales' => ['fr']] : ['locales' => ['fr'], 'review_before_publishing' => false])
        ->assertHasNoFormErrors()->assertNotified();

    $request = HelpTranslationRequest::query()->sole();
    expect($english->fresh()->published_revision_id)->toBe($english->latest_revision_id)
        ->and($request->topicLocale->locale)->toBe('fr')
        ->and($request->publish_automatically)->toBe(! $review)
        ->and($request->source_english_revision_id)->toBe($english->latest_revision_id);
    Queue::assertPushed(TranslateHelpTopic::class, 1);
})->with(['default manual review' => true, 'explicit automatic publication' => false]);

it('preserves existing manual drafts and current translations when bulk queuing', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish(published: true);
    $manual = HelpTopicLocale::factory()->translated('fr')->create(['help_topic_id' => $english->help_topic_id]);
    $draft = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $manual->id, 'source_english_revision_id' => $english->latest_revision_id]);
    $manual->update(['latest_revision_id' => $draft->id]);
    $current = HelpTopicLocale::factory()->translated('es')->create(['help_topic_id' => $english->help_topic_id]);
    $live = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $current->id, 'source_english_revision_id' => $english->latest_revision_id]);
    $current->update(['latest_revision_id' => $live->id, 'published_revision_id' => $live->id]);
    $actor = User::factory()->admin()->create();
    $snapshot = app(HelpTopicBulkOperations::class)->snapshot(collect([$english->topic]));

    $result = app(RunHelpTopicBulkAction::class)->handle($actor, $snapshot, ['fr', 'es'], false);

    expect($result['queued'])->toBe(0)
        ->and($manual->fresh()->latest_revision_id)->toBe($draft->id)
        ->and($current->fresh()->published_revision_id)->toBe($live->id);
    Queue::assertNothingPushed();
});

it('rejects bulk help operations for non-administrators', function (): void {
    $english = bulkHelpEnglish();
    $snapshot = app(HelpTopicBulkOperations::class)->snapshot(collect([$english->topic]));

    expect(fn () => app(RunHelpTopicBulkAction::class)->handle(User::factory()->create(), $snapshot))
        ->toThrow(AuthorizationException::class);
    expect($english->fresh()->published_revision_id)->toBeNull();
});

it('automatically publishes a successful translation only when explicitly selected', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish(published: true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    $actor = User::factory()->admin()->create();
    $request = app(RequestHelpTranslation::class)->handle($actor, $target, $english->latest_revision_id, 0, publishAutomatically: true);
    $this->mock(HelpTranslationClient::class)->shouldReceive('translate')->once()
        ->andReturn(new HelpTranslationResponse(['title' => 'Aide', 'summary' => 'Texte traduit.', 'body_markdown' => null], inputTokens: 12, outputTokens: 20));

    app(HelpTranslationService::class)->execute($request);
    app(HelpTranslationService::class)->execute($request->fresh());

    expect($request->fresh()->status)->toBe(Status::Accepted)
        ->and($target->fresh()->published_revision_id)->toBe($target->fresh()->latest_revision_id)
        ->and($target->fresh()->publishedRevision->title)->toBe('Aide')
        ->and($target->revisions()->count())->toBe(1)
        ->and($request->fresh()->output_tokens)->toBe(20);
});

it('keeps an automatic translation as a candidate when its source target or administrator changes during generation', function (string $changed): void {
    Queue::fake();
    $english = bulkHelpEnglish(published: true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    $actor = User::factory()->admin()->create();
    $request = app(RequestHelpTranslation::class)->handle($actor, $target, $english->latest_revision_id, 0, publishAutomatically: true);
    $this->mock(HelpTranslationClient::class)->shouldReceive('translate')->once()->andReturnUsing(function () use ($changed, $english, $target, $actor): HelpTranslationResponse {
        if (in_array($changed, ['source', 'source_draft'], true)) {
            $next = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id, 'revision_number' => 2]);
            $english->update(['latest_revision_id' => $next->id, 'published_revision_id' => $changed === 'source' ? $next->id : $english->published_revision_id, 'lock_version' => 1]);
        } elseif ($changed === 'target') {
            $target->update(['lock_version' => 1]);
        } else {
            $actor->update(['is_admin' => false]);
        }

        return new HelpTranslationResponse(['title' => 'Aide', 'summary' => 'Candidat conservé.', 'body_markdown' => null], outputTokens: 10);
    });

    app(HelpTranslationService::class)->execute($request);
    app(HelpTranslationService::class)->recover();
    app(HelpTranslationService::class)->recover();

    expect($target->fresh()->published_revision_id)->toBeNull()
        ->and($target->revisions()->count())->toBe(0)
        ->and($request->fresh()->status)->toBe(Status::Completed)
        ->and($request->fresh()->error_code)->toBe('automatic_publication_blocked')
        ->and($request->fresh()->result['title'])->toBe('Aide')
        ->and($request->fresh()->output_tokens)->toBe(10);
})->with(['source', 'source_draft', 'target', 'actor']);

it('never upgrades an existing manual request to automatic publication', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish(published: true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    $actor = User::factory()->admin()->create();
    $request = app(RequestHelpTranslation::class)->handle($actor, $target, $english->latest_revision_id, 0);

    $again = app(RequestHelpTranslation::class)->handle($actor, $target, $english->latest_revision_id, 0, publishAutomatically: true);

    expect($again->id)->toBe($request->id)
        ->and($again->publish_automatically)->toBeFalse();
    Queue::assertPushed(TranslateHelpTopic::class, 1);
});

it('validates target languages before publishing any selected English drafts', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListHelpTopics::class)->loadTable()
        ->selectTableRecords([$english->help_topic_id])
        ->callAction(TestAction::make('translateSelected')->table()->bulk(), ['locales' => ['en']])
        ->assertHasFormErrors();

    expect($english->fresh()->published_revision_id)->toBeNull()
        ->and(HelpTranslationRequest::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not publish an invalid automatically requested translation', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish(published: true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    $request = app(RequestHelpTranslation::class)->handle(User::factory()->admin()->create(), $target, $english->latest_revision_id, 0, publishAutomatically: true);
    $this->mock(HelpTranslationClient::class)->shouldReceive('translate')->once()
        ->andReturn(new HelpTranslationResponse(null, errorCode: 'provider_connection_failed'));

    app(HelpTranslationService::class)->execute($request);

    expect($request->fresh()->status)->toBe(Status::Failed)
        ->and($target->fresh()->latest_revision_id)->toBeNull()
        ->and($target->fresh()->published_revision_id)->toBeNull();
});

it('retains the automatic publication choice when a pending job is recovered', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish(published: true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    $request = app(RequestHelpTranslation::class)->handle(User::factory()->admin()->create(), $target, $english->latest_revision_id, 0, publishAutomatically: true);
    $this->mock(HelpTranslationClient::class)->shouldReceive('translate')->once()
        ->andReturn(new HelpTranslationResponse(['title' => 'Aide', 'summary' => 'Texte traduit.', 'body_markdown' => null]));

    app(HelpTranslationService::class)->recover();
    (new TranslateHelpTopic($request->id))->handle(app(HelpTranslationService::class));

    expect($target->fresh()->published_revision_id)->not->toBeNull();
    Queue::assertPushed(TranslateHelpTopic::class, 2);
});

it('prevents changing publication authorization on an existing request', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish(published: true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    $request = app(RequestHelpTranslation::class)->handle(User::factory()->admin()->create(), $target, $english->latest_revision_id, 0);

    expect(fn () => $request->update(['publish_automatically' => true]))->toThrow(QueryException::class);
});

it('exports publication intent while accepting older packages that omit it', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish(published: true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    app(RequestHelpTranslation::class)->handle(User::factory()->admin()->create(), $target, $english->latest_revision_id, 0, publishAutomatically: true);
    $manifest = app(HelpContentSnapshot::class)->capture();
    $localeIndex = array_search('fr', array_column($manifest['topics'][0]['locales'], 'locale'), true);

    expect($manifest['topics'][0]['locales'][$localeIndex]['translation_requests'][0]['publish_automatically'])->toBeTrue();
    unset($manifest['topics'][0]['locales'][$localeIndex]['translation_requests'][0]['publish_automatically']);
    $manifests = app(HelpContentManifest::class);
    $legacy = $manifests->seal($manifest);

    expect($manifests->validate($legacy))->toBe($legacy);
});

it('skips an existing translation job even when its English source is older', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish(published: true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    $actor = User::factory()->admin()->create();
    $request = app(RequestHelpTranslation::class)->handle($actor, $target, $english->latest_revision_id, 0);
    $next = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id, 'revision_number' => 2]);
    $english->update(['latest_revision_id' => $next->id, 'published_revision_id' => $next->id, 'lock_version' => 1]);
    $snapshot = app(HelpTopicBulkOperations::class)->snapshot(collect([$english->topic]));

    $result = app(RunHelpTopicBulkAction::class)->handle($actor, $snapshot, ['fr'], false);

    expect($result['queued'])->toBe(0)
        ->and(HelpTranslationRequest::query()->count())->toBe(1)
        ->and($request->fresh()->publish_automatically)->toBeFalse();
    Queue::assertPushed(TranslateHelpTopic::class, 1);
});

it('imports automatic request history without executing or publishing it', function (bool $completed): void {
    Queue::fake();
    Storage::fake('local');
    config(['contextual-help.disk' => 'local']);
    $english = bulkHelpEnglish(published: true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    app(RequestHelpTranslation::class)->handle(User::factory()->admin()->create(), $target, $english->latest_revision_id, 0, publishAutomatically: true);
    $manifest = app(HelpContentSnapshot::class)->capture();
    $index = array_search('fr', array_column($manifest['topics'][0]['locales'], 'locale'), true);
    $uuid = (string) Str::uuid();
    $manifest['topics'][0]['locales'][$index]['translation_requests'][0]['public_id'] = $uuid;
    if ($completed) {
        $manifest['topics'][0]['locales'][$index]['translation_requests'][0]['status'] = Status::Completed->value;
        $manifest['topics'][0]['locales'][$index]['translation_requests'][0]['result'] = ['title' => 'Aide', 'summary' => 'Historique.', 'body_markdown' => null];
    }
    $manifest = app(HelpContentManifest::class)->seal($manifest);
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::MergeDrafts);

    $importer->apply($manifest, HelpContentImportMode::MergeDrafts, $preview['selection'], $preview['manifest_hash']);

    $this->mock(HelpTranslationClient::class)->shouldNotReceive('translate');
    app(HelpTranslationService::class)->recover();
    $restored = HelpTranslationRequest::query()->where('public_id', $uuid)->sole();
    expect($restored->publish_automatically)->toBeTrue()
        ->and($restored->status)->toBe($completed ? Status::Completed : Status::Failed)
        ->and($restored->requested_by)->toBeNull()
        ->and($target->fresh()->published_revision_id)->toBeNull();
    Queue::assertPushed(TranslateHelpTopic::class, 2);
})->with(['pending history' => false, 'completed history' => true]);

it('recovers completed automatic candidates without repeating translation or publication', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish(published: true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    $request = app(RequestHelpTranslation::class)->handle(User::factory()->admin()->create(), $target, $english->latest_revision_id, 0, publishAutomatically: true);
    $request->update(['status' => Status::Completed, 'result' => ['title' => 'Aide', 'summary' => 'Candidat sauvegardé.', 'body_markdown' => null], 'output_tokens' => 20, 'completed_at' => now()]);
    $this->mock(HelpTranslationClient::class)->shouldNotReceive('translate');

    app(HelpTranslationService::class)->recover();
    app(HelpTranslationService::class)->recover();

    expect($request->fresh()->status)->toBe(Status::Accepted)
        ->and($request->fresh()->output_tokens)->toBe(20)
        ->and($target->fresh()->publishedRevision->title)->toBe('Aide')
        ->and($target->revisions()->count())->toBe(1);
});

it('rejects an oversized topic-language batch before publishing or queuing anything', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish();
    foreach (['fr', 'es', 'de', 'it', 'nl', 'pt_BR', 'pl'] as $code) {
        SupportedLocale::factory()->create(['code' => $code, 'is_active' => true]);
    }
    $selection = collect(range(1, 100))->map(fn (int $id): array => ['topic_id' => $id, 'revision_id' => $english->latest_revision_id, 'lock_version' => 0, 'locale_locks' => []])->all();

    expect(fn () => app(RunHelpTopicBulkAction::class)->handle(User::factory()->admin()->create(), $selection, ['fr', 'es', 'de', 'it', 'nl', 'pt_BR', 'pl']))
        ->toThrow(ValidationException::class);

    expect($english->fresh()->published_revision_id)->toBeNull();
    Queue::assertNothingPushed();
});

it('continues after a topic publication fails and rolls that topic back', function (): void {
    Queue::fake();
    $first = bulkHelpEnglish('soap.first');
    $failed = bulkHelpEnglish('soap.failed');
    $last = bulkHelpEnglish('soap.last');
    $snapshot = app(HelpTopicBulkOperations::class)->snapshot(collect([$first->topic, $failed->topic, $last->topic]));
    DB::unprepared("CREATE TRIGGER reject_one_help_publication BEFORE UPDATE ON help_topic_locales WHEN NEW.id = {$failed->id} BEGIN SELECT RAISE(ABORT, 'simulated topic failure'); END");

    $result = app(RunHelpTopicBulkAction::class)->handle(User::factory()->admin()->create(), $snapshot);

    expect($result)->toBe(['published' => 2, 'queued' => 0, 'skipped' => 1]);
    expect($first->fresh()->published_revision_id)->toBe($first->latest_revision_id);
    expect($failed->fresh()->published_revision_id)->toBeNull();
    expect($last->fresh()->published_revision_id)->toBe($last->latest_revision_id);
});

it('does not expose bulk execution helpers as browser-callable methods', function (string $method): void {
    Queue::fake();
    $english = bulkHelpEnglish();
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test(ListHelpTopics::class)->loadTable()
        ->selectTableRecords([$english->help_topic_id])
        ->mountAction(TestAction::make('publishSelected')->table()->bulk());
    $page->call('unmountAction');

    expect(fn () => $page->call($method))->toThrow(MethodNotFoundException::class);

    expect($english->fresh()->published_revision_id)->toBeNull();
    Queue::assertNothingPushed();
})->with(['captureHelpSelection', 'runHelpBulkAction']);

it('refuses a mounted bulk action after the administrator loses access', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish();
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $page = Livewire::test(ListHelpTopics::class)->loadTable()
        ->selectTableRecords([$english->help_topic_id])
        ->mountAction(TestAction::make('publishSelected')->table()->bulk());
    $actor->update(['is_admin' => false]);

    $page->callMountedAction()->assertForbidden();

    expect($english->fresh()->published_revision_id)->toBeNull();
    Queue::assertNothingPushed();
});

it('retries stored automatic candidates after a temporary publication database failure', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish(published: true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    $request = app(RequestHelpTranslation::class)->handle(User::factory()->admin()->create(), $target, $english->latest_revision_id, 0, publishAutomatically: true);
    $request->update(['status' => Status::Completed, 'result' => ['title' => 'Aide', 'summary' => 'Candidat sauvegardé.', 'body_markdown' => null], 'completed_at' => now()]);
    $this->mock(HelpTranslationClient::class)->shouldNotReceive('translate');
    DB::unprepared("CREATE TRIGGER temporary_help_failure BEFORE UPDATE ON help_topic_locales WHEN NEW.id = {$target->id} BEGIN SELECT RAISE(ABORT, 'database is locked'); END");

    app(HelpTranslationService::class)->recover();

    expect($request->fresh()->status)->toBe(Status::Completed);
    expect($request->fresh()->error_code)->toBeNull();
    expect($target->fresh()->published_revision_id)->toBeNull();
    DB::unprepared('DROP TRIGGER temporary_help_failure');

    app(HelpTranslationService::class)->recover();

    expect($request->fresh()->status)->toBe(Status::Accepted);
    expect($target->fresh()->published_revision_id)->not->toBeNull();
});

it('queues all 97 selected topics in all six translation languages and shows the request count', function (): void {
    Queue::fake();
    $topics = collect(range(1, 97))->map(fn (int $number): HelpTopic => bulkHelpEnglish('soap.topic_'.$number)->topic);
    $codes = ['fr', 'es', 'de', 'it', 'nl', 'pt_BR'];
    foreach ($codes as $code) {
        SupportedLocale::factory()->create(['code' => $code, 'is_active' => true]);
    }
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test(ListHelpTopics::class)->loadTable()->selectTableRecords($topics->pluck('id')->all())
        ->mountAction(TestAction::make('translateSelected')->table()->bulk())
        ->fillForm(['locales' => $codes]);
    expect(count($page->get('helpBulkSnapshot')))->toBe(97);
    $page->assertFormFieldExists('locales', fn (Select $field): bool => str_contains($field->getChildSchema(Select::BELOW_CONTENT_SCHEMA_KEY)->getComponents()[0]->getContent(), 'Up to 582 AI requests.'));

    $page->callMountedAction()->assertHasNoFormErrors()->assertNotified();

    expect(HelpTranslationRequest::query()->count())->toBe(582);
    expect(HelpTranslationRequest::query()->where('publish_automatically', true)->exists())->toBeFalse();
    Queue::assertPushed(TranslateHelpTopic::class, 582);
});

it('requires publication permission as well as translation permission in translate mode', function (): void {
    Queue::fake();
    $english = bulkHelpEnglish();
    SupportedLocale::factory()->create(['code' => 'fr', 'is_active' => true]);
    $snapshot = app(HelpTopicBulkOperations::class)->snapshot(collect([$english->topic]));
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'publish' ? false : null);

    expect(fn () => app(RunHelpTopicBulkAction::class)->handle(User::factory()->admin()->create(), $snapshot, ['fr']))
        ->toThrow(AuthorizationException::class);

    expect($english->fresh()->published_revision_id)->toBeNull();
    Queue::assertNothingPushed();
});
