<?php

namespace App\Services\ContextualHelp;

use App\Models\HelpTopic;
use Illuminate\Support\Facades\DB;

final class HelpTopicRegistry
{
    /** @return array<string, array{domain: string}> */
    public function definitions(): array
    {
        return config('contextual-help.topics', []);
    }

    /** Register missing identities only; installation must never overwrite authoring. */
    public function register(): int
    {
        return DB::transaction(function (): int {
            $created = 0;
            foreach ($this->definitions() as $key => $definition) {
                $topic = HelpTopic::query()->firstOrCreate(['key' => $key], ['domain' => $definition['domain']]);
                $created += (int) $topic->wasRecentlyCreated;
            }

            return $created;
        }, attempts: 5);
    }
}
