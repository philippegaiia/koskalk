<?php

namespace App\Services\ContextualHelp;

use App\Contracts\HelpTranslationClient;
use App\Data\HelpContentInput;
use App\Enums\HelpContentOrigin;
use App\Enums\HelpTranslationRequestStatus as Status;
use App\Jobs\TranslateHelpTopic;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use App\Models\SupportedLocale;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class HelpTranslationService
{
    public function __construct(private readonly HelpTranslationClient $client, private readonly HelpContentEditor $editor, private readonly HelpContentValidator $validator) {}

    public function request(User $actor, HelpTopicLocale $locale, int $sourceEnglishRevisionId, int $expectedLockVersion): HelpTranslationRequest
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $locale, $sourceEnglishRevisionId, $expectedLockVersion): HelpTranslationRequest {
            $locked = $this->editor->lockLocale($locale, $expectedLockVersion);
            $this->assertEligible($locked, $sourceEnglishRevisionId);
            $existing = $locked->translationRequests()->whereIn('status', [Status::Pending, Status::Running])->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $request = $locked->translationRequests()->create([
                'source_english_revision_id' => $sourceEnglishRevisionId,
                'expected_target_lock_version' => $expectedLockVersion,
                'status' => Status::Pending,
                'requested_by' => $actor->id,
                'model' => config('contextual-help.translation.model') ?: config('ingredient-enrichment.openai.localization_model'),
                'reasoning_effort' => config('contextual-help.translation.reasoning_effort') ?: config('ingredient-enrichment.openai.localization_reasoning_effort'),
                'prompt_version' => config('contextual-help.translation.prompt_version'),
            ]);
            $this->dispatch($request);

            return $request;
        }, attempts: 5);
    }

    public function dispatch(HelpTranslationRequest $request): void
    {
        DB::afterCommit(function () use ($request): void {
            try {
                TranslateHelpTopic::dispatch($request->id);
            } catch (Throwable) {
                // The durable pending request is redispatched by recovery.
            }
        });
    }

    public function execute(HelpTranslationRequest $request): HelpTranslationRequest
    {
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($request, $token): bool {
            $locale = $request->topicLocale;
            $this->lockTopic($locale);
            $locked = HelpTranslationRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== Status::Pending) {
                return false;
            }
            try {
                $this->authorize($locked->requester);
                $target = $this->editor->lockLocale($locale, $locked->expected_target_lock_version);
                $this->assertEligible($target, $locked->source_english_revision_id);
            } catch (AuthorizationException|ValidationException) {
                $locked->update(['status' => Status::Failed, 'error_code' => 'request_no_longer_valid', 'error_message' => 'The requester, source, or target changed. Request a new translation.', 'completed_at' => now()]);

                return false;
            }
            $locked->update(['status' => Status::Running, 'processing_token' => $token, 'started_at' => now()]);

            return true;
        }, attempts: 5);
        if (! $claimed) {
            return $request->refresh();
        }
        $request->refresh();
        $source = $request->sourceEnglishRevision;
        $audit = [];
        try {
            $response = $this->client->translate(new HelpContentInput($source->title, $source->summary, $source->body_markdown), $request->topicLocale->locale, $request->model, $request->reasoning_effort, $request->prompt_version);
            $audit = ['response_model' => $response->model, 'response_id' => $response->responseId, 'request_id' => $response->requestId, 'input_tokens' => $response->inputTokens, 'output_tokens' => $response->outputTokens];
            $content = $response->errorCode === null ? $this->candidate($response->content) : null;
            $changes = $content ? ['status' => Status::Completed, 'result' => json_encode($content->toArray(), JSON_THROW_ON_ERROR), 'error_code' => null, 'error_message' => null]
                : ['status' => Status::Failed, 'error_code' => $response->errorCode ?? 'invalid_candidate', 'error_message' => 'Translation failed or returned invalid content. Request a new translation to retry.'];
        } catch (Throwable) {
            $changes = ['status' => Status::Failed, 'error_code' => 'translation_failed', 'error_message' => 'Translation failed or returned invalid content. Request a new translation to retry.'];
        }
        HelpTranslationRequest::query()->whereKey($request->id)->where('status', Status::Running)->where('processing_token', $token)
            ->update([...$audit, ...$changes, 'processing_token' => null, 'completed_at' => now()]);

        return $request->refresh();
    }

    public function accept(User $actor, HelpTranslationRequest $request, int $expectedLockVersion): HelpTopicRevision
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $request, $expectedLockVersion): HelpTopicRevision {
            $locale = $request->topicLocale;
            $this->lockTopic($locale);
            $locked = HelpTranslationRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status === Status::Accepted) {
                return $locked->acceptedRevision->load('topicLocale');
            }
            if ($locked->status !== Status::Completed || $expectedLockVersion !== $locked->expected_target_lock_version) {
                throw ValidationException::withMessages(['content' => __('help_admin.validation.conflict')]);
            }
            $target = $this->editor->lockLocale($locale, $expectedLockVersion);
            $this->assertEligible($target, $locked->source_english_revision_id);
            $content = $this->candidate($locked->result);
            if (! $content) {
                throw ValidationException::withMessages(['content' => __('help_admin.validation.translation_source')]);
            }
            $revision = $this->editor->save($actor->id, $target, $content, $expectedLockVersion, $locked->source_english_revision_id, HelpContentOrigin::Ai, $locked->response_model ?? $locked->model, $locked->prompt_version, forceRevision: true);
            $locked->update(['status' => Status::Accepted, 'accepted_revision_id' => $revision->id]);

            return $revision;
        }, attempts: 5);
    }

    public function dismiss(User $actor, HelpTranslationRequest $request): HelpTranslationRequest
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($request): HelpTranslationRequest {
            $this->lockTopic($request->topicLocale);
            $locked = HelpTranslationRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($locked->status, [Status::Completed, Status::Failed, Status::Dismissed], true)) {
                throw ValidationException::withMessages(['content' => __('help_admin.validation.conflict')]);
            }
            $locked->update(['status' => Status::Dismissed]);

            return $locked;
        }, attempts: 5);
    }

    public function recover(): void
    {
        HelpTranslationRequest::query()->where('status', Status::Running)->where('started_at', '<', now()->subMinutes(10))->eachById(function (HelpTranslationRequest $request): void {
            HelpTranslationRequest::query()->whereKey($request->id)->where('status', Status::Running)->where('processing_token', $request->processing_token)->where('started_at', '<', now()->subMinutes(10))
                ->update(['status' => Status::Failed, 'processing_token' => null, 'completed_at' => now(), 'error_code' => 'worker_expired', 'error_message' => 'The translation worker expired. Its provider outcome is unknown; request a new translation to retry.']);
        });
        HelpTranslationRequest::query()->where('status', Status::Pending)->eachById(fn (HelpTranslationRequest $request) => $this->dispatch($request));
    }

    private function authorize(?User $actor): void
    {
        if (! $actor || ! User::query()->whereKey($actor->id)->where('is_admin', true)->exists()) {
            throw new AuthorizationException;
        }
    }

    private function lockTopic(HelpTopicLocale $locale): void
    {
        HelpTopic::query()->lockForUpdate()->findOrFail($locale->help_topic_id);
        HelpTopicLocale::query()->where('help_topic_id', $locale->help_topic_id)->orderBy('id')->lockForUpdate()->get();
    }

    private function assertEligible(HelpTopicLocale $locale, int $sourceId): void
    {
        if ($locale->locale === 'en' || ! SupportedLocale::query()->where('code', $locale->locale)->where('is_active', true)->exists()
            || ! HelpTopicLocale::query()->where('help_topic_id', $locale->help_topic_id)->where('locale', 'en')->where('published_revision_id', $sourceId)->exists()) {
            throw ValidationException::withMessages(['source_english_revision_id' => __('help_admin.validation.translation_source')]);
        }
    }

    /** @param array<string, mixed>|null $result */
    private function candidate(?array $result): ?HelpContentInput
    {
        if ($result === null || count($result) !== 3 || ! is_string($result['title'] ?? null) || ! is_string($result['summary'] ?? null)
            || ! array_key_exists('body_markdown', $result) || (! is_string($result['body_markdown']) && $result['body_markdown'] !== null)) {
            return null;
        }

        return $this->validator->validate(new HelpContentInput($result['title'], $result['summary'], $result['body_markdown']));
    }
}
