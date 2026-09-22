<?php

use App\Actions\ContextualHelp\RestoreHelpTopicRevision;
use App\Actions\ContextualHelp\SaveHelpTopicDraft;
use App\Data\HelpContentInput;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('saves immutable drafts without publishing and skips unchanged saves', function () {
    $locale = HelpTopicLocale::factory()->create();
    $admin = User::factory()->admin()->create();
    $save = app(SaveHelpTopicDraft::class);
    $input = new HelpContentInput('Help', 'A saved explanation.', null);
    $first = $save->handle($admin, $locale, $input, 0);
    $same = $save->handle($admin, $locale->refresh(), $input, 1);
    expect($same->id)->toBe($first->id)->and($locale->refresh()->lock_version)->toBe(1)
        ->and($locale->published_revision_id)->toBeNull()->and(HelpTopicRevision::count())->toBe(1);
});

it('rejects a stale editor without losing either saved revision', function () {
    $locale = HelpTopicLocale::factory()->create();
    $admin = User::factory()->admin()->create();
    app(SaveHelpTopicDraft::class)->handle($admin, $locale, new HelpContentInput('Help', 'First saved text.', null), 0);
    try {
        app(SaveHelpTopicDraft::class)->handle($admin, $locale, new HelpContentInput('Help', 'Stale text.', null), 0);
        test()->fail('Expected a conflict');
    } catch (ValidationException) {
        expect($locale->refresh()->latestRevision->summary)->toBe('First saved text.')
            ->and(HelpTopicRevision::count())->toBe(1);
    }
});

it('restores historical content into a new draft', function () {
    $locale = HelpTopicLocale::factory()->create();
    $admin = User::factory()->admin()->create();
    $save = app(SaveHelpTopicDraft::class);
    $first = $save->handle($admin, $locale, new HelpContentInput('Help', 'First text.', null), 0);
    $save->handle($admin, $locale->refresh(), new HelpContentInput('Help', 'Second text.', null), 1);
    $restored = app(RestoreHelpTopicRevision::class)->handle($admin, $locale->refresh(), $first, 2);
    expect($restored->id)->not->toBe($first->id)->and($restored->summary)->toBe('First text.')
        ->and($restored->revision_number)->toBe(3)->and(HelpTopicRevision::count())->toBe(3);
});

it('denies non admin editing', function () {
    app(SaveHelpTopicDraft::class)->handle(User::factory()->create(), HelpTopicLocale::factory()->create(), new HelpContentInput('Help', 'Visible text.', null), 0);
})->throws(AuthorizationException::class);

it('rejects editing archived topics', function () {
    $locale = HelpTopicLocale::factory()->create();
    $locale->topic->update(['archived_at' => now()]);
    app(SaveHelpTopicDraft::class)->handle(User::factory()->admin()->create(), $locale, new HelpContentInput('Help', 'Visible text.', null), 0);
})->throws(ValidationException::class);
