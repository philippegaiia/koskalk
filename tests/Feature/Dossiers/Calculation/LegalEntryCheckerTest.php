<?php

use App\Models\Allergen;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use App\Models\Substance;
use App\Services\Dossiers\Calculation\LegalEntryChecker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function legalEntryFor(Substance $substance, array $attributes, bool $reviewed = true): RegulatoryLegalEntry
{
    $regime = RegulatoryRegime::query()->firstWhere('code', 'eu') ?? RegulatoryRegime::factory()->create(['code' => 'eu']);
    $factory = RegulatoryLegalEntry::factory();
    $entry = ($reviewed ? $factory->reviewed() : $factory)->create(['regulatory_regime_id' => $regime->id, ...$attributes]);
    $entry->substances()->attach($substance->id);

    return $entry;
}

function legalCheckDate(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-23');
}

it('makes a banned or over-limit substance a binding breach and passes exactly at the limit', function (array $attributes, string $concentration, bool $breach): void {
    $substance = Substance::factory()->create();
    legalEntryFor($substance, $attributes);

    $result = app(LegalEntryChecker::class)->check($substance, $concentration, true, legalCheckDate());

    expect($result['binding_breach'])->toBe($breach)
        ->and($result['entries'])->toHaveCount(1);
})->with([
    'annex II present' => [['annex' => 'II'], '0.0001', true],
    'annex III above the maximum' => [['annex' => 'III', 'max_concentration_percent' => '0.5'], '0.500001', true],
    'annex III exactly at the maximum' => [['annex' => 'III', 'max_concentration_percent' => '0.5'], '0.5', false],
]);

it('follows the product scope and flags conditions it cannot check', function (): void {
    $substance = Substance::factory()->create();
    legalEntryFor($substance, ['annex' => 'III', 'entry_reference' => 'III/1', 'product_scope' => 'leave_on_only', 'max_concentration_percent' => '0.01']);
    legalEntryFor($substance, ['annex' => 'III', 'entry_reference' => 'III/2', 'product_scope' => 'see_conditions', 'label_warnings_fr' => 'Éviter le contact avec les yeux.']);

    $result = app(LegalEntryChecker::class)->check($substance, '1', true, legalCheckDate());

    expect($result['binding_breach'])->toBeFalse()
        ->and($result['needs_verification'])->toBeTrue()
        ->and(collect($result['entries'])->pluck('applies')->all())->toBe([false, true])
        ->and($result['label_warnings_fr'])->toBe(['Éviter le contact avec les yeux.']);
});

it('ignores draft and expired entries but reports the drafts', function (): void {
    $substance = Substance::factory()->create();
    $draft = legalEntryFor($substance, ['annex' => 'II'], reviewed: false);
    legalEntryFor($substance, ['annex' => 'II', 'effective_until' => '2025-12-31']);

    $result = app(LegalEntryChecker::class)->check($substance, '1', true, legalCheckDate());

    expect($result['binding_breach'])->toBeFalse()
        ->and($result['entries'])->toBe([])
        ->and($result['unreviewed_entry_ids'])->toBe([$draft->id]);
});

it('decides the label declaration of an allergen from its final concentration', function (string $concentration, bool $mustDeclare): void {
    $regime = RegulatoryRegime::factory()->create(['code' => 'eu']);
    $allergen = Allergen::factory()->create();
    RegulatoryRegimeAllergen::factory()->create([
        'regulatory_regime_id' => $regime->id,
        'allergen_id' => $allergen->id,
        'rinse_off_threshold_percent' => 0.01,
        'leave_on_threshold_percent' => 0.001,
        'threshold_operator' => 'greater_than',
        'is_active' => true,
    ]);

    $declaration = app(LegalEntryChecker::class)->allergenDeclaration($allergen->id, $concentration, true, legalCheckDate());

    expect($declaration['must_declare'])->toBe($mustDeclare);
})->with([
    'above the rinse-off threshold' => ['0.0101', true],
    'exactly at a "greater than" threshold' => ['0.01', false],
]);

it('applies an entry through the whole of its first and last effective day', function (string $evaluatedAt): void {
    $substance = Substance::factory()->create();
    legalEntryFor($substance, ['annex' => 'II', 'effective_from' => '2026-10-23', 'effective_until' => '2026-10-23']);

    $result = app(LegalEntryChecker::class)->check($substance, '1', true, CarbonImmutable::parse($evaluatedAt));

    expect($result['binding_breach'])->toBeTrue();
})->with([
    'midnight' => ['2026-10-23 00:00:00'],
    'noon' => ['2026-10-23 12:00:00'],
    'last minute' => ['2026-10-23 23:59:00'],
]);
