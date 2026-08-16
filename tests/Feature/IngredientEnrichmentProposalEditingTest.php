<?php

use App\Actions\IngredientEnrichment\EditIngredientEnrichmentProposal;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientIntakeItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeBatch;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentPlanner;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentSubjectBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('edits an allow-listed proposal while preserving the original research audit', function (): void {
    config()->set('interface-translations.catalogue_locales', []);
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'argan_oil_edit',
        'category' => IngredientCategory::Other,
        'display_name' => 'Argan oil',
        'info_markdown' => null,
    ]);
    $fingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = editableEnrichmentResult($ingredient, $fingerprint);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
        'total_count' => 1,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Ready,
        'snapshot' => ['source_fingerprint' => $fingerprint],
        'source_fingerprint' => $fingerprint,
        'result' => $result,
        'original_result' => null,
        'plan' => app(IngredientEnrichmentPlanner::class)->plan($ingredient, $result),
    ]);
    $proposal = $result['proposal'];
    $proposal['display_name'] = 'Cold-pressed argan oil';
    $proposal['info_markdown'] = "## Overview\nA reviewed cosmetic ingredient.\n\n## Formulation use\nUsed as an emollient in cosmetic formulations.";

    $edited = app(EditIngredientEnrichmentProposal::class)->handle($admin, $item, $proposal);

    expect(data_get($edited->original_result, 'proposal.display_name'))->toBe('Argan Oil')
        ->and(data_get($edited->result, 'proposal.display_name'))->toBe('Cold-pressed argan oil')
        ->and($edited->edited_fields)->toBe([
            'proposal.display_name',
            'proposal.info_markdown',
        ])
        ->and($edited->edited_by_user_id)->toBe($admin->id)
        ->and($edited->edited_at)->not->toBeNull()
        ->and($edited->status)->toBe(IngredientEnrichmentItemStatus::Warning)
        ->and(collect($edited->result['value_provenance'])->firstWhere('field', 'proposal.display_name')['kind'])->toBe('reviewer_supplied')
        ->and(collect($edited->result['value_provenance'])->firstWhere('field', 'proposal.info_markdown')['kind'])->toBe('reviewer_supplied')
        ->and($ingredient->fresh()->display_name)->toBe('Argan oil');
});

it('keeps field confidence and evidence aligned when a source-backed row is edited', function (): void {
    config()->set('interface-translations.catalogue_locales', []);
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'argan_oil_identifier_edit',
        'category' => IngredientCategory::Other,
    ]);
    $fingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = editableEnrichmentResult($ingredient, $fingerprint);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
        'total_count' => 1,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $fingerprint,
        'result' => $result,
    ]);
    $proposal = $result['proposal'];
    $proposal['identifiers'] = [[
        'scheme' => 'unii',
        'value' => '4V59G5UW9X',
        'is_primary' => true,
        ...enrichmentProposalSource(
            'FDA Global Substance Registration System',
            'https://precision.fda.gov/uniisearch/srs/unii/4V59G5UW9X',
            'verified',
            'GSRS',
        ),
    ]];

    $edited = app(EditIngredientEnrichmentProposal::class)->handle($admin, $item, $proposal);

    expect(collect($edited->result['evidence'])->firstWhere('field', 'proposal.identifiers.0'))->toMatchArray([
        'source_name' => 'FDA Global Substance Registration System',
        'source_url' => 'https://precision.fda.gov/uniisearch/srs/unii/4V59G5UW9X',
        'confidence' => 'verified',
    ])->and(collect($edited->result['field_confidence'])->firstWhere('field', 'proposal.identifiers.0'))->toBe([
        'field' => 'proposal.identifiers.0',
        'confidence' => 'verified',
    ])->and($edited->edited_fields)->toContain('proposal.identifiers.0');
});

