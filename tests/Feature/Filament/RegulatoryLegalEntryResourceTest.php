<?php

use App\Enums\LegalEntryReviewStatus;
use App\Filament\Resources\RegulatoryLegalEntries\Pages\CreateRegulatoryLegalEntry;
use App\Filament\Resources\RegulatoryLegalEntries\Pages\EditRegulatoryLegalEntry;
use App\Filament\Resources\RegulatoryLegalEntries\Pages\ListRegulatoryLegalEntries;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegime;
use App\Models\Substance;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('creates a draft entry linked to substances and reviews it from the list', function (): void {
    $linalool = Substance::factory()->create(['name' => 'Linalool']);

    Livewire::test(CreateRegulatoryLegalEntry::class)
        ->fillForm([
            'regulatory_regime_id' => RegulatoryRegime::factory()->create()->id,
            'annex' => 'III',
            'entry_reference' => 'III/84',
            'substance_description' => 'Linalool',
            'product_scope' => 'all_products',
            'source_reference' => 'Regulation (EC) No 1223/2009, Annex III, consolidated text',
            'substances' => [$linalool->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $entry = RegulatoryLegalEntry::query()->sole();

    expect($entry->review_status)->toBe(LegalEntryReviewStatus::Draft)
        ->and($entry->substances->pluck('id')->all())->toBe([$linalool->id]);

    Livewire::test(ListRegulatoryLegalEntries::class)
        ->callAction(TestAction::make('markReviewed')->table($entry));

    expect($entry->refresh()->isReviewed())->toBeTrue();
});

it('does not save edits to a reviewed entry', function (): void {
    $entry = RegulatoryLegalEntry::factory()->reviewed()->create(['substance_description' => 'Original']);

    Livewire::test(EditRegulatoryLegalEntry::class, ['record' => $entry->getKey()])
        ->fillForm(['substance_description' => 'Changed'])
        ->call('save');

    expect($entry->refresh()->substance_description)->toBe('Original');
});

it('sets the end date of a reviewed entry', function (): void {
    $entry = RegulatoryLegalEntry::factory()->reviewed()->create();

    Livewire::test(ListRegulatoryLegalEntries::class)
        ->callAction(TestAction::make('setEffectiveUntil')->table($entry), data: ['effective_until' => '2027-06-30']);

    expect($entry->refresh()->effective_until->toDateString())->toBe('2027-06-30');
});
