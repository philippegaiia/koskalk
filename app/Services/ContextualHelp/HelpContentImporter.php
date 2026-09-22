<?php

namespace App\Services\ContextualHelp;

use App\Enums\HelpContentImportMode;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HelpContentImporter
{
    public function __construct(private readonly HelpContentManifest $manifest, private readonly HelpContentSnapshot $snapshot, private readonly HelpContentExportService $exports) {}

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function preview(array $manifest, HelpContentImportMode $mode): array
    {
        $manifest = $this->manifest->validate($manifest);
        $this->checkIdentities($manifest);
        if ($mode === HelpContentImportMode::RestoreEmpty) {
            $this->assertEmpty();
        }
        $changes = [];
        foreach ($manifest['topics'] as $topic) {
            $existingTopic = HelpTopic::query()->where('key', $topic['key'])->first();
            foreach ($topic['locales'] as $locale) {
                if (($locale['latest_revision_uuid'] === null && ($mode !== HelpContentImportMode::MergeDrafts || $locale['translation_requests'] === [])) || ($mode === HelpContentImportMode::Bootstrap && $locale['locale'] !== 'en')) {
                    continue;
                }
                $head = $existingTopic?->locales()->where('locale', $locale['locale'])->with('latestRevision')->first();
                $incoming = collect($locale['revisions'])->firstWhere('public_id', $locale['latest_revision_uuid']);
                $seen = $incoming === null || HelpTopicRevision::query()->where('public_id', $incoming['public_id'])->exists();
                $newRequests = $mode === HelpContentImportMode::MergeDrafts ? collect($locale['translation_requests'])->reject(fn (array $request): bool => HelpTranslationRequest::query()->where('public_id', $request['public_id'])->exists())->count() : 0;
                $changes[] = [
                    'key' => $topic['key'], 'locale' => $locale['locale'], 'revision_uuid' => $incoming['public_id'] ?? null,
                    'lock_version' => $head?->lock_version, 'latest_revision_uuid' => $head?->latestRevision?->public_id,
                    'current' => $head?->latestRevision?->only(['title', 'summary', 'body_markdown']),
                    'incoming' => $incoming ? Arr::only($incoming, ['title', 'summary', 'body_markdown']) : null,
                    'incoming_requests' => $newRequests,
                    'will_change' => ($mode !== HelpContentImportMode::Bootstrap || $existingTopic?->archived_at === null) && ($newRequests > 0 || (! $seen && ($mode !== HelpContentImportMode::Bootstrap || ($head?->latest_revision_id === null && ! $head?->revisions()->exists())))),
                ];
            }
        }

        return ['manifest_hash' => $manifest['digest'], 'mode' => $mode->value, 'selection' => collect($changes)->map(fn (array $change): array => Arr::only($change, ['key', 'locale', 'revision_uuid', 'lock_version', 'latest_revision_uuid']))->all(), 'changes' => $changes];
    }

    /** @param array<string, mixed> $manifest @param list<array<string, mixed>> $selection @return array{imported:int, skipped:int} */
    public function apply(array $manifest, HelpContentImportMode $mode, array $selection, string $expectedManifestHash): array
    {
        $manifest = $this->manifest->validate($manifest);
        $this->ensure(hash_equals($manifest['digest'], $expectedManifestHash), 'The manifest changed after preview.');
        $this->checkIdentities($manifest);
        if ($mode === HelpContentImportMode::MergeDrafts && HelpTopic::query()->exists()) {
            $this->exports->beforeImport();
        }

        return DB::transaction(function () use ($manifest, $mode, $selection, $expectedManifestHash): array {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE help_topics IN SHARE ROW EXCLUSIVE MODE');
            }
            HelpTopic::query()->orderBy('id')->lockForUpdate()->get();
            HelpTopicLocale::query()->orderBy('id')->lockForUpdate()->get();
            HelpTranslationRequest::query()->orderBy('id')->lockForUpdate()->get();
            $this->ensure(hash_equals($this->manifest->hash($manifest), $expectedManifestHash), 'The manifest changed after preview.');
            $this->checkIdentities($manifest);
            if ($mode === HelpContentImportMode::RestoreEmpty) {
                $this->assertEmpty();

                return $this->restore($manifest);
            }
            $allowed = collect($this->preview($manifest, $mode)['selection'])->keyBy(fn (array $row): string => $row['key'].'|'.$row['locale']);
            $selected = [];
            foreach ($selection as $row) {
                $this->ensure(is_array($row) && isset($row['key'], $row['locale']) && array_key_exists('revision_uuid', $row) && array_key_exists('lock_version', $row) && array_key_exists('latest_revision_uuid', $row), 'Invalid import selection.');
                $key = $row['key'].'|'.$row['locale'];
                $this->ensure(! isset($selected[$key]) && $allowed->has($key), 'Invalid or duplicate import selection.');
                $this->ensure($allowed[$key] === Arr::only($row, ['key', 'locale', 'revision_uuid', 'lock_version', 'latest_revision_uuid']), 'Help content changed after preview.');
                $selected[$key] = $row;
            }
            $imported = 0;
            $skipped = 0;
            foreach ($manifest['topics'] as $topicData) {
                foreach ($topicData['locales'] as $localeData) {
                    if (! isset($selected[$topicData['key'].'|'.$localeData['locale']])) {
                        continue;
                    }
                    $topic = HelpTopic::query()->firstOrCreate(['key' => $topicData['key']], Arr::only($topicData, ['public_id', 'domain']));
                    if ($mode === HelpContentImportMode::Bootstrap && $topic->archived_at !== null) {
                        $skipped++;

                        continue;
                    }
                    $locale = $topic->locales()->firstOrCreate(['locale' => $localeData['locale']]);
                    if ($mode === HelpContentImportMode::Bootstrap && ($topic->archived_at !== null || $locale->revisions()->exists() || $locale->latest_revision_id !== null || $locale->published_revision_id !== null)) {
                        $skipped++;

                        continue;
                    }
                    $incoming = collect($localeData['revisions'])->firstWhere('public_id', $localeData['latest_revision_uuid']);
                    $alreadyKnown = $incoming === null || HelpTopicRevision::query()->where('public_id', $incoming['public_id'])->exists();
                    $englishData = collect($topicData['locales'])->firstWhere('locale', 'en');
                    if ($localeData['locale'] !== 'en') {
                        $english = $topic->locales()->firstOrCreate(['locale' => 'en']);
                        foreach ($englishData['revisions'] as $revision) {
                            $this->insertRevision($english, $revision, false);
                        }
                    }
                    foreach ($localeData['revisions'] as $revision) {
                        $this->insertRevision($locale, $revision, false);
                    }
                    if ($mode === HelpContentImportMode::MergeDrafts) {
                        foreach ($localeData['translation_requests'] as $request) {
                            $this->insertRequest($locale, $request);
                        }
                    }
                    if ($alreadyKnown) {
                        $skipped++;

                        continue;
                    }
                    $locale->update(['latest_revision_id' => HelpTopicRevision::query()->where('public_id', $incoming['public_id'])->sole()->id, 'lock_version' => $locale->lock_version + 1]);
                    $imported++;
                }
            }

            return ['imported' => $imported, 'skipped' => $skipped];
        }, attempts: 5);
    }

    /** @param array<string, mixed> $manifest @return array{imported:int, skipped:int} */
    private function restore(array $manifest): array
    {
        $heads = [];
        $imported = 0;
        foreach ($manifest['topics'] as $topicData) {
            $topic = HelpTopic::query()->create(Arr::only($topicData, ['public_id', 'key', 'domain', 'archived_at']));
            foreach (collect($topicData['locales'])->sortBy(fn (array $locale): int => $locale['locale'] === 'en' ? 0 : 1) as $localeData) {
                $locale = $topic->locales()->create(['locale' => $localeData['locale']]);
                foreach ($localeData['revisions'] as $revision) {
                    $this->insertRevision($locale, $revision, true);
                    $imported++;
                }
                $heads[] = [$locale, $localeData];
            }
        }
        foreach ($heads as [$locale, $data]) {
            $locale->update([
                'latest_revision_id' => $this->revisionId($data['latest_revision_uuid']), 'published_revision_id' => $this->revisionId($data['published_revision_uuid']),
                'lock_version' => $data['lock_version'], 'published_by' => $this->userId($data['published_by_uuid']), 'published_at' => $data['published_at'],
            ]);
            foreach ($data['translation_requests'] as $request) {
                $this->insertRequest($locale, $request);
            }
        }

        return ['imported' => $imported, 'skipped' => 0];
    }

    /** @param array<string, mixed> $request */
    private function insertRequest(HelpTopicLocale $locale, array $request): void
    {
        if (HelpTranslationRequest::query()->where('public_id', $request['public_id'])->exists()) {
            return;
        }
        $attributes = Arr::except($request, ['source_english_revision_uuid', 'accepted_revision_uuid', 'requested_by_uuid']);
        $attributes['source_english_revision_id'] = $this->revisionId($request['source_english_revision_uuid']);
        $attributes['accepted_revision_id'] = $this->revisionId($request['accepted_revision_uuid']);
        $attributes['requested_by'] = $this->userId($request['requested_by_uuid']);
        $attributes['help_topic_locale_id'] = $locale->id;
        if (in_array($attributes['status'], ['pending', 'running'], true)) {
            $attributes['status'] = 'failed';
            $attributes['error_code'] = 'restored_not_requeued';
            $attributes['error_message'] = 'Restored unfinished request. Request translation again to retry.';
            $attributes['completed_at'] = now();
        }
        $restored = new HelpTranslationRequest;
        $restored->forceFill($attributes)->save();
    }

    /** @param array<string, mixed> $data */
    private function insertRevision(HelpTopicLocale $locale, array $data, bool $preserveNumber): HelpTopicRevision
    {
        $existing = HelpTopicRevision::query()->where('public_id', $data['public_id'])->first();
        if ($existing) {
            return $existing;
        }
        $attributes = Arr::except($data, ['source_english_revision_uuid', 'created_by_uuid']);
        $attributes['source_english_revision_id'] = $this->revisionId($data['source_english_revision_uuid']);
        $attributes['created_by'] = $this->userId($data['created_by_uuid']);
        $attributes['revision_number'] = $preserveNumber ? $data['revision_number'] : ((int) $locale->revisions()->max('revision_number') + 1);

        return $locale->revisions()->create($attributes);
    }

    /** @param array<string, mixed> $manifest */
    private function checkIdentities(array $manifest): void
    {
        foreach ($manifest['topics'] as $topic) {
            $uuidTopic = HelpTopic::query()->where('public_id', $topic['public_id'])->first();
            $this->ensure($uuidTopic === null || $uuidTopic->key === $topic['key'], 'Topic UUID conflicts with local identity.');
            $localTopic = HelpTopic::query()->where('key', $topic['key'])->first();
            $this->ensure($localTopic === null || $localTopic->domain->value === $topic['domain'], 'Topic domain conflicts with local identity.');
            foreach ($topic['locales'] as $locale) {
                foreach ($locale['translation_requests'] as $incomingRequest) {
                    $request = HelpTranslationRequest::query()->with(['topicLocale.topic', 'sourceEnglishRevision', 'acceptedRevision'])->where('public_id', $incomingRequest['public_id'])->first();
                    if (! $request) {
                        continue;
                    }
                    $this->ensure($request->topicLocale->topic->key === $topic['key'] && $request->topicLocale->locale === $locale['locale']
                        && $request->sourceEnglishRevision->public_id === $incomingRequest['source_english_revision_uuid'], 'Request UUID conflicts with local identity.');
                    foreach (['model', 'prompt_version', 'reasoning_effort', 'expected_target_lock_version'] as $field) {
                        $this->ensure($request->{$field} === $incomingRequest[$field], 'Request UUID conflicts with generation configuration.');
                    }
                    $this->ensure($request->created_at->equalTo(CarbonImmutable::parse($incomingRequest['created_at'])), 'Request UUID conflicts with original creation time.');
                    if ($request->result !== null && $incomingRequest['result'] !== null) {
                        $this->ensure($request->result == $incomingRequest['result'], 'Request UUID conflicts with candidate content.');
                    }
                    if ($request->accepted_revision_id !== null && $incomingRequest['accepted_revision_uuid'] !== null) {
                        $this->ensure($request->acceptedRevision->public_id === $incomingRequest['accepted_revision_uuid'], 'Request UUID conflicts with accepted content.');
                    }
                }
                foreach ($locale['revisions'] as $incoming) {
                    $existing = HelpTopicRevision::query()->with(['topicLocale.topic', 'sourceEnglishRevision'])->where('public_id', $incoming['public_id'])->first();
                    if ($existing === null) {
                        continue;
                    }
                    $data = $this->snapshot->revision($existing, $existing->sourceEnglishRevision?->public_id);
                    $this->ensure($existing->topicLocale->topic->key === $topic['key'] && $existing->topicLocale->locale === $locale['locale'] && $this->identity($data) === $this->identity($incoming), 'Revision UUID conflicts with immutable local content.');
                }
            }
        }
    }

    /** @param array<string, mixed> $revision @return array<string, mixed> */
    private function identity(array $revision): array
    {
        $identity = Arr::except($revision, ['revision_number', 'created_by_uuid']);
        $identity['created_at'] = CarbonImmutable::parse($identity['created_at'])->toISOString();
        ksort($identity);

        return $identity;
    }

    private function assertEmpty(): void
    {
        $this->ensure(! HelpTopic::query()->exists() && ! HelpTopicLocale::query()->exists() && ! HelpTopicRevision::query()->exists() && ! HelpTranslationRequest::query()->exists(), 'Restore requires empty help content tables.');
    }

    private function revisionId(?string $uuid): ?int
    {
        return $uuid === null ? null : HelpTopicRevision::query()->where('public_id', $uuid)->sole()->id;
    }

    /** Portable attribution cannot be linked: users do not have cross-installation UUIDs. */
    private function userId(?string $uuid): ?int
    {
        return null;
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['manifest' => $message]);
        }
    }
}
