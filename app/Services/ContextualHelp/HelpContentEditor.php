<?php

namespace App\Services\ContextualHelp;

use App\Data\HelpContentInput;
use App\Enums\HelpContentOrigin;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HelpContentEditor
{
    public function __construct(private readonly HelpContentValidator $validator) {}

    public function save(?int $actorId, HelpTopicLocale $locale, HelpContentInput $input, int $expectedLockVersion, ?int $sourceEnglishRevisionId = null, HelpContentOrigin $origin = HelpContentOrigin::Human, ?string $aiModel = null, ?string $promptVersion = null, bool $forceRevision = false): HelpTopicRevision
    {
        $content = $this->validator->validate($input);

        return DB::transaction(function () use ($actorId, $locale, $content, $expectedLockVersion, $sourceEnglishRevisionId, $origin, $aiModel, $promptVersion, $forceRevision): HelpTopicRevision {
            $locked = $this->lockLocale($locale, $expectedLockVersion);
            $this->assertSource($locked, $sourceEnglishRevisionId);
            $latest = $locked->latestRevision;
            if (! $forceRevision && $latest && $latest->title === $content->title && $latest->summary === $content->summary
                && $latest->body_markdown === $content->bodyMarkdown && $latest->source_english_revision_id === $sourceEnglishRevisionId) {
                return $latest->setRelation('topicLocale', $locked);
            }
            $revision = $locked->revisions()->create([
                ...$content->toArray(),
                'revision_number' => ((int) $locked->revisions()->max('revision_number')) + 1,
                'source_english_revision_id' => $sourceEnglishRevisionId,
                'origin' => $origin,
                'ai_model' => $aiModel,
                'prompt_version' => $promptVersion,
                'created_by' => $actorId,
            ]);
            $locked->update(['latest_revision_id' => $revision->id, 'lock_version' => $locked->lock_version + 1]);

            return $revision->setRelation('topicLocale', $locked);
        }, attempts: 5);
    }

    /** Must be called inside a transaction. All help writers lock topic before locales. */
    public function lockLocale(HelpTopicLocale $locale, int $expectedLockVersion): HelpTopicLocale
    {
        $topic = HelpTopic::query()->lockForUpdate()->findOrFail($locale->help_topic_id);
        $locales = $topic->locales()->orderBy('id')->lockForUpdate()->get();
        $locked = $locales->firstWhere('id', $locale->id);
        abort_unless($locked instanceof HelpTopicLocale, 404);
        if ($topic->archived_at !== null) {
            throw ValidationException::withMessages(['content' => __('help_admin.validation.archived')]);
        }
        if ($locked->lock_version !== $expectedLockVersion) {
            throw ValidationException::withMessages(['content' => __('help_admin.validation.conflict')]);
        }

        return $locked;
    }

    private function assertSource(HelpTopicLocale $locale, ?int $sourceId): void
    {
        if ($locale->locale === 'en' && $sourceId === null) {
            return;
        }
        if ($locale->locale !== 'en' && $sourceId !== null && HelpTopicRevision::query()->whereKey($sourceId)
            ->whereHas('topicLocale', fn ($query) => $query->where('help_topic_id', $locale->help_topic_id)->where('locale', 'en'))->exists()) {
            return;
        }
        throw ValidationException::withMessages(['source_english_revision_id' => __('help_admin.validation.source')]);
    }
}
