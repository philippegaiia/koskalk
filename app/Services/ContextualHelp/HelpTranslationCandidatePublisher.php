<?php

namespace App\Services\ContextualHelp;

use App\Actions\ContextualHelp\AcceptHelpTranslation;
use App\Actions\ContextualHelp\PublishHelpTopic;
use App\Enums\HelpTranslationRequestStatus as Status;
use App\Models\HelpTopic;
use App\Models\HelpTranslationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class HelpTranslationCandidatePublisher
{
    public function __construct(private readonly AcceptHelpTranslation $accept, private readonly PublishHelpTopic $publisher) {}

    /**
     * @param  list<array{topic_id: int, revision_id: int|null, lock_version: int, locale_locks: array<string, int>, candidate_requests: array<string, int>}>  $selection
     * @param  list<string>  $targetLocales
     * @return array{published: int, skipped: int}
     */
    public function publish(User $actor, array $selection, array $targetLocales): array
    {
        Validator::make(['selection' => $selection, 'locales' => $targetLocales], [
            'selection' => ['required', 'array', 'min:1', 'max:'.HelpTopicBulkOperations::MaxTopics],
            'selection.*.topic_id' => ['required', 'integer', 'distinct'],
            'selection.*.revision_id' => ['present', 'nullable', 'integer'],
            'selection.*.lock_version' => ['required', 'integer', 'min:0'],
            'selection.*.locale_locks' => ['present', 'array'],
            'selection.*.locale_locks.*' => ['integer', 'min:0'],
            'selection.*.candidate_requests' => ['present', 'array'],
            'selection.*.candidate_requests.*' => ['integer', 'min:1'],
            'locales' => ['required', 'array', 'min:1', 'max:20'],
            'locales.*' => ['string', 'distinct', Rule::notIn(['en']), Rule::exists('supported_locales', 'code')->where('is_active', true)],
        ])->validate();
        if (count($selection) * count($targetLocales) > HelpTopicBulkOperations::MaxTranslationRequests) {
            throw ValidationException::withMessages(['locales' => __('help_admin.bulk.limit', ['limit' => HelpTopicBulkOperations::MaxTranslationRequests])]);
        }
        $result = ['published' => 0, 'skipped' => 0];
        foreach ($selection as $snapshot) {
            foreach ($targetLocales as $code) {
                try {
                    $published = DB::transaction(function () use ($actor, $snapshot, $code): bool {
                        $topic = HelpTopic::query()->lockForUpdate()->findOrFail($snapshot['topic_id']);
                        Gate::forUser($actor)->authorize('publish', $topic);
                        $locales = $topic->locales()->orderBy('id')->lockForUpdate()->get();
                        $english = $locales->firstWhere('locale', 'en');
                        $target = $locales->firstWhere('locale', $code);
                        if ($topic->archived_at || ! $english?->published_revision_id || ! $target
                            || $english->latest_revision_id !== $snapshot['revision_id'] || $english->lock_version !== $snapshot['lock_version']
                            || $english->published_revision_id !== $snapshot['revision_id']
                            || $target->lock_version !== ($snapshot['locale_locks'][$code] ?? null)
                            || ($target->latest_revision_id !== null && $target->latest_revision_id !== $target->published_revision_id)
                            || $target->publishedRevision?->source_english_revision_id === $english->published_revision_id) {
                            return false;
                        }
                        $requestId = $snapshot['candidate_requests'][$code] ?? null;
                        $request = $requestId ? HelpTranslationRequest::query()->whereKey($requestId)->where('help_topic_locale_id', $target->id)->lockForUpdate()->first() : null;
                        if (! $request || $request->status !== Status::Completed
                            || $request->source_english_revision_id !== $english->published_revision_id
                            || $target->latestTranslationRequest?->id !== $request->id) {
                            return false;
                        }
                        $revision = $this->accept->handle($actor, $request, $target->lock_version);
                        $target->refresh();
                        $this->publisher->handle($actor, $target, $revision->id, $target->lock_version);

                        return true;
                    }, attempts: 5);
                } catch (ValidationException|ModelNotFoundException) {
                    $published = false;
                } catch (QueryException $exception) {
                    report($exception);
                    $published = false;
                }
                $result[$published ? 'published' : 'skipped']++;
            }
        }

        return $result;
    }
}
