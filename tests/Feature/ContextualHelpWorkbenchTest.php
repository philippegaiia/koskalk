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
    $specificTopic = HelpTopic::factory()->create(['key' => 'soap.qualities.cure']);
    $specificLocale = HelpTopicLocale::factory()->create(['help_topic_id' => $specificTopic->id]);
    $specificRevision = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $specificLocale->id]);
    $specificLocale->update(['latest_revision_id' => $specificRevision->id, 'published_revision_id' => $specificRevision->id]);
    $payload = app(RecipeWorkbenchViewDataBuilder::class)->build($family, null, null);
    expect($payload['contextualHelp']['topics']['soap.water_mode']['title'])->toBe('Published answer')
        ->and(json_encode($payload))->not->toContain('Private draft')
        ->and(array_keys($payload['contextualHelp']['tabs']))->toBe(['formula', 'output'])
        ->and($payload['contextualHelp']['tabs']['formula'])->toBe(['soap.water_mode'])
        ->and(array_keys($payload['contextualHelp']['topics']))->toContain('soap.qualities.cure');
});

it('keeps the Formula index short while retaining specific topics for contextual triggers', function () {
    $topics = app(WorkbenchHelpTopics::class);
    foreach (['soap', 'cosmetic'] as $family) {
        $scope = $topics->forSurface($family, true);
        expect($scope['index']['formula'])->toHaveCount(7)->toContain('shared.saving_and_history')
            ->and(array_diff($scope['index']['formula'], $scope['keys']))->toBe([]);
        expect($topics->forSurface($family, false)['index']['formula'])->toHaveCount(6)->not->toContain('shared.saving_and_history');
    }
    $soap = $topics->forSurface('soap', true);
    expect($soap['keys'])->toContain('soap.qualities.cure', 'soap.qualities.dos', 'soap.fatty_acids')
        ->and($soap['index']['formula'])->not->toContain('soap.qualities.cure', 'soap.qualities.dos', 'soap.fatty_acids')
        ->and($topics->locations('soap.qualities.cure'))->toContain('Soap · Formula');
});

it('renders a labelled Help button for the top bar while retaining the workbench tab context', function () {
    $html = view('livewire.dashboard.partials.recipe-workbench.header', [
        'workbench' => [],
        'contextualHelp' => ['topics' => ['soap.water_mode' => ['title' => 'Water mode']], 'tabs' => ['formula' => ['soap.water_mode']]],
    ])->render();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $button = (new DOMXPath($document))->query('//button[@data-help-index]')->item(0);
    expect($button)->not->toBeNull()
        ->and(trim($button->textContent))->toBe('Help')
        ->and($button->parentNode->getAttribute('x-teleport'))->toBe('#contextual-help-topbar')
        ->and($button->getAttribute('x-show'))->toContain('activeWorkbenchTab')
        ->and($button->getAttribute('class'))->not->toContain('sk-btn-outline', 'shadow-sm', 'float-right')
        ->and($button->getElementsByTagName('svg')->length)->toBe(1)
        ->and($button->hasAttribute('disabled'))->toBeFalse();
});
