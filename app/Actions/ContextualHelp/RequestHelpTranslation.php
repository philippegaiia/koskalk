<?php

namespace App\Actions\ContextualHelp;

use App\Models\HelpTopicLocale;
use App\Models\HelpTranslationRequest;
use App\Models\User;
use App\Services\ContextualHelp\HelpTranslationService;
use Illuminate\Support\Facades\Gate;

final class RequestHelpTranslation
{
    public function __construct(private readonly HelpTranslationService $service) {}

    public function handle(User $actor, HelpTopicLocale $locale, int $sourceEnglishRevisionId, int $expectedLockVersion): HelpTranslationRequest
    {
        Gate::forUser($actor)->authorize('update', $locale->topic);

        return $this->service->request($actor, $locale, $sourceEnglishRevisionId, $expectedLockVersion);
    }
}
