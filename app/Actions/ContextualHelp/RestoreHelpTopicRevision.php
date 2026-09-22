<?php

namespace App\Actions\ContextualHelp;

use App\Data\HelpContentInput;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\User;
use App\Services\ContextualHelp\HelpContentEditor;
use Illuminate\Support\Facades\Gate;

final class RestoreHelpTopicRevision
{
    public function __construct(private readonly HelpContentEditor $service) {}

    public function handle(User $actor, HelpTopicLocale $locale, HelpTopicRevision $revision, int $expectedLockVersion): HelpTopicRevision
    {
        Gate::forUser($actor)->authorize('update', $locale->topic);
        abort_unless($revision->help_topic_locale_id === $locale->id, 404);

        return $this->service->save($actor->id, $locale, new HelpContentInput($revision->title, $revision->summary, $revision->body_markdown), $expectedLockVersion, $revision->source_english_revision_id, forceRevision: true);
    }
}
