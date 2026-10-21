<?php

use App\Actions\ContextualHelp\PublishHelpTranslationCandidates;
use App\Contracts\HelpTranslationClient;
use App\Enums\HelpTranslationRequestStatus as Status;
use App\Filament\Resources\HelpTopics\Pages\EditHelpTopic;
use App\Filament\Resources\HelpTopics\Pages\ListHelpTopics;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\ContextualHelp\HelpTopicBulkOperations;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function publicationHelpCandidate(): HelpTranslationRequest
{
    $english = HelpTopicLocale::factory()->create();
    $source = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id]);
    $english->update(['latest_revision_id' => $source->id, 'published_revision_id' => $source->id]);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);

    return HelpTranslationRequest::factory()->create([
        'help_topic_locale_id' => $target->id, 'source_english_revision_id' => $source->id,
        'status' => Status::Completed, 'result' => ['title' => 'Aide', 'summary' => 'Texte traduit.', 'body_markdown' => null],
        'model' => 'gpt-6.1-sol', 'reasoning_effort' => 'medium', 'response_model' => 'gpt-6.1-sol-2026-10-01',
    ]);
}

it('publishes selected completed translations without another AI request or individual review', function (): void {
    Queue::fake();
    $candidate = publicationHelpCandidate();
    $excluded = publicationHelpCandidate();
    $this->actingAs(User::factory()->admin()->create());
    $this->mock(HelpTranslationClient::class)->shouldNotReceive('translate');

    Livewire::test(ListHelpTopics::class)->loadTable()->selectTableRecords([$candidate->topicLocale->help_topic_id])
        ->callAction(TestAction::make('publishTranslations')->table()->bulk(), ['locales' => ['fr']])
        ->assertHasNoFormErrors()->assertNotified();

    expect($candidate->fresh()->status)->toBe(Status::Accepted);
    expect($candidate->topicLocale->fresh()->publishedRevision->title)->toBe('Aide');
    expect($candidate->fresh()->publish_automatically)->toBeFalse();
    expect($excluded->fresh()->status)->toBe(Status::Completed);
    expect($excluded->topicLocale->fresh()->published_revision_id)->toBeNull();
});

it('skips candidates if the source target or candidate changes after the dialog opens', function (string $changed): void {
    Queue::fake();
    $candidate = publicationHelpCandidate();
    $target = $candidate->topicLocale;
    $english = $candidate->sourceEnglishRevision->topicLocale;
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test(ListHelpTopics::class)->loadTable()->selectTableRecords([$target->help_topic_id])
        ->mountAction(TestAction::make('publishTranslations')->table()->bulk());
    if ($changed === 'source') {
        $source = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id, 'revision_number' => 2]);
        $english->update(['latest_revision_id' => $source->id, 'lock_version' => 1]);
    } elseif ($changed === 'target') {
        $draft = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $target->id, 'source_english_revision_id' => $candidate->source_english_revision_id]);
        $target->update(['latest_revision_id' => $draft->id, 'lock_version' => 1]);
    } else {
        HelpTranslationRequest::factory()->create(['help_topic_locale_id' => $target->id, 'source_english_revision_id' => $candidate->source_english_revision_id, 'status' => Status::Completed]);
    }

    $page->fillForm(['locales' => ['fr']])->callMountedAction()->assertHasNoFormErrors();

    expect($target->fresh()->published_revision_id)->toBeNull();
    expect($candidate->fresh()->status)->toBe(Status::Completed);
})->with(['source', 'target', 'candidate']);

it('does not replace a manual translation draft or publish an outdated source', function (bool $manual): void {
    Queue::fake();
    $candidate = publicationHelpCandidate();
    $target = $candidate->topicLocale;
    if ($manual) {
        $draft = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $target->id, 'source_english_revision_id' => $candidate->source_english_revision_id]);
        $target->update(['latest_revision_id' => $draft->id]);
    } else {
        $english = $candidate->sourceEnglishRevision->topicLocale;
        $source = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id, 'revision_number' => 2]);
        $english->update(['latest_revision_id' => $source->id, 'published_revision_id' => $source->id]);
    }
    $snapshot = app(HelpTopicBulkOperations::class)->snapshot(collect([$target->topic]));

    $result = app(PublishHelpTranslationCandidates::class)->handle(User::factory()->admin()->create(), $snapshot, ['fr']);

    expect($result)->toBe(['published' => 0, 'skipped' => 1]);
    expect($target->fresh()->published_revision_id)->toBeNull();
})->with(['manual draft' => true, 'outdated English' => false]);

it('is idempotent when publishing saved candidates twice', function (): void {
    Queue::fake();
    $candidate = publicationHelpCandidate();
    $actor = User::factory()->admin()->create();
    $snapshot = app(HelpTopicBulkOperations::class)->snapshot(collect([$candidate->topicLocale->topic]));
    $publisher = app(PublishHelpTranslationCandidates::class);

    expect($publisher->handle($actor, $snapshot, ['fr']))->toBe(['published' => 1, 'skipped' => 0]);
    expect($publisher->handle($actor, $snapshot, ['fr']))->toBe(['published' => 0, 'skipped' => 1]);
    expect($candidate->topicLocale->revisions()->count())->toBe(1);
});

it('refuses ordinary users when bulk publishing translation candidates', function (): void {
    $candidate = publicationHelpCandidate();
    $snapshot = app(HelpTopicBulkOperations::class)->snapshot(collect([$candidate->topicLocale->topic]));

    expect(fn () => app(PublishHelpTranslationCandidates::class)->handle(User::factory()->create(), $snapshot, ['fr']))
        ->toThrow(AuthorizationException::class);
});

