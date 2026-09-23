<?php

use App\Enums\HelpTopicDomain;
use App\Livewire\Dashboard\MediaLibraryIndex;
use App\Livewire\Dashboard\RecipesIndex;
use App\Livewire\Dashboard\SettingsIndex;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows published page help and excludes newer drafts', function (string $component, string $key): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $user->update(['active_workspace_id' => $workspace->id]);
    $this->actingAs($user);
    $topic = HelpTopic::factory()->create(['key' => $key, 'domain' => HelpTopicDomain::Application]);
    $locale = HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $published = HelpTopicRevision::factory()->for($locale, 'topicLocale')->create(['title' => 'Published page guidance']);
    $draft = HelpTopicRevision::factory()->for($locale, 'topicLocale')->create(['revision_number' => 2, 'title' => 'Private page draft']);
    $locale->update(['published_revision_id' => $published->id, 'latest_revision_id' => $draft->id]);

    Livewire::test($component)->assertSee('data-help-index', false)
        ->assertSee('data-contextual-help-heading', false)
        ->assertSee('data-contextual-help-scope', false)
        ->assertSee('Published page guidance')->assertDontSee('Private page draft')
        ->assertViewHas('contextualHelp', fn (array $help): bool => $help['tabs']['page'] === [$key]);
})->with([
    'products' => [RecipesIndex::class, 'products.getting_started'],
    'media library' => [MediaLibraryIndex::class, 'media.uploading'],
    'preferences' => [SettingsIndex::class, 'settings.language'],
]);

it('updates settings help with the selected tab and hides unpublished or disabled help', function (): void {
    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create();
    $this->actingAs($user);
    $topic = HelpTopic::factory()->create(['key' => 'settings.workspace', 'domain' => HelpTopicDomain::Application]);
    $locale = HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $revision = HelpTopicRevision::factory()->for($locale, 'topicLocale')->create();
    $locale->update(['latest_revision_id' => $revision->id]);
    $page = Livewire::test(SettingsIndex::class)->set('activeTab', 'workspace')->assertDontSee('data-help-index', false);
    $locale->update(['published_revision_id' => $revision->id]);

    $page->call('$refresh')->assertSee('data-help-index', false)
        ->assertViewHas('contextualHelp', fn (array $help): bool => $help['tabs']['page'] === ['settings.workspace'])
        ->set('activeTab', 'preferences')->assertDontSee('data-help-index', false);
    config(['contextual-help.enabled' => false]);
    $page->set('activeTab', 'workspace')->assertDontSee('data-help-index', false);
});