it('returns a rejected proposal to review and clears the old decision audit', function (): void {
    config()->set('interface-translations.catalogue_locales', []);
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'rejected_edit_oil',
        'category' => IngredientCategory::Other,
    ]);
    $fingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = editableEnrichmentResult($ingredient, $fingerprint);
    $batch = IngredientEnrichmentBatch::factory()->create();
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Rejected,
        'source_fingerprint' => $fingerprint,
        'result' => $result,
        'rejected_by_user_id' => $admin->id,
        'rejected_at' => now(),
        'rejection_reason' => 'Needs a corrected material identity.',
    ]);
    $proposal = $result['proposal'];
    $proposal['display_name'] = 'Corrected rejected oil';

    $edited = app(EditIngredientEnrichmentProposal::class)->handle($admin, $item, $proposal);

    expect($edited->status)->toBeIn([IngredientEnrichmentItemStatus::Ready, IngredientEnrichmentItemStatus::Warning])
        ->and($edited->approved_by_user_id)->toBeNull()
        ->and($edited->approved_at)->toBeNull()
        ->and($edited->rejected_by_user_id)->toBeNull()
        ->and($edited->rejected_at)->toBeNull()
        ->and($edited->rejection_reason)->toBeNull();
});

it('persists the stale status when the ingredient changed before proposal editing', function (): void {
    config()->set('interface-translations.catalogue_locales', []);
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'argan_oil_stale_edit',
        'category' => IngredientCategory::Other,
    ]);
    $fingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = editableEnrichmentResult($ingredient, $fingerprint);
    $batch = IngredientEnrichmentBatch::factory()->create();
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $fingerprint,
        'result' => $result,
    ]);
    $ingredient->update(['display_name' => 'Changed after research']);

    expect(fn () => app(EditIngredientEnrichmentProposal::class)->handle($admin, $item, $result['proposal']))
        ->toThrow(ValidationException::class)
        ->and($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Stale);
});

it('edits an unpromoted intake proposal without requiring a catalogue ingredient', function (): void {
    config()->set('interface-translations.catalogue_locales', []);
    $admin = User::factory()->create(['is_admin' => true]);
    $linked = Ingredient::factory()->create([
        'catalog_key' => 'intake_linked_oil',
        'category' => IngredientCategory::Other,
    ]);
    $intakeBatch = IngredientIntakeBatch::factory()->create();
    $intakeItem = IngredientIntakeItem::factory()->for($intakeBatch, 'batch')->create([
        'status' => IngredientIntakeItemStatus::Draft,
        'original_current_name' => 'Argan oil',
        'normalized_current_name' => 'argan oil',
    ]);
    $subject = app(IngredientEnrichmentSubjectBuilder::class)->forIntake($intakeItem->fresh());
    $result = editableEnrichmentResult($linked, $subject->fingerprint);
    $result['schema_version'] = config('ingredient-enrichment.schema_version');
    $result['subject_type'] = 'intake';
    $result['subject_public_id'] = (string) $intakeItem->public_id;
    $result['catalog_key'] = null;
    $result['source_fingerprint'] = $subject->fingerprint;
    $result['value_provenance'] = collect([
        'display_name', 'inci_name', 'category', 'subcategory', 'saponification_name',
        'soap_inci_naoh_name', 'soap_inci_koh_name', 'info_markdown', 'soapmaking_relevant',
    ])->map(fn (string $field): array => [
        'field' => "proposal.{$field}",
        'kind' => $field === 'inci_name' ? 'source_confirmed' : 'ai_proposed',
        'reasoning' => 'Recorded for the intake reviewer.',
        'source_urls' => $field === 'inci_name'
            ? ['https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175']
            : [],
    ])->merge([
        ['field' => 'proposal.market_labels.0', 'kind' => 'source_confirmed', 'reasoning' => 'Recorded for the intake reviewer.', 'source_urls' => ['https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175']],
        ['field' => 'proposal.market_labels.1', 'kind' => 'source_confirmed', 'reasoning' => 'Recorded for the intake reviewer.', 'source_urls' => ['https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names']],
    ])->all();
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
        'mode' => 'intake',
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'ingredient_id' => null,
        'ingredient_intake_item_id' => $intakeItem->id,
        'catalog_key' => null,
        'status' => IngredientEnrichmentItemStatus::Ready,
        'snapshot' => ['subject_type' => 'intake', 'subject_public_id' => (string) $intakeItem->public_id],
        'source_fingerprint' => $subject->fingerprint,
        'result' => $result,
    ]);
    $proposal = $result['proposal'];
    $proposal['display_name'] = 'Reviewed argan oil';

    $edited = app(EditIngredientEnrichmentProposal::class)->handle($admin, $item, $proposal);

    expect(data_get($edited->result, 'proposal.display_name'))->toBe('Reviewed argan oil')
        ->and($edited->edited_by_user_id)->toBe($admin->id)
        ->and($edited->status)->toBeIn([
            IngredientEnrichmentItemStatus::Ready,
            IngredientEnrichmentItemStatus::Warning,
        ])
        ->and($linked->fresh()->display_name)->not->toBe('Reviewed argan oil');
});

