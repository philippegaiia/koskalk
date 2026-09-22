<?php

namespace App\Actions\ContextualHelp;

use App\Models\HelpTopic;
use App\Models\User;
use App\Services\ContextualHelp\HelpContentPublisher;
use Illuminate\Support\Facades\Gate;

final class SetHelpTopicArchived
{
    public function __construct(private readonly HelpContentPublisher $service) {}

    public function handle(User $actor, HelpTopic $topic, bool $archived): void
    {
        Gate::forUser($actor)->authorize('archive', $topic);
        $this->service->setArchived($actor->id, $topic, $archived);
    }
}
