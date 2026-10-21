<?php

use App\Enums\HelpTranslationRequestStatus as Status;
use App\Filament\Resources\HelpTopics\Pages\ListHelpTopics;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function listHelpEnglish(bool $published = false): HelpTopicLocale
{
    $english = HelpTopicLocale::factory()->create();
    $revision = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id]);
    $english->update(['latest_revision_id' => $revision->id, 'published_revision_id' => $published ? $revision->id : null]);

    return $english;
}

it('shows all 97 topics together so the header checkbox can select the whole import', function (): void {
    $topics = HelpTopic::factory()->count(97)->create();
    $this->actingAs(User::factory()->admin()->create());

    $page = Livewire::test(ListHelpTopics::class)->loadTable()->assertCanSeeTableRecords($topics);

    expect($page->instance()->getTableRecords())->toHaveCount(97);
});

it('filters English publication independently from translations', function (string $filter, string $expected): void {
    $draft = listHelpEnglish();
    $published = listHelpEnglish(true);
    $changed = listHelpEnglish(true);
    $next = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $changed->id, 'revision_number' => 2]);
    $changed->update(['latest_revision_id' => $next->id]);
    $missing = HelpTopic::factory()->create();
    $records = collect(['draft' => $draft->topic, 'published' => $published->topic, 'changed' => $changed->topic, 'missing' => $missing]);
    $visible = $filter === 'unpublished' ? $records->only(['draft', 'missing']) : $records->only([$expected]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListHelpTopics::class)->loadTable()->filterTable('publication', $filter)
        ->assertCanSeeTableRecords($visible)->assertCanNotSeeTableRecords($records->except($visible->keys()->all()));
})->with(['unpublished' => ['unpublished', 'draft'], 'published' => ['published', 'published'], 'changes' => ['changes', 'changed']]);

it('shows translation requests before any translated draft exists', function (Status $status, string $expected): void {
    $english = listHelpEnglish(true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    HelpTranslationRequest::factory()->create(['help_topic_locale_id' => $target->id, 'source_english_revision_id' => $english->latest_revision_id, 'status' => $status]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListHelpTopics::class)->loadTable()->assertSee($expected);
})->with([
    'queued' => [Status::Pending, '1 queued'],
    'running' => [Status::Running, '1 running'],
    'candidate awaiting review' => [Status::Completed, '1 need review'],
    'failed' => [Status::Failed, '1 failed'],
]);

it('does not show an old failed request after its replacement completes', function (): void {
    $english = listHelpEnglish(true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    HelpTranslationRequest::factory()->create(['help_topic_locale_id' => $target->id, 'source_english_revision_id' => $english->latest_revision_id, 'status' => Status::Failed]);
    HelpTranslationRequest::factory()->create(['help_topic_locale_id' => $target->id, 'source_english_revision_id' => $english->latest_revision_id, 'status' => Status::Completed]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListHelpTopics::class)->loadTable()->assertSee('1 need review')->assertDontSee('1 failed');
});

it('shows the worker queue when pending translations have not started', function (): void {
    $this->freezeTime();
    config(['contextual-help.queue' => 'ingredient-enrichment']);
    HelpTranslationRequest::factory()->create(['status' => Status::Pending, 'created_at' => now()->subMinutes(3)]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListHelpTopics::class)->loadTable()->assertSee('ingredient-enrichment')->assertSee('Forge worker');
});

it('updates the visible translation progress as a request runs and completes', function (): void {
    $english = listHelpEnglish(true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    $request = HelpTranslationRequest::factory()->create(['help_topic_locale_id' => $target->id, 'source_english_revision_id' => $english->latest_revision_id, 'status' => Status::Pending]);
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test(ListHelpTopics::class)->loadTable()->assertSee('1 queued');
    $request->update(['status' => Status::Running, 'started_at' => now()]);

    $page->call('$refresh')->assertSee('1 running')->assertDontSee('1 queued');
    $request->update(['status' => Status::Completed, 'completed_at' => now()]);
    $page->call('$refresh')->assertSee('1 need review')->assertDontSee('1 running');
});

it('shows a failed translation error with its language', function (): void {
    $english = listHelpEnglish(true);
    $target = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $english->help_topic_id]);
    HelpTranslationRequest::factory()->create(['help_topic_locale_id' => $target->id, 'source_english_revision_id' => $english->latest_revision_id, 'status' => Status::Failed, 'error_message' => 'The translation worker expired.']);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListHelpTopics::class)->loadTable()->assertSee('1 failed')->assertSee('FR: The translation worker expired.');
});

it('offers 100 rows even when the administrator previously chose 50', function (): void {
    $topics = HelpTopic::factory()->count(97)->create();
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test(ListHelpTopics::class)->loadTable()->set('tableRecordsPerPage', 50);
    expect($page->instance()->getTableRecords())->toHaveCount(50);

    $page->set('tableRecordsPerPage', 100)->assertCanSeeTableRecords($topics);

    expect($page->instance()->getTableRecords())->toHaveCount(97);
});
