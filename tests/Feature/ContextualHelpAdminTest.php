<?php

use App\Actions\ContextualHelp\PublishHelpTopic;
use App\Actions\ContextualHelp\SaveHelpTopicDraft;
use App\Data\HelpContentInput;
use App\Enums\HelpTranslationRequestStatus;
use App\Filament\Resources\HelpTopics\HelpTopicResource;
use App\Filament\Resources\HelpTopics\Pages\EditHelpTopic;
use App\Jobs\TranslateHelpTopic;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lets an admin save and publish reviewed help through the editor', function () {
    $topic = HelpTopic::factory()->create(['key' => 'soap.water_mode']);
    HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test(EditHelpTopic::class, ['record' => $topic->public_id])
        ->fillForm(['title' => 'Water mode', 'summary' => 'Select a water calculation basis.', 'body_markdown' => '## Choose a basis'])
        ->call('save')->assertHasNoFormErrors();
    $locale = $topic->locales()->where('locale', 'en')->first();
    expect($locale->latestRevision->title)->toBe('Water mode')->and($locale->published_revision_id)->toBeNull();
    $page->callAction('publish')->assertHasNoFormErrors();
    expect($locale->refresh()->published_revision_id)->toBe($locale->latest_revision_id);
});

it('blocks a stale admin form without overwriting another editor', function () {
    $topic = HelpTopic::factory()->create();
    HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $this->actingAs(User::factory()->admin()->create());
    $first = Livewire::test(EditHelpTopic::class, ['record' => $topic->public_id]);
    $second = Livewire::test(EditHelpTopic::class, ['record' => $topic->public_id]);
    $first->fillForm(['title' => 'Help', 'summary' => 'First saved text.'])->call('save')->assertHasNoFormErrors();
    $second->fillForm(['title' => 'Help', 'summary' => 'Stale saved text.'])->call('save')->assertHasErrors(['content']);
    expect($topic->locales()->first()->latestRevision->summary)->toBe('First saved text.');
});

it('denies the help editor to ordinary users', function () {
    $topic = HelpTopic::factory()->create();
    $this->actingAs(User::factory()->create());
    $this->get(HelpTopicResource::getUrl('edit', ['record' => $topic], panel: 'admin'))->assertForbidden();
});

it('does not reverse another admins archive when both confirmations were opened', function () {
    $topic = HelpTopic::factory()->create();
    HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $this->actingAs(User::factory()->admin()->create());
    $first = Livewire::test(EditHelpTopic::class, ['record' => $topic->public_id])->mountAction('archive');
    $second = Livewire::test(EditHelpTopic::class, ['record' => $topic->public_id])->mountAction('archive');
    $first->callMountedAction()->assertHasNoFormErrors();
    $second->callMountedAction()->assertHasNoFormErrors();
    expect($topic->fresh()->archived_at)->not->toBeNull();
});

it('refuses to mark a translation reviewed against English published after the preview opened', function () {
    $topic = HelpTopic::factory()->create();
    $en = HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $fr = HelpTopicLocale::factory()->for($topic, 'topic')->translated()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $save = app(SaveHelpTopicDraft::class);
    $publish = app(PublishHelpTopic::class);
    $english = $save->handle($admin, $en, new HelpContentInput('Help', 'First English.', null), 0);
    $publish->handle($admin, $en->refresh(), $english->id, 1);
    $save->handle($admin, $fr, new HelpContentInput('Aide', 'Texte traduit.', null), 0, $english->id);
    $page = Livewire::test(EditHelpTopic::class, ['record' => $topic->public_id])
        ->callAction('language', data: ['locale' => 'fr'])->assertHasNoFormErrors()->mountAction('reviewed');
    $changed = $save->handle($admin, $en->refresh(), new HelpContentInput('Help', 'Changed English.', null), 2);
    $publish->handle($admin, $en->refresh(), $changed->id, 3);
    $page->callMountedAction()->assertHasErrors(['content']);
    expect($fr->refresh()->latestRevision->source_english_revision_id)->toBe($english->id)
        ->and($fr->revisions()->count())->toBe(1);
});

