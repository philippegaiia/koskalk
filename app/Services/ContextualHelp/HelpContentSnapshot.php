<?php

namespace App\Services\ContextualHelp;

use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HelpContentSnapshot
{
    public function __construct(private readonly HelpContentManifest $manifest) {}

    /** @return array<string, mixed> */
    public function capture(): array
    {
        return DB::transaction(function (): array {
            if (DB::getDriverName() === 'pgsql') {
                if (DB::transactionLevel() === 1) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                } elseif (! in_array(DB::selectOne('SHOW transaction_isolation')->transaction_isolation, ['repeatable read', 'serializable'], true)) {
                    throw new \RuntimeException('Help snapshots inside a transaction require repeatable-read or serializable isolation.');
                }
            }
            $topics = HelpTopic::query()->orderBy('id')->lockForUpdate()->get();
            $locales = HelpTopicLocale::query()->orderBy('id')->lockForUpdate()->get();
            $requests = HelpTranslationRequest::query()->orderBy('id')->lockForUpdate()->get();
            $revisions = HelpTopicRevision::query()->orderBy('revision_number')->orderBy('id')->get();
            $revisionUuids = $revisions->pluck('public_id', 'id');
            $data = $topics->map(fn (HelpTopic $topic): array => [
                'public_id' => $topic->public_id,
                'key' => $topic->key,
                'domain' => $topic->domain->value,
                'archived_at' => $topic->archived_at?->toISOString(),
                'locales' => $locales->where('help_topic_id', $topic->id)->map(fn (HelpTopicLocale $locale): array => [
                    'locale' => $locale->locale,
                    'latest_revision_uuid' => $revisionUuids->get($locale->latest_revision_id),
                    'published_revision_uuid' => $revisionUuids->get($locale->published_revision_id),
                    'lock_version' => $locale->lock_version,
                    'published_by_uuid' => null,
                    'published_at' => $locale->published_at?->toISOString(),
                    'revisions' => $revisions->where('help_topic_locale_id', $locale->id)->map(fn (HelpTopicRevision $revision): array => $this->revision($revision, $revisionUuids->get($revision->source_english_revision_id), null))->values()->all(),
                    'translation_requests' => $requests->where('help_topic_locale_id', $locale->id)->map(function (HelpTranslationRequest $request) use ($revisionUuids): array {
                        $data = $request->only(['public_id', 'expected_target_lock_version', 'result', 'model', 'response_model', 'prompt_version', 'reasoning_effort', 'response_id', 'request_id', 'input_tokens', 'output_tokens', 'error_code', 'error_message']);
                        $data['status'] = $request->status->value;
                        $data['source_english_revision_uuid'] = $revisionUuids->get($request->source_english_revision_id);
                        $data['accepted_revision_uuid'] = $revisionUuids->get($request->accepted_revision_id);
                        $data['requested_by_uuid'] = null;
                        foreach (['started_at', 'completed_at', 'created_at', 'updated_at'] as $field) {
                            $data[$field] = $request->{$field}?->toISOString();
                        }

                        return $data;
                    })->values()->all(),
                ])->values()->all(),
            ])->all();

            return $this->manifest->seal(['format_version' => 1, 'export_uuid' => (string) Str::uuid(), 'captured_at' => now()->toISOString(), 'topics' => $data]);
        }, attempts: 5);
    }

    /** @return array<string, mixed> */
    public function revision(HelpTopicRevision $revision, ?string $sourceUuid, ?string $authorUuid = null): array
    {
        return [
            ...$revision->only(['public_id', 'revision_number', 'title', 'summary', 'body_markdown', 'ai_model', 'prompt_version']),
            'origin' => $revision->origin->value,
            'source_english_revision_uuid' => $sourceUuid,
            'created_by_uuid' => $authorUuid,
            'created_at' => $revision->created_at->toISOString(),
        ];
    }
}
