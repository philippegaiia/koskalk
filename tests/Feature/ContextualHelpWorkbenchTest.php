<?php

use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\ProductFamily;
use App\Services\ContextualHelp\WorkbenchHelpTopics;
use App\Services\RecipeWorkbenchViewDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

it('limits help topics to the current family and visible tabs', function () {
    $topics = app(WorkbenchHelpTopics::class);
    $guest = $topics->forSurface('soap', false);
    expect(array_keys($guest['tabs']))->toBe(['formula', 'output'])
        ->and($guest['keys'])->toContain('soap.water_mode')->not->toContain('shared.saving_and_history', 'shared.packaging', 'cosmetic.phases');
    $cosmetic = $topics->forSurface('cosmetic', true);
    expect($cosmetic['keys'])->toContain('cosmetic.phases', 'shared.costing')->not->toContain('soap.water_mode');
});

it('renders a read only anchor for help within locked fieldsets', function () {
    $html = Blade::render('<x-contextual-help.trigger topic="soap.water_mode" :topics="$topics" />', ['topics' => ['soap.water_mode' => ['title' => 'Water mode']]]);
    expect($html)->toContain('role="button"', 'tabindex="0"', 'data-help-key="soap.water_mode"')->not->toContain('<button', 'disabled');
    $hidden = Blade::render('<x-contextual-help.trigger topic="soap.water_mode" :topics="[]" />');
    expect(trim($hidden))->toBe('');
});

it('sends only published family help to the workbench and keeps drafts out of the payload', function () {
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $topic = HelpTopic::factory()->create(['key' => 'soap.water_mode']);
    $locale = HelpTopicLocale::factory()->create(['help_topic_id' => $topic->id]);
    $published = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $locale->id, 'title' => 'Published answer']);
    $draft = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $locale->id, 'revision_number' => 2, 'title' => 'Private draft']);
    $locale->update(['latest_revision_id' => $draft->id, 'published_revision_id' => $published->id]);
    $payload = app(RecipeWorkbenchViewDataBuilder::class)->build($family, null, null);
    expect($payload['contextualHelp']['topics']['soap.water_mode']['title'])->toBe('Published answer')
        ->and(json_encode($payload))->not->toContain('Private draft')
        ->and(array_keys($payload['contextualHelp']['tabs']))->toBe(['formula', 'output']);
});
