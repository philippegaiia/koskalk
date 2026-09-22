<?php

namespace App\Services\ContextualHelp;

use App\Enums\HelpContentExportReason;
use App\Enums\HelpContentExportStatus;
use App\Models\HelpContentExport;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HelpContentPublisher
{
    public function __construct(private readonly HelpContentEditor $editor, private readonly HelpContentExportService $exports) {}

    public function publish(int $actorId, HelpTopicLocale $locale, int $revisionId, int $expectedLockVersion): int
    {
        return DB::transaction(function () use ($actorId, $locale, $revisionId, $expectedLockVersion): int {
            $locked = $this->editor->lockLocale($locale, $expectedLockVersion);
            if ($locked->latest_revision_id !== $revisionId) {
                throw ValidationException::withMessages(['content' => __('help_admin.validation.publication')]);
            }
            if ($locked->locale !== 'en') {
                $english = HelpTopicLocale::query()->where('help_topic_id', $locked->help_topic_id)->where('locale', 'en')->first();
                if (! $english?->published_revision_id || $locked->latestRevision->source_english_revision_id !== $english->published_revision_id) {
                    throw ValidationException::withMessages(['content' => __('help_admin.validation.translation_source')]);
                }
            }
            if ($locked->published_revision_id === $revisionId) {
                return $locked->lock_version;
            }
            $locked->update(['published_revision_id' => $revisionId, 'published_by' => $actorId, 'published_at' => now(), 'lock_version' => $locked->lock_version + 1]);
            $this->requestSnapshot($actorId);

            return $locked->lock_version;
        }, attempts: 5);
    }

    public function withdraw(int $actorId, HelpTopicLocale $locale, int $expectedLockVersion): int
    {
        return DB::transaction(function () use ($actorId, $locale, $expectedLockVersion): int {
            $locked = $this->editor->lockLocale($locale, $expectedLockVersion);
            if ($locked->published_revision_id === null) {
                return $locked->lock_version;
            }
            $locked->update(['published_revision_id' => null, 'published_by' => null, 'published_at' => null, 'lock_version' => $locked->lock_version + 1]);
            $this->requestSnapshot($actorId);

            return $locked->lock_version;
        }, attempts: 5);
    }

    public function setArchived(int $actorId, HelpTopic $topic, bool $archived): void
    {
        DB::transaction(function () use ($actorId, $topic, $archived): void {
            $locked = HelpTopic::query()->lockForUpdate()->findOrFail($topic->id);
            $locked->locales()->orderBy('id')->lockForUpdate()->get();
            if (($locked->archived_at !== null) === $archived) {
                return;
            }
            $locked->update(['archived_at' => $archived ? now() : null]);
            $this->requestSnapshot($actorId);
        }, attempts: 5);
    }

    private function requestSnapshot(int $actorId): void
    {
        $export = HelpContentExport::query()->create([
            'status' => HelpContentExportStatus::Pending,
            'reason' => HelpContentExportReason::Publication,
            'requested_by' => $actorId,
            'format_version' => 1,
        ]);
        $this->exports->dispatch($export);
    }
}
