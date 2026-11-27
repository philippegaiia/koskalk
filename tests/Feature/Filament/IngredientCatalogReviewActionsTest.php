<?php

use App\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Models\Ingredient;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('marks the selected ingredients reviewed from the list', function (): void {
    $admin = User::factory()->admin()->create();
    $selected = Ingredient::factory()->count(2)->create(['requires_admin_review' => true]);
    $untouched = Ingredient::factory()->create(['requires_admin_review' => true]);
    $this->actingAs($admin);

    Livewire::test(ListIngredients::class)
        ->loadTable()
        ->selectTableRecords($selected->pluck('id')->all())
        ->callAction(TestAction::make('markCatalogReviewed')->table()->bulk())
        ->assertNotified('2 ingredients marked reviewed.');

    expect(Ingredient::query()->whereKey($selected->pluck('id'))->pluck('catalog_reviewed_by_user_id')->all())
        ->toBe([$admin->id, $admin->id])
        ->and($untouched->fresh()->requires_admin_review)->toBeTrue();
});

it('lists only ingredients ready for review under that step', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    config()->set('interface-translations.catalogue_locales', []);
    $ready = Ingredient::factory()->create(['requires_admin_review' => true, 'info_markdown' => '## Overview']);
    $missingGuidance = Ingredient::factory()->create(['requires_admin_review' => true, 'info_markdown' => null]);
    $reviewed = Ingredient::factory()->create(['requires_admin_review' => false, 'info_markdown' => '## Overview']);

    Livewire::test(ListIngredients::class)
        ->loadTable()
        ->filterTable('catalog_review_step', 'awaiting_review')
        ->assertCanSeeTableRecords([$ready])
        ->assertCanNotSeeTableRecords([$missingGuidance, $reviewed]);
});

it('marks the open ingredient reviewed and updates the Needs review toggle', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create(['requires_admin_review' => true]);
    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->callAction('markCatalogReviewed')
        ->assertSchemaStateSet(['requires_admin_review' => false])
        ->assertActionHidden('markCatalogReviewed');

    expect($ingredient->fresh())
        ->requires_admin_review->toBeFalse()
        ->catalog_reviewed_by_user_id->toBe($admin->id);
});
