<?php

use App\Actions\ContextualHelp\AcceptHelpTranslation;
use App\Actions\ContextualHelp\DismissHelpTranslation;
use App\Actions\ContextualHelp\RequestHelpTranslation;
use App\Contracts\HelpTranslationClient;
use App\Data\HelpTranslationResponse;
use App\Enums\HelpTranslationRequestStatus as Status;
use App\Jobs\ExportHelpContent;
use App\Jobs\TranslateHelpTopic;
use App\Models\HelpContentExport;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use App\Models\User;
use App\Services\ContextualHelp\HelpTranslationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array{User, HelpTopicLocale, HelpTopicRevision} */
function translationFixture(): array
{
    $english = HelpTopicLocale::factory()->create();
    $source = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id, 'title' => 'Saved formula', 'summary' => 'Do not change 5%.', 'body_markdown' => null]);
    $english->update(['latest_revision_id' => $source->id, 'published_revision_id' => $source->id]);
    $target = HelpTopicLocale::factory()->translated('fr')->create(['help_topic_id' => $english->help_topic_id]);

    return [User::factory()->admin()->create(), $target, $source];
}

function completedTranslation(User $actor, HelpTopicLocale $target, HelpTopicRevision $source): HelpTranslationRequest
{
    $request = app(RequestHelpTranslation::class)->handle($actor, $target, $source->id, 0);
    $request->update(['status' => Status::Completed, 'result' => ['title' => 'Formule', 'summary' => 'Ne pas modifier 5%.', 'body_markdown' => null]]);

    return $request;
}

it('reuses active requests and captures the configured model and source without changing drafts', function () {
    Queue::fake([TranslateHelpTopic::class]);
    [$actor, $target, $source] = translationFixture();
    config(['contextual-help.translation.model' => 'help-model', 'contextual-help.translation.reasoning_effort' => 'medium']);
    $request = app(RequestHelpTranslation::class)->handle($actor, $target, $source->id, 0);
    $again = app(RequestHelpTranslation::class)->handle($actor, $target, $source->id, 0);
    expect($again->id)->toBe($request->id);
    expect($request->model)->toBe('help-model');
    expect($request->reasoning_effort)->toBe('medium');
    expect($request->source_english_revision_id)->toBe($source->id);
    expect($target->refresh()->latest_revision_id)->toBeNull();
    Queue::assertPushed(TranslateHelpTopic::class, fn ($job) => $job->requestId === $request->id && $job->connection === 'database' && $job->queue === 'content');
    Queue::assertPushed(TranslateHelpTopic::class, 1);
});

