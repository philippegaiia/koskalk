<?php

namespace App\Services\ContextualHelp;

use App\Data\HelpContentInput;
use App\Enums\HelpContentOrigin;
use App\Enums\HelpTranslationRequestStatus;
use App\Models\SupportedLocale;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class HelpContentManifest
{
    public const MAX_BYTES = 10485760;

    public function __construct(private readonly HelpContentValidator $content, private readonly HelpTopicRegistry $registry) {}

    /** @return array<string, mixed> */
    public function decode(string $json): array
    {
        $this->ensure(strlen($json) <= self::MAX_BYTES, 'Manifest exceeds 10 MiB.');
        try {
            $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['manifest' => 'Invalid JSON manifest.']);
        }
        $this->ensure(is_array($manifest), 'Expected a JSON object.');

        return $this->validate($manifest);
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function seal(array $manifest): array
    {
        $manifest['digest'] = $this->hash($manifest);

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    public function hash(array $manifest): string
    {
        unset($manifest['digest']);

        return hash('sha256', $this->encode($this->canonical($manifest)));
    }

    /** @param array<string, mixed> $manifest */
    public function encode(array $manifest): string
    {
        return json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function validate(array $manifest): array
    {
        $this->ensure(strlen($this->encode($manifest)) <= self::MAX_BYTES, 'Manifest exceeds 10 MiB.');
        Validator::make($manifest, [
            'format_version' => ['required', 'integer', 'in:1'],
            'export_uuid' => ['required', 'uuid'],
            'captured_at' => ['required', 'date'],
            'digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'topics' => ['present', 'array', 'max:1000'],
        ])->validate();
        $this->keys($manifest, ['format_version', 'export_uuid', 'captured_at', 'digest', 'topics']);
        $this->ensure(hash_equals($this->hash($manifest), $manifest['digest']), 'Manifest digest does not match.');
        $uuids = [];
        $keys = [];
        $revisions = [];
        $localeCodes = SupportedLocale::query()->pluck('code')->all();
        foreach ($manifest['topics'] as $topic) {
            $this->ensure(is_array($topic), 'Each topic must be an object.');
            Validator::make($topic, [
                'public_id' => ['required', 'uuid'], 'key' => ['required', 'string', 'max:160'],
                'domain' => ['required', 'string'], 'archived_at' => ['present', 'nullable', 'date'],
                'locales' => ['present', 'array', 'max:100'],
            ])->validate();
            $this->keys($topic, ['public_id', 'key', 'domain', 'archived_at', 'locales']);
            $this->unique($topic['public_id'], $uuids);
            $this->ensure(! isset($keys[$topic['key']]), 'Duplicate topic key.');
            $keys[$topic['key']] = true;
            $this->ensure(($this->registry->definitions()[$topic['key']]['domain'] ?? null) === $topic['domain'], 'Unknown topic or mismatched domain.');
            $locales = [];
            foreach ($topic['locales'] as $locale) {
                $this->ensure(is_array($locale), 'Each locale must be an object.');
                Validator::make($locale, [
                    'locale' => ['required', Rule::in($localeCodes)],
                    'latest_revision_uuid' => ['present', 'nullable', 'uuid'],
                    'published_revision_uuid' => ['present', 'nullable', 'uuid'],
                    'lock_version' => ['required', 'integer', 'min:0', 'max:2147483647'],
                    'published_by_uuid' => ['present', 'nullable', 'uuid'], 'published_at' => ['present', 'nullable', 'date'],
                    'revisions' => ['present', 'array', 'max:10000'], 'translation_requests' => ['present', 'array', 'max:10000'],
                ])->validate();
                $this->keys($locale, ['locale', 'latest_revision_uuid', 'published_revision_uuid', 'lock_version', 'published_by_uuid', 'published_at', 'revisions', 'translation_requests']);
                $this->ensure(! isset($locales[$locale['locale']]), 'Duplicate locale.');
                $locales[$locale['locale']] = true;
                $numbers = [];
                foreach ($locale['revisions'] as $revision) {
                    $this->ensure(is_array($revision), 'Each revision must be an object.');
                    Validator::make($revision, [
                        'public_id' => ['required', 'uuid'], 'revision_number' => ['required', 'integer', 'min:1', 'max:2147483647'],
                        'title' => ['required', 'string', 'max:160'], 'summary' => ['required', 'string', 'max:600'], 'body_markdown' => ['present', 'nullable', 'string', 'max:12000'],
                        'source_english_revision_uuid' => ['present', 'nullable', 'uuid'], 'origin' => ['required', Rule::enum(HelpContentOrigin::class)],
                        'ai_model' => ['present', 'nullable', 'string', 'max:160'], 'prompt_version' => ['present', 'nullable', 'string', 'max:100'],
                        'created_by_uuid' => ['present', 'nullable', 'uuid'], 'created_at' => ['required', 'date'],
                    ])->validate();
                    $this->keys($revision, ['public_id', 'revision_number', 'title', 'summary', 'body_markdown', 'source_english_revision_uuid', 'origin', 'ai_model', 'prompt_version', 'created_by_uuid', 'created_at']);
                    $this->content->validate(new HelpContentInput($revision['title'], $revision['summary'], $revision['body_markdown']));
                    $this->unique($revision['public_id'], $uuids);
                    $this->ensure(! isset($numbers[$revision['revision_number']]), 'Duplicate revision number.');
                    $numbers[$revision['revision_number']] = true;
                    $revisions[$revision['public_id']] = ['key' => $topic['key'], 'locale' => $locale['locale'], 'revision' => $revision];
                }
                foreach ($locale['translation_requests'] as $request) {
                    $this->ensure(is_array($request), 'Each translation request must be an object.');
                    $this->validateRequest($request);
                    $this->unique($request['public_id'], $uuids);
                    $this->ensure($locale['locale'] !== 'en', 'English cannot have translation requests.');
                }
            }
        }
        foreach ($manifest['topics'] as $topic) {
            foreach ($topic['locales'] as $locale) {
                foreach (['latest_revision_uuid', 'published_revision_uuid'] as $head) {
                    $this->reference($locale[$head], $topic['key'], $locale['locale'], $revisions, true);
                }
                foreach ($locale['revisions'] as $revision) {
                    if ($locale['locale'] === 'en') {
                        $this->ensure($revision['source_english_revision_uuid'] === null, 'English cannot reference a source revision.');
                    } else {
                        $this->reference($revision['source_english_revision_uuid'], $topic['key'], 'en', $revisions);
                    }
                }
                foreach ($locale['translation_requests'] as $request) {
                    $this->reference($request['source_english_revision_uuid'], $topic['key'], 'en', $revisions);
                    $this->reference($request['accepted_revision_uuid'], $topic['key'], $locale['locale'], $revisions, true);
                }
            }
        }

        return $manifest;
    }

    /** @param array<string, mixed> $request */
    private function validateRequest(array $request): void
    {
        $rules = [
            'public_id' => ['required', 'uuid'], 'source_english_revision_uuid' => ['required', 'uuid'],
            'accepted_revision_uuid' => ['present', 'nullable', 'uuid'], 'requested_by_uuid' => ['present', 'nullable', 'uuid'],
            'expected_target_lock_version' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'status' => ['required', Rule::enum(HelpTranslationRequestStatus::class)],
            'result' => ['present', 'nullable', 'array:title,summary,body_markdown'],
            'response_model' => ['present', 'nullable', 'string', 'max:160'],
            'model' => ['required', 'string', 'max:160'], 'prompt_version' => ['required', 'string', 'max:100'],
            'reasoning_effort' => ['required', 'string', 'max:32'],
        ];
        foreach (['response_id', 'request_id', 'error_code', 'error_message'] as $field) {
            $rules[$field] = ['present', 'nullable', 'string', 'max:'.($field === 'error_message' ? 2000 : 255)];
        }
        foreach (['input_tokens', 'output_tokens'] as $field) {
            $rules[$field] = ['present', 'nullable', 'integer', 'min:0', 'max:2147483647'];
        }
        foreach (['started_at', 'completed_at', 'created_at', 'updated_at'] as $field) {
            $rules[$field] = ['present', 'nullable', 'date'];
        }
        Validator::make($request, $rules)->validate();
        $this->keys($request, array_keys($rules));
        if ($request['result'] !== null) {
            Validator::make($request['result'], ['title' => ['required', 'string'], 'summary' => ['required', 'string'], 'body_markdown' => ['present', 'nullable', 'string']])->validate();
            $this->content->validate(new HelpContentInput($request['result']['title'], $request['result']['summary'], $request['result']['body_markdown']));
        }
    }

    /** @param array<string, mixed> $values @param list<string> $allowed */
    private function keys(array $values, array $allowed): void
    {
        $this->ensure(array_diff(array_keys($values), $allowed) === [], 'Unknown manifest fields are forbidden.');
    }

    /** @param array<string, bool> $seen */
    private function unique(string $uuid, array &$seen): void
    {
        $this->ensure($uuid === strtolower($uuid), 'UUIDs must use lowercase canonical spelling.');
        $this->ensure(! isset($seen[$uuid]), 'Duplicate UUID.');
        $seen[$uuid] = true;
    }

    /** @param array<string, mixed> $revisions */
    private function reference(?string $uuid, string $key, string $locale, array $revisions, bool $nullable = false): void
    {
        if ($uuid === null && $nullable) {
            return;
        }
        $this->ensure($uuid !== null && ($revisions[$uuid]['key'] ?? null) === $key && ($revisions[$uuid]['locale'] ?? null) === $locale, 'Invalid revision reference.');
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['manifest' => $message]);
        }
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