it('restores only revisions belonging to the selected topic language', function () {
    $topic = HelpTopic::factory()->create();
    HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $foreign = HelpTopicRevision::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(EditHelpTopic::class, ['record' => $topic->public_id])
        ->callAction('restore', data: ['revision' => $foreign->public_id])->assertHasFormErrors(['revision']);
    expect($topic->locales()->first()->latest_revision_id)->toBeNull();
});

it('queues selected languages against the English revision shown when the action opened', function () {
    Queue::fake();
    $en = HelpTopicLocale::factory()->create();
    $source = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $en->id]);
    $en->update(['latest_revision_id' => $source->id, 'published_revision_id' => $source->id]);
    $fr = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $en->help_topic_id]);
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(EditHelpTopic::class, ['record' => $en->topic->public_id])
        ->callAction('generateTranslations', data: ['locales' => ['fr']])->assertHasNoFormErrors();
    $request = $fr->translationRequests()->sole();
    expect($request->source_english_revision_id)->toBe($source->id)
        ->and($request->expected_target_lock_version)->toBe(0)
        ->and($fr->fresh()->latest_revision_id)->toBeNull();
    Queue::assertPushed(TranslateHelpTopic::class, 1);
});

it('shows the candidate comparison and accepts it only as an unpublished draft', function () {
    $en = HelpTopicLocale::factory()->create();
    $source = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $en->id]);
    $en->update(['latest_revision_id' => $source->id, 'published_revision_id' => $source->id]);
    $fr = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $en->help_topic_id]);
    $request = HelpTranslationRequest::factory()->create([
        'help_topic_locale_id' => $fr->id, 'source_english_revision_id' => $source->id,
        'status' => HelpTranslationRequestStatus::Completed,
        'result' => ['title' => 'Aide', 'summary' => 'Texte à relire.', 'body_markdown' => null],
    ]);
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(EditHelpTopic::class, ['record' => $en->topic->public_id])
        ->callAction('language', data: ['locale' => 'fr'])
        ->mountAction('acceptTranslation', arguments: ['request' => $request->public_id])
        ->assertActionMounted('acceptTranslation')
        ->callMountedAction()->assertHasNoFormErrors();
    expect($fr->fresh()->latestRevision->title)->toBe('Aide')
        ->and($fr->fresh()->published_revision_id)->toBeNull()
        ->and($request->fresh()->status)->toBe(HelpTranslationRequestStatus::Accepted);
});

it('shows a readable help heading and groups secondary editor actions', function () {
    $topic = HelpTopic::factory()->create(['key' => 'cosmetic.formula_basis']);
    $locale = HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    app(SaveHelpTopicDraft::class)->handle($admin, $locale, new HelpContentInput('Cosmetic formula basis', 'Use the complete formula.', null), 0);

    $page = Livewire::test(EditHelpTopic::class, ['record' => $topic->public_id])
        ->assertSee('Cosmetic formula basis')
        ->assertSee('sk-help-editor-header', escape: false)
        ->assertSee('More')
        ->assertActionExists('history')
        ->assertActionExists('restore')
        ->assertActionExists('archive');

    expect($page->instance()->getTitle())->toBe('Cosmetic formula basis')
        ->and(array_values($page->instance()->getBreadcrumbs()))->toBe(['Contextual Help', 'Cosmetic formula basis']);

    $actions = collect($page->instance()->getCachedHeaderActions());
    $more = $actions->last();
    expect($more)->toBeInstanceOf(ActionGroup::class)
        ->and(array_keys($more->getFlatActions()))->toBe(['withdraw', 'history', 'restore', 'archive'])
        ->and($actions->first()->getColor())->toBe('gray');
});