it('rejects an unavailable source target or actor before dispatch', function (string $case) {
    Queue::fake([TranslateHelpTopic::class]);
    [$actor, $target, $source] = translationFixture();
    if ($case === 'source') {
        $source->topicLocale->update(['published_revision_id' => null]);
    } elseif ($case === 'locale') {
        $target->supportedLocale->update(['is_active' => false]);
    } elseif ($case === 'english') {
        $target = $source->topicLocale;
    } elseif ($case === 'lock') {
        $target->update(['lock_version' => 1]);
    } else {
        $actor->update(['is_admin' => false]);
    }
    expect(fn () => app(RequestHelpTranslation::class)->handle($actor, $target, $source->id, 0))->toThrow($case === 'actor' ? AuthorizationException::class : ValidationException::class);
    expect(HelpTranslationRequest::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['source', 'locale', 'english', 'lock', 'actor']);

it('stores a candidate and usage without altering latest or published content and never calls twice', function () {
    Queue::fake([TranslateHelpTopic::class]);
    [$actor, $target, $source] = translationFixture();
    $request = app(RequestHelpTranslation::class)->handle($actor, $target, $source->id, 0);
    $this->mock(HelpTranslationClient::class)->shouldReceive('translate')->once()->andReturn(new HelpTranslationResponse(['title' => 'Formule', 'summary' => 'Ne pas modifier 5%.', 'body_markdown' => null], 'provider-model', 'resp_1', 'req_1', 15, 8));
    app(HelpTranslationService::class)->execute($request);
    app(HelpTranslationService::class)->execute($request);
    expect($request->refresh()->status)->toBe(Status::Completed);
    expect($request->input_tokens)->toBe(15);
    expect($request->response_model)->toBe('provider-model');
    expect($target->refresh()->latest_revision_id)->toBeNull();
    expect($target->published_revision_id)->toBeNull();
    expect(HelpTopicRevision::count())->toBe(1);
    Queue::assertPushed(TranslateHelpTopic::class, 1);
});

it('retains provider audit information for invalid or failed candidates', function (array $result, ?string $error) {
    Queue::fake([TranslateHelpTopic::class]);
    [$actor, $target, $source] = translationFixture();
    $request = app(RequestHelpTranslation::class)->handle($actor, $target, $source->id, 0);
    $this->mock(HelpTranslationClient::class)->shouldReceive('translate')->once()->andReturn(new HelpTranslationResponse($result, 'model', 'resp_2', 'req_2', 25, 10, $error));
    app(HelpTranslationService::class)->execute($request);
    expect($request->refresh()->status)->toBe(Status::Failed);
    expect($request->input_tokens)->toBe(25);
    expect($request->output_tokens)->toBe(10);
    expect($request->response_id)->toBe('resp_2');
    expect($request->request_id)->toBe('req_2');
    expect($target->refresh()->latest_revision_id)->toBeNull();
    $retry = app(RequestHelpTranslation::class)->handle($actor, $target, $source->id, 0);
    expect($retry->id)->not->toBe($request->id);
    Queue::assertPushed(TranslateHelpTopic::class, 2);
})->with([
    'extra key' => [['title' => 'Aide', 'summary' => 'Texte.', 'body_markdown' => null, 'extra' => true], null],
    'unsafe markdown' => [['title' => 'Aide', 'summary' => 'Texte.', 'body_markdown' => '<script>secret</script>'], null],
    'provider failure' => [[], 'provider_http_500'],
]);

it('rechecks requester source and target at execution without contacting the provider', function (string $change) {
    Queue::fake([TranslateHelpTopic::class]);
    [$actor, $target, $source] = translationFixture();
    $request = app(RequestHelpTranslation::class)->handle($actor, $target, $source->id, 0);
    if ($change === 'actor') {
        $actor->update(['is_admin' => false]);
    } elseif ($change === 'source') {
        $source->topicLocale->update(['published_revision_id' => null]);
    } else {
        $target->update(['lock_version' => 1]);
    }
    $this->mock(HelpTranslationClient::class)->shouldNotReceive('translate');
    app(HelpTranslationService::class)->execute($request);
    expect($request->refresh()->status)->toBe(Status::Failed);
    expect($request->error_code)->toBe('request_no_longer_valid');
    Queue::assertPushed(TranslateHelpTopic::class, 1);
})->with(['actor', 'source', 'target']);

it('accepts once into an attributed draft without publishing', function () {
    Queue::fake([TranslateHelpTopic::class]);
    [$actor, $target, $source] = translationFixture();
    $request = completedTranslation($actor, $target, $source);
    $revision = app(AcceptHelpTranslation::class)->handle($actor, $request, 0);
    $again = app(AcceptHelpTranslation::class)->handle($actor, $request, 0);
    expect($again->id)->toBe($revision->id);
    expect($revision->topicLocale->lock_version)->toBe(1);
    expect($revision->source_english_revision_id)->toBe($source->id);
    expect($revision->origin->value)->toBe('ai');
    expect($request->refresh()->accepted_revision_id)->toBe($revision->id);
    expect($target->refresh()->published_revision_id)->toBeNull();
    expect(HelpTopicRevision::count())->toBe(2);
    Queue::assertPushed(TranslateHelpTopic::class, 1);
});

it('leaves stale candidates viewable without overwriting content', function (string $case) {
    Queue::fake([TranslateHelpTopic::class]);
    [$actor, $target, $source] = translationFixture();
    $request = completedTranslation($actor, $target, $source);
    if ($case === 'source') {
        $source->topicLocale->update(['published_revision_id' => null]);
    } else {
        $target->update(['lock_version' => 1]);
    }
    expect(fn () => app(AcceptHelpTranslation::class)->handle($actor, $request, $case === 'observed' ? 1 : 0))->toThrow(ValidationException::class);
    expect($request->refresh()->status)->toBe(Status::Completed);
    expect($request->result['title'])->toBe('Formule');
    expect($target->refresh()->latest_revision_id)->toBeNull();
    Queue::assertPushed(TranslateHelpTopic::class, 1);
})->with(['source', 'target', 'observed']);

it('dismisses reviewable candidates but refuses to discard active work', function () {
    Queue::fake([TranslateHelpTopic::class]);
    [$actor, $target, $source] = translationFixture();
    $request = app(RequestHelpTranslation::class)->handle($actor, $target, $source->id, 0);
    expect(fn () => app(DismissHelpTranslation::class)->handle($actor, $request))->toThrow(ValidationException::class);
    $request->update(['status' => Status::Failed]);
    app(DismissHelpTranslation::class)->handle($actor, $request);
    app(DismissHelpTranslation::class)->handle($actor, $request);
    expect($request->refresh()->status)->toBe(Status::Dismissed);
    Queue::assertPushed(TranslateHelpTopic::class, 1);
});

it('expires abandoned calls and rejects late results without retrying a paid call', function () {
    $this->freezeTime();
    Queue::fake([TranslateHelpTopic::class]);
    [$actor, $target, $source] = translationFixture();
    $request = app(RequestHelpTranslation::class)->handle($actor, $target, $source->id, 0);
    $this->mock(HelpTranslationClient::class)->shouldReceive('translate')->once()->andReturnUsing(function () use ($request) {
        $request->refresh()->update(['started_at' => now()->subMinutes(11)]);
        app(HelpTranslationService::class)->recover();

        return new HelpTranslationResponse(['title' => 'Tardif', 'summary' => 'Texte.', 'body_markdown' => null], inputTokens: 4);
    });
    app(HelpTranslationService::class)->execute($request);
    expect($request->refresh()->status)->toBe(Status::Failed);
    expect($request->error_code)->toBe('worker_expired');
    expect($request->processing_token)->toBeNull();
    expect($request->result)->toBeNull();
    Queue::assertPushed(TranslateHelpTopic::class, 1);
});

it('recovers pending translations and snapshots and expired snapshots through the command', function () {
    $this->freezeTime();
    Queue::fake([TranslateHelpTopic::class, ExportHelpContent::class]);
    [$actor, $target, $source] = translationFixture();
    $request = app(RequestHelpTranslation::class)->handle($actor, $target, $source->id, 0);
    $pending = HelpContentExport::factory()->create();
    $expired = HelpContentExport::factory()->create(['status' => 'running', 'processing_token' => '06c421a8-d27f-4e78-b24b-6f1f12dfb562', 'started_at' => now()->subMinutes(11)]);
    $this->artisan('help:recover-jobs')->assertSuccessful();
    expect($expired->refresh()->processing_token)->toBeNull();
    expect($expired->status->value)->toBe('failed');
    Queue::assertPushed(TranslateHelpTopic::class, 2);
    Queue::assertPushed(ExportHelpContent::class, fn ($job) => $job->exportId === $pending->id);
    Queue::assertPushed(ExportHelpContent::class, fn ($job) => $job->exportId === $expired->id);
});
