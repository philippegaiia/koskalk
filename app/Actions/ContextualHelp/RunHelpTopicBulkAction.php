<?php

namespace App\Actions\ContextualHelp;

use App\Models\HelpTopic;
use App\Models\User;
use App\Services\ContextualHelp\HelpTopicBulkOperations;
use Illuminate\Support\Facades\Gate;

final class RunHelpTopicBulkAction
{
    public function __construct(private readonly HelpTopicBulkOperations $service) {}

    /**
     * @param  list<array{topic_id: int, revision_id: int|null, lock_version: int, locale_locks: array<string, int>}>  $selection
     * @param  list<string>  $targetLocales
     * @return array{published: int, queued: int, skipped: int}
     */
    public function handle(User $actor, array $selection, array $targetLocales = [], bool $reviewBeforePublishing = true): array
    {
        Gate::forUser($actor)->authorize('viewAny', HelpTopic::class);

        return $this->service->run($actor, $selection, $targetLocales, $reviewBeforePublishing);
    }
}
