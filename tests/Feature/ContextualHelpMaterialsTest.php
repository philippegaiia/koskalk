<?php

use App\Enums\HelpTopicDomain;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Filament\Resources\HelpTopics\Pages\EditHelpTopic;
use App\Filament\Resources\HelpTopics\Pages\ListHelpTopics;
use App\Livewire\Dashboard\IngredientEditor;
use App\Livewire\Dashboard\IngredientsIndex;
use App\Livewire\Dashboard\PackagingItemEditor;
use App\Livewire\Dashboard\PackagingItemsIndex;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\MaterialHelpTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function publishedMaterialHelp(string $key): HelpTopicLocale
{
    $topic = HelpTopic::factory()->create(['key' => $key, 'domain' => HelpTopicDomain::MaterialLibrary]);
    $locale = HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $revision = HelpTopicRevision::factory()->for($locale, 'topicLocale')->create(['title' => 'Published material guidance']);
    $locale->update(['latest_revision_id' => $revision->id, 'published_revision_id' => $revision->id]);

    return $locale;
}

it('keeps material help menus relevant and limited to six topics', function (): void {
    $topics = app(MaterialHelpTopics::class);
    expect($topics->forSurface('ingredients')['index'])->toHaveCount(6)->toContain('ingredients.duplicate', 'materials.prices');
    expect($topics->forSurface('ingredient')['index'])->toHaveCount(6)->not->toContain('ingredients.soap_chemistry');
    expect($topics->forSurface('ingredient')['keys'])->toContain('ingredients.soap_chemistry');
    expect($topics->forSurface('packaging')['index'])->toHaveCount(5)->not->toContain('ingredients.composition');
    expect($topics->forSurface('unknown'))->toBe(['keys' => [], 'index' => []]);
});

it('renders published help by the title on material pages without exposing newer drafts', function (Closure $surface, string $key): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $user->update(['active_workspace_id' => $workspace->id]);
    $this->actingAs($user);
    $locale = publishedMaterialHelp($key);
    $draft = HelpTopicRevision::factory()->for($locale, 'topicLocale')->create(['title' => 'Private material draft']);
    $locale->update(['latest_revision_id' => $draft->id]);
    [$class, $parameters] = $surface($workspace);

    Livewire::test($class, $parameters)->assertSee('data-help-index', false)
        ->assertSee('data-contextual-help-heading', false)
        ->assertSee('data-contextual-help-scope', false)
        ->assertSee('Published material guidance')->assertDontSee('Private material draft')
        ->assertViewHas('contextualHelp', fn (array $help): bool => $help['tabs']['materials'] === [$key]);
})->with([
    'ingredients' => [fn (Workspace $workspace): array => [IngredientsIndex::class, []], 'ingredients.catalogue'],
    'new ingredient' => [fn (Workspace $workspace): array => [IngredientEditor::class, []], 'ingredients.identity'],
    'platform reference' => [fn (Workspace $workspace): array => [IngredientEditor::class, ['ingredient' => Ingredient::factory()->create()]], 'ingredients.identity'],
    'private ingredient' => [fn (Workspace $workspace): array => [IngredientEditor::class, ['ingredient' => Ingredient::factory()->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'visibility' => Visibility::Private])]], 'ingredients.identity'],
    'packaging' => [fn (Workspace $workspace): array => [PackagingItemsIndex::class, []], 'packaging.library'],
    'new packaging' => [fn (Workspace $workspace): array => [PackagingItemEditor::class, []], 'packaging.library'],
    'edit packaging' => [fn (Workspace $workspace): array => [PackagingItemEditor::class, ['packagingItem' => PackagingItem::factory()->for($workspace)->create()]], 'packaging.library'],
]);

it('resolves section help once per ingredient editor render', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $user->update(['active_workspace_id' => $workspace->id]);
    $this->actingAs($user);
    foreach (['ingredients.identity', 'ingredients.classification', 'materials.codes'] as $key) {
        publishedMaterialHelp($key);
    }
    $component = Livewire::test(IngredientEditor::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $component->call('$refresh')->assertSee('data-help-key="ingredients.identity"', false)
            ->assertSee('data-help-key="ingredients.classification"', false)
            ->assertSee('data-help-key="materials.codes"', false);
        expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "help_topic')))->toHaveCount(3);
    } finally {
        DB::disableQueryLog();
    }
});

it('hides unpublished and disabled material help', function (): void {
    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create();
    $this->actingAs($user);
    $locale = publishedMaterialHelp('packaging.library');
    $locale->update(['published_revision_id' => null]);
    Livewire::test(PackagingItemsIndex::class)->assertDontSee('data-help-index', false);
    $locale->update(['published_revision_id' => $locale->latest_revision_id]);
    config(['contextual-help.enabled' => false]);
    Livewire::test(PackagingItemsIndex::class)->assertDontSee('data-help-index', false);
});

it('exposes material help filters and locations in the admin editor', function (): void {
    $locale = publishedMaterialHelp('ingredients.composition');
    $other = HelpTopic::factory()->create(['key' => 'soap.water_mode']);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListHelpTopics::class)->filterTable('domain', 'material_library')
        ->assertCanSeeTableRecords([$locale->topic])->assertCanNotSeeTableRecords([$other]);
    $page = Livewire::test(EditHelpTopic::class, ['record' => $locale->topic->public_id]);
    expect($page->instance()->editorReference()['locations'])->toBe(['Ingredient details']);
});
