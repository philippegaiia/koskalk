<?php

namespace App\Actions\ContextualHelp;

use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use App\Models\User;
use App\Services\ContextualHelp\HelpTranslationService;
use Illuminate\Support\Facades\Gate;

final class AcceptHelpTranslation
{
    public function __construct(private readonly HelpTranslationService $service) {}

    public function handle(User $actor, HelpTranslationRequest $request, int $expectedLockVersion): HelpTopicRevision
    {
        Gate::forUser($actor)->authorize('update', $request->topicLocale->topic);

        return $this->service->accept($actor, $request, $expectedLockVersion);
    }
}
