<?php

use App\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('offers a confirmed multi ingredient ai enrichment bulk action', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredients = Ingredient::factory()->count(2)->create();
    $this->actingAs($admin);

    Livewire::test(ListIngredients::class)
        ->loadTable()
        ->assertActionExists(TestAction::make('runAiEnrichment')->table()->bulk())
        ->selectTableRecords($ingredients->pluck('id')->all())
        ->mountAction(TestAction::make('runAiEnrichment')->table()->bulk())
        ->assertMountedActionModalSee('translations, identifiers, COSING functions, guidance, and colour labels');
});

it('starts one durable batch for all selected ingredients', function (): void {
    config()->set('ingredient-enrichment.direct_ai.enabled', true);
    config()->set('ingredient-enrichment.openai.api_key', 'test-only');
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $ingredients = Ingredient::factory()->count(3)->create();
    $this->actingAs($admin);

    Livewire::test(ListIngredients::class)
        ->loadTable()
        ->selectTableRecords($ingredients->pluck('id')->all())
        ->callAction(TestAction::make('runAiEnrichment')->table()->bulk())
        ->assertHasNoFormErrors();

    expect(IngredientEnrichmentBatch::query()->count())->toBe(1)
        ->and(IngredientEnrichmentBatch::query()->firstOrFail()->items()->count())->toBe(3);
});
