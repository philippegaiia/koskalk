<?php

namespace App\Actions\ContextualHelp;

use App\Models\HelpTopicLocale;
use App\Models\User;
use App\Services\ContextualHelp\HelpContentPublisher;
use Illuminate\Support\Facades\Gate;

final class PublishHelpTopic
{
    public function __construct(private readonly HelpContentPublisher $service) {}

    public function handle(User $actor, HelpTopicLocale $locale, int $revisionId, int $expectedLockVersion): int
    {
        Gate::forUser($actor)->authorize('publish', $locale->topic);

        return $this->service->publish($actor->id, $locale, $revisionId, $expectedLockVersion);
    }
}
