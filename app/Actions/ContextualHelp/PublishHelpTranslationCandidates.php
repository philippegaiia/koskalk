<?php

namespace App\Actions\ContextualHelp;

use App\Models\HelpTopic;
use App\Models\User;
use App\Services\ContextualHelp\HelpTranslationCandidatePublisher;
use Illuminate\Support\Facades\Gate;

final class PublishHelpTranslationCandidates
{
    public function __construct(private readonly HelpTranslationCandidatePublisher $publisher) {}

    /**
     * @param  list<array{topic_id: int, revision_id: int|null, lock_version: int, locale_locks: array<string, int>, candidate_requests: array<string, int>}>  $selection
     * @param  list<string>  $locales
     * @return array{published: int, skipped: int}
     */
    public function handle(User $actor, array $selection, array $locales): array
    {
        Gate::forUser($actor)->authorize('viewAny', HelpTopic::class);

        return $this->publisher->publish($actor, $selection, $locales);
    }
}