/** @return array<string, mixed> */
function editableEnrichmentResult(Ingredient $ingredient, string $fingerprint): array
{
    $eu = enrichmentProposalSource(
        'EUR-Lex Common Ingredient Names Glossary',
        'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175',
        'verified',
        '32025D1175',
    );
    $us = enrichmentProposalSource(
        'FDA cosmetic ingredient naming guidance',
        'https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names',
        'supported',
        '21 CFR 701.3',
    );

    return [
        'format' => 'soapkraft-platform-ingredient-enrichment-result',
        'schema_version' => 2,
        'catalog_key' => $ingredient->catalog_key,
        'source_fingerprint' => $fingerprint,
        'proposal' => [
            'display_name' => 'Argan Oil',
            'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
            'category' => 'other',
            'subcategory' => null,
            'saponification_name' => 'Argan Oil',
            'info_markdown' => "## Overview\nA cosmetic ingredient.\n\n## Formulation use\nUsed as an emollient in cosmetic formulations.",
            'soapmaking_relevant' => false,
            'identifiers' => [],
            'cosing_functions' => [],
            'translations' => [],
            'market_labels' => [
                [
                    'market_code' => 'eu',
                    'declaration_name' => 'ARGANIA SPINOSA KERNEL OIL',
                    'reviewed_at' => null,
                    'effective_from' => null,
                    'effective_until' => null,
                    ...$eu,
                ],
                [
                    'market_code' => 'us',
                    'declaration_name' => 'Argan Oil',
                    'reviewed_at' => null,
                    'effective_from' => null,
                    'effective_until' => null,
                    ...$us,
                ],
            ],
        ],
        'field_confidence' => [
            ['field' => 'proposal.inci_name', 'confidence' => 'verified'],
            ['field' => 'proposal.display_name', 'confidence' => 'supported'],
            ['field' => 'proposal.saponification_name', 'confidence' => 'supported'],
            ['field' => 'proposal.info_markdown', 'confidence' => 'supported'],
            ['field' => 'proposal.soapmaking_relevant', 'confidence' => 'supported'],
            ['field' => 'proposal.market_labels.0', 'confidence' => 'verified'],
            ['field' => 'proposal.market_labels.1', 'confidence' => 'supported'],
        ],
        'evidence' => [
            ['field' => 'proposal.inci_name', ...$eu],
            ['field' => 'proposal.market_labels.0', ...$eu],
            ['field' => 'proposal.market_labels.1', ...$us],
        ],
        'regulatory_findings' => [],
        'confidence' => 'medium',
        'warnings' => [],
        'unresolved_questions' => [],
    ];
}

/** @return array<string, mixed> */
function enrichmentProposalSource(string $name, string $url, string $confidence, string $version): array
{
    return [
        'source_name' => $name,
        'source_url' => $url,
        'source_tier' => 'official',
        'confidence' => $confidence,
        'source_version' => $version,
        'source_updated_at' => null,
        'retrieved_at' => '2026-08-14T12:00:00+00:00',
    ];
}
