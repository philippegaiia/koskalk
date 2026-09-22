<?php

use App\Actions\ContextualHelp\PublishHelpTopic;
use App\Actions\ContextualHelp\SaveHelpTopicDraft;
use App\Actions\ContextualHelp\WithdrawHelpTopic;
use App\Data\HelpContentInput;
use App\Models\HelpContentExport;
use App\Models\HelpTopicLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('publishes the reviewed revision and retains live content during later edits', function () {
    $locale = HelpTopicLocale::factory()->create();
    $admin = User::factory()->admin()->create();
    $save = app(SaveHelpTopicDraft::class);
    $revision = $save->handle($admin, $locale, new HelpContentInput('Help', 'Published text.', null), 0);
    app(PublishHelpTopic::class)->handle($admin, $locale->refresh(), $revision->id, 1);
    $save->handle($admin, $locale->refresh(), new HelpContentInput('Help', 'Unpublished text.', null), 2);
    expect($locale->refresh()->publishedRevision->summary)->toBe('Published text.')
        ->and($locale->latestRevision->summary)->toBe('Unpublished text.')
        ->and(HelpContentExport::count())->toBe(1);
    app(WithdrawHelpTopic::class)->handle($admin, $locale, 3);
    expect($locale->refresh()->published_revision_id)->toBeNull()->and($locale->published_at)->toBeNull();
});

it('rejects publishing a historical revision', function () {
    $locale = HelpTopicLocale::factory()->create();
    $admin = User::factory()->admin()->create();
    $save = app(SaveHelpTopicDraft::class);
    $first = $save->handle($admin, $locale, new HelpContentInput('Help', 'First text.', null), 0);
    $save->handle($admin, $locale->refresh(), new HelpContentInput('Help', 'Second text.', null), 1);
    app(PublishHelpTopic::class)->handle($admin, $locale->refresh(), $first->id, 2);
})->throws(ValidationException::class);
