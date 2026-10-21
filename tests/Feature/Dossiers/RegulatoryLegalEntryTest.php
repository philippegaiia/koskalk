<?php

use App\Enums\LegalEntryReviewStatus;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegime;
use App\Models\Substance;
use App\Models\User;
use App\Services\Dossiers\RegulatoryLegalEntryReviewer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('lets one substance carry several legal entries in the same regime', function (): void {
    $regime = RegulatoryRegime::factory()->create(['code' => 'eu']);
    $limonene = Substance::factory()->create(['name' => 'Limonene']);

    foreach (['III/88', 'III/167', 'III/168'] as $reference) {
        RegulatoryLegalEntry::factory()->for($regime)->create(['entry_reference' => $reference])->substances()->attach($limonene);
    }

    expect($limonene->legalEntries()->count())->toBe(3);
});

it('marks a complete draft as reviewed by an admin', function (): void {
    $admin = User::factory()->admin()->create();
    $entry = RegulatoryLegalEntry::factory()->create(['entry_reference' => 'III/84', 'annex' => 'III']);
    $entry->substances()->attach(Substance::factory()->create());

    app(RegulatoryLegalEntryReviewer::class)->markReviewed($admin, $entry);

    expect($entry->refresh()->review_status)->toBe(LegalEntryReviewStatus::Reviewed)
        ->and($entry->reviewed_by_user_id)->toBe($admin->id);
});

it('refuses to review an incomplete entry', function (array $attributes, bool $withSubstance, string $errorKey): void {
    $entry = RegulatoryLegalEntry::factory()->create($attributes);

    if ($withSubstance) {
        $entry->substances()->attach(Substance::factory()->create());
    }

    try {
        app(RegulatoryLegalEntryReviewer::class)->markReviewed(User::factory()->admin()->create(), $entry);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($errorKey);
    }
})->with([
    'no substance' => [[], false, 'substances'],
    'no source' => [['source_reference' => ' '], true, 'source_reference'],
    'reference outside its annex' => [['annex' => 'IV', 'entry_reference' => 'III/84'], true, 'entry_reference'],
    'conditions scope without conditions' => [['product_scope' => 'see_conditions', 'product_type_body_parts' => null], true, 'product_type_body_parts'],
]);

it('refuses review by a non-admin', function (): void {
    $entry = RegulatoryLegalEntry::factory()->create();
    $entry->substances()->attach(Substance::factory()->create());

    app(RegulatoryLegalEntryReviewer::class)->markReviewed(User::factory()->create(), $entry);
})->throws(AuthorizationException::class);

it('freezes a reviewed entry except for its end date', function (): void {
    $entry = RegulatoryLegalEntry::factory()->reviewed()->create();

    $entry->update(['effective_until' => '2027-12-31']);

    expect($entry->refresh()->effective_until->toDateString())->toBe('2027-12-31')
        ->and(fn () => $entry->update(['max_concentration_percent' => '1']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('rejects an update from a stale draft model once the stored entry is reviewed', function (): void {
    $stale = RegulatoryLegalEntry::factory()->create(['substance_description' => 'Original']);
    RegulatoryLegalEntry::query()->whereKey($stale->getKey())->update(['review_status' => LegalEntryReviewStatus::Reviewed->value]);

    expect(fn () => $stale->update(['substance_description' => 'Changed']))->toThrow(LogicException::class);
});