it('shows the saved model and reasoning independently of later configuration changes', function (): void {
    $candidate = publicationHelpCandidate();
    config(['contextual-help.translation.model' => 'gpt-6-luna', 'contextual-help.translation.reasoning_effort' => 'max']);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditHelpTopic::class, ['record' => $candidate->topicLocale->topic->public_id])
        ->callAction('language', data: ['locale' => 'fr'])
        ->assertSee('Requested model: gpt-6.1-sol')->assertSee('Reasoning effort: medium')
        ->assertSee('Provider-reported model: gpt-6.1-sol-2026-10-01');
});

it('shows the configured model before queuing new translations', function (): void {
    $candidate = publicationHelpCandidate();
    config(['contextual-help.translation.model' => 'gpt-6.1-sol', 'contextual-help.translation.reasoning_effort' => 'medium']);
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test(ListHelpTopics::class)->loadTable()->selectTableRecords([$candidate->topicLocale->help_topic_id])
        ->mountAction(TestAction::make('translateSelected')->table()->bulk());

    expect($page->instance()->getMountedAction()->getModalDescription())->toContain('Requested model: gpt-6.1-sol', 'Reasoning effort: medium');
});

it('does not infer a provider model when older response metadata is missing', function (): void {
    $candidate = publicationHelpCandidate();
    $candidate->update(['response_model' => null]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditHelpTopic::class, ['record' => $candidate->topicLocale->topic->public_id])
        ->callAction('language', data: ['locale' => 'fr'])
        ->assertSee('Requested model: gpt-6.1-sol')->assertSee('Provider-reported model: Not reported');
});

it('keeps candidate acceptance and publication atomic when publication fails', function (): void {
    Queue::fake();
    $candidate = publicationHelpCandidate();
    $next = publicationHelpCandidate();
    $target = $candidate->topicLocale;
    $snapshot = app(HelpTopicBulkOperations::class)->snapshot(collect([$target->topic, $next->topicLocale->topic]));
    DB::unprepared("CREATE TRIGGER reject_candidate_publication BEFORE UPDATE ON help_topic_locales WHEN NEW.id = {$target->id} AND NEW.published_revision_id IS NOT NULL BEGIN SELECT RAISE(ABORT, 'simulated publication failure'); END");

    $result = app(PublishHelpTranslationCandidates::class)->handle(User::factory()->admin()->create(), $snapshot, ['fr']);

    expect($result)->toBe(['published' => 1, 'skipped' => 1]);
    expect($candidate->fresh()->status)->toBe(Status::Completed);
    expect($target->fresh()->latest_revision_id)->toBeNull();
    expect($target->revisions()->count())->toBe(0);
    expect($next->fresh()->status)->toBe(Status::Accepted);
});

it('shows the saved candidate models before bulk publication even if current defaults differ', function (): void {
    $candidate = publicationHelpCandidate();
    config(['contextual-help.translation.model' => 'gpt-6-luna', 'contextual-help.translation.reasoning_effort' => 'max']);
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test(ListHelpTopics::class)->loadTable()->selectTableRecords([$candidate->topicLocale->help_topic_id])
        ->mountAction(TestAction::make('publishTranslations')->table()->bulk());

    expect($page->instance()->getMountedAction()->getModalDescription())->toContain('gpt-6.1-sol (medium)', 'Provider-reported models in saved candidates: gpt-6.1-sol-2026-10-01.')->not->toContain('gpt-6-luna');
});

it('preserves an already current published translation when a saved candidate also exists', function (): void {
    Queue::fake();
    $candidate = publicationHelpCandidate();
    $target = $candidate->topicLocale;
    $published = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $target->id, 'source_english_revision_id' => $candidate->source_english_revision_id]);
    $target->update(['latest_revision_id' => $published->id, 'published_revision_id' => $published->id]);
    $snapshot = app(HelpTopicBulkOperations::class)->snapshot(collect([$target->topic]));

    $result = app(PublishHelpTranslationCandidates::class)->handle(User::factory()->admin()->create(), $snapshot, ['fr']);

    expect($result)->toBe(['published' => 0, 'skipped' => 1]);
    expect($target->fresh()->published_revision_id)->toBe($published->id);
    expect($candidate->fresh()->status)->toBe(Status::Completed);
});

it('bounds candidate publication before changing any selected topics', function (int $topicCount, array $codes): void {
    $candidate = publicationHelpCandidate();
    foreach (array_diff($codes, ['fr']) as $code) {
        SupportedLocale::factory()->create(['code' => $code, 'is_active' => true]);
    }
    $selection = collect(range(1, $topicCount))->map(fn (int $id): array => ['topic_id' => $id, 'revision_id' => $candidate->source_english_revision_id, 'lock_version' => 0, 'locale_locks' => [], 'candidate_requests' => []])->all();

    expect(fn () => app(PublishHelpTranslationCandidates::class)->handle(User::factory()->admin()->create(), $selection, $codes))
        ->toThrow(ValidationException::class);

    expect($candidate->fresh()->status)->toBe(Status::Completed);
})->with(['too many topics' => [101, ['fr']], 'too many topic-language pairs' => [100, ['fr', 'de', 'es', 'it', 'nl', 'pt_BR', 'pl']]]);
