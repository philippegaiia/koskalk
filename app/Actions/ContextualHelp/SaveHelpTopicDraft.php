<?php

namespace App\Actions\ContextualHelp;

use App\Data\HelpContentInput;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\User;
use App\Services\ContextualHelp\HelpContentEditor;
use Illuminate\Support\Facades\Gate;

final class SaveHelpTopicDraft
{
    public function __construct(private readonly HelpContentEditor $service) {}

    public function handle(User $actor, HelpTopicLocale $locale, HelpContentInput $content, int $expectedLockVersion, ?int $sourceEnglishRevisionId = null): HelpTopicRevision
    {
        Gate::forUser($actor)->authorize('update', $locale->topic);

        return $this->service->save($actor->id, $locale, $content, $expectedLockVersion, $sourceEnglishRevisionId);
    }
}
