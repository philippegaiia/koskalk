<?php

namespace App\Services\ContextualHelp;

use App\Actions\ContextualHelp\PublishHelpTopic;
use App\Actions\ContextualHelp\RequestHelpTranslation;
use App\Enums\HelpTranslationRequestStatus as Status;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class HelpTopicBulkOperations
{
    public const int MaxTopics = 100;

    public const int MaxTranslationRequests = 600;

    public function __construct(private readonly PublishHelpTopic $publish, private readonly RequestHelpTranslation $translate) {}

    /**
     * @param  Collection<int, HelpTopic>  $topics
     * @return list<array{topic_id: int, revision_id: int|null, lock_version: int, locale_locks: array<string, int>, candidate_requests: array<string, int>}>
     */
    public function snapshot(Collection $topics): array
    {
        $byTopic = HelpTopicLocale::query()->whereIn('help_topic_id', $topics->pluck('id'))->with('latestTranslationRequest:id,help_topic_locale_id,status')->get()->groupBy('help_topic_id');

        return $topics->map(function (HelpTopic $topic) use ($byTopic): array {
            $locales = $byTopic->get($topic->id, collect());
            $english = $locales->firstWhere('locale', 'en');

            return [
                'topic_id' => $topic->id,
                'revision_id' => $english?->latest_revision_id,
                'lock_version' => $english?->lock_version ?? 0,
                'locale_locks' => $locales->pluck('lock_version', 'locale')->all(),
                'candidate_requests' => $locales->filter(fn ($locale): bool => $locale->latestTranslationRequest?->status === Status::Completed)->mapWithKeys(fn ($locale): array => [$locale->locale => $locale->latestTranslationRequest->id])->all(),
            ];
        })->values()->all();
    }

    /**
     * @param  list<array{topic_id: int, revision_id: int|null, lock_version: int, locale_locks: array<string, int>, candidate_requests?: array<string, int>}>  $selection
     * @param  list<string>  $targetLocales
     * @return array{published: int, queued: int, skipped: int}
     */
    public function run(User $actor, array $selection, array $targetLocales, bool $reviewBeforePublishing): array
    {
        Validator::make(['selection' => $selection, 'locales' => $targetLocales], [
            'selection' => ['required', 'array', 'min:1', 'max:'.self::MaxTopics],
            'selection.*.topic_id' => ['required', 'integer', 'distinct'],
            'selection.*.revision_id' => ['present', 'nullable', 'integer'],
            'selection.*.lock_version' => ['required', 'integer', 'min:0'],
            'selection.*.locale_locks' => ['present', 'array'],
            'locales' => ['array', 'max:20'],
            'locales.*' => ['string', 'distinct', Rule::notIn(['en']), Rule::exists('supported_locales', 'code')->where('is_active', true)],
        ])->validate();
        if (count($selection) * count($targetLocales) > self::MaxTranslationRequests) {
            throw ValidationException::withMessages(['locales' => __('help_admin.bulk.limit', ['limit' => self::MaxTranslationRequests])]);
        }
        $result = ['published' => 0, 'queued' => 0, 'skipped' => 0];

        foreach ($selection as $snapshot) {
            try {
                $counts = DB::transaction(function () use ($actor, $snapshot, $targetLocales, $reviewBeforePublishing): array {
                    $topic = HelpTopic::query()->lockForUpdate()->findOrFail($snapshot['topic_id']);
                    Gate::forUser($actor)->authorize('publish', $topic);
                    if ($targetLocales !== []) {
                        Gate::forUser($actor)->authorize('translate', $topic);
                    }
                    $locales = $topic->locales()->orderBy('id')->lockForUpdate()->get();
                    $english = $locales->firstWhere('locale', 'en');
                    if ($topic->archived_at || ! $english?->latest_revision_id
                        || $english->latest_revision_id !== $snapshot['revision_id']
                        || $english->lock_version !== $snapshot['lock_version']) {
                        return ['published' => 0, 'queued' => 0, 'skipped' => 1];
                    }
                    $published = $english->published_revision_id !== $english->latest_revision_id;
                    if ($published) {
                        $this->publish->handle($actor, $english, $english->latest_revision_id, $english->lock_version);
                    }
                    $counts = ['published' => (int) $published, 'queued' => 0, 'skipped' => (int) (! $published && $targetLocales === [])];
                    foreach ($targetLocales as $code) {
                        $target = $topic->locales()->firstOrCreate(['locale' => $code], ['lock_version' => 0]);
                        if ($target->lock_version !== ($snapshot['locale_locks'][$code] ?? 0)
                            || $this->preservesExistingWork($target, $english->latest_revision_id)) {
                            $counts['skipped']++;

                            continue;
                        }
                        $this->translate->handle($actor, $target, $english->latest_revision_id, $target->lock_version, publishAutomatically: ! $reviewBeforePublishing);
                        $counts['queued']++;
                    }

                    return $counts;
                }, attempts: 5);
            } catch (ValidationException|ModelNotFoundException $exception) {
                $counts = ['published' => 0, 'queued' => 0, 'skipped' => 1];
            } catch (QueryException $exception) {
                report($exception);
                $counts = ['published' => 0, 'queued' => 0, 'skipped' => 1];
            }
            foreach ($counts as $key => $count) {
                $result[$key] += $count;
            }
        }

        return $result;
    }

    private function preservesExistingWork(HelpTopicLocale $locale, int $sourceId): bool
    {
        return ($locale->latest_revision_id !== null && $locale->latest_revision_id !== $locale->published_revision_id)
            || $locale->publishedRevision?->source_english_revision_id === $sourceId
            || $locale->translationRequests()->where(function (Builder $query) use ($sourceId): void {
                $query->whereIn('status', [Status::Pending, Status::Running])
                    ->orWhere(fn (Builder $query) => $query->where('status', Status::Completed)->where('source_english_revision_id', $sourceId));
            })->exists();
    }
}
