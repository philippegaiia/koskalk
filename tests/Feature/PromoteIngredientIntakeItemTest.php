<?php

use App\Actions\IngredientEnrichment\ApplyApprovedIngredientEnrichment;
use App\Actions\IngredientEnrichment\ApproveIngredientEnrichmentItem;
use App\Actions\IngredientIntake\PromoteIngredientIntakeItem;
use App\Enums\IngredientCategory;
use App\Enums\IngredientDuplicateResolution;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientIntakeBatchStatus;
use App\Enums\IngredientIntakeItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeBatch;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientEnrichment\ApplyPlatformIngredientEnrichment;
use App\Services\IngredientEnrichment\IngredientEnrichmentSubjectBuilder;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('ingredient-enrichment.guidance.minimum_words', 1);
    $this->seed(SupportedLocaleSeeder::class);
});

it('keeps an intake row out of the catalogue until approval and promotion', function (): void {
    $admin = User::factory()->admin()->create();
    [$intakeBatch, $intakeItem, $enrichmentBatch, $enrichmentItem] = promotionIntakeFixture();

    expect(Ingredient::query()->count())->toBe(0);

    app(ApproveIngredientEnrichmentItem::class)->handle($admin, $enrichmentItem);

    expect($enrichmentItem->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Approved)
        ->and(Ingredient::query()->count())->toBe(0);

    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $enrichmentBatch);
    $promoted = $intakeItem->fresh()->promotedIngredient;

    expect($totals)->toBe(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and($intakeBatch->fresh()->status)->toBe(IngredientIntakeBatchStatus::ReadyForReview)
        ->and($intakeItem->fresh()->status)->toBe(IngredientIntakeItemStatus::Promoted)
        ->and($promoted)->toBeInstanceOf(Ingredient::class)
        ->and($promoted?->catalog_key)->toStartWith('ADM-')
        ->and($promoted?->display_name)->toBe('Coconut oil')
        ->and($promoted?->inci_name)->toBe('Cocos nucifera oil')
        ->and($promoted?->category)->toBe(IngredientCategory::Lipids)
        ->and($promoted?->subcategory?->value)->toBe('vegetable_oils')
        ->and($promoted?->requires_admin_review)->toBeFalse()
        ->and($promoted?->is_active)->toBeTrue()
        ->and($promoted?->taxonomy_source)->toBe('admin_reviewed_enrichment')
        ->and($promoted?->taxonomy_reviewed_by_user_id)->toBe($admin->id)
        ->and($enrichmentItem->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Applied)
        ->and($enrichmentItem->fresh()->catalog_key)->toBe($promoted?->catalog_key);
});

it('blocks approval when a new intake row is missing catalogue taxonomy', function (): void {
    $admin = User::factory()->admin()->create();
    [, $intakeItem, $enrichmentBatch, $enrichmentItem] = promotionIntakeFixture();
    $result = promotionIntakeResult($intakeItem, incomplete: true);
    $enrichmentItem->update(['result' => $result]);

    expect(fn () => app(ApproveIngredientEnrichmentItem::class)->handle($admin, $enrichmentItem))
        ->toThrow(ValidationException::class);

    expect($enrichmentItem->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and($enrichmentBatch->fresh()->approved_count)->toBe(0)
        ->and(Ingredient::query()->count())->toBe(0);
});

it('links an approved intake row to an existing ingredient without creating a duplicate', function (): void {
    $admin = User::factory()->admin()->create();
    $existing = Ingredient::factory()->create([
        'catalog_key' => 'COCONUT-EXISTING',
        'display_name' => 'Existing coconut oil',
        'inci_name' => 'Cocos nucifera oil',
        'category' => IngredientCategory::Lipids,
        'subcategory' => 'vegetable_oils',
    ]);
    [, $intakeItem, $enrichmentBatch, $enrichmentItem] = promotionIntakeFixture([
        'duplicate_resolution' => IngredientDuplicateResolution::ExistingIngredient,
        'existing_ingredient_id' => $existing->id,
    ]);
    $result = promotionIntakeResult($intakeItem);
    $enrichmentItem->update(['result' => $result]);

    app(ApproveIngredientEnrichmentItem::class)->handle($admin, $enrichmentItem);
    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $enrichmentBatch);

    expect($totals)->toBe(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and(Ingredient::query()->count())->toBe(1)
        ->and($intakeItem->fresh()->status)->toBe(IngredientIntakeItemStatus::LinkedExisting)
        ->and($intakeItem->fresh()->promoted_ingredient_id)->toBe($existing->id)
        ->and($enrichmentItem->fresh()->catalog_key)->toBe('COCONUT-EXISTING');
});

it('records the approving reviewer rather than the applying admin during promotion', function (): void {
    $approver = User::factory()->admin()->create();
    $applier = User::factory()->admin()->create();
    [, $intakeItem, , $enrichmentItem] = promotionIntakeFixture();

    $approved = app(ApproveIngredientEnrichmentItem::class)->handle($approver, $enrichmentItem);
    $approvedAt = $approved->fresh()->approved_at;
    app(ApplyApprovedIngredientEnrichment::class)->handle($applier, $enrichmentItem->batch);

    $promoted = $intakeItem->fresh()->promotedIngredient;

    expect($promoted?->taxonomy_reviewed_by_user_id)->toBe($approver->id)
        ->and($promoted?->taxonomy_reviewed_at)->toEqual($approvedAt)
        ->and($promoted?->requires_admin_review)->toBeFalse();
});

it('rolls back a failed promotion and leaves the approved intake row retryable', function (): void {
    $admin = User::factory()->admin()->create();
    [, $intakeItem, $enrichmentBatch, $enrichmentItem] = promotionIntakeFixture();
    app(ApproveIngredientEnrichmentItem::class)->handle($admin, $enrichmentItem);

    $this->mock(ApplyPlatformIngredientEnrichment::class, function ($mock): void {
        $mock->shouldReceive('applyWithinTransaction')->once()->andThrow(new RuntimeException('simulated apply failure'));
    });

    expect(fn () => app(PromoteIngredientIntakeItem::class)->handle($admin, $enrichmentItem))
        ->toThrow(RuntimeException::class);

    expect(Ingredient::query()->count())->toBe(0)
        ->and($intakeItem->fresh()->status)->toBe(IngredientIntakeItemStatus::Ready)
        ->and($intakeItem->fresh()->promoted_ingredient_id)->toBeNull()
        ->and($enrichmentItem->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Approved)
        ->and($enrichmentBatch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::ReadyForReview);
});

it('treats a repeated promotion as an idempotent replay', function (): void {
    $admin = User::factory()->admin()->create();
    [, $intakeItem, $enrichmentBatch, $enrichmentItem] = promotionIntakeFixture();
    app(ApproveIngredientEnrichmentItem::class)->handle($admin, $enrichmentItem);

    $promoter = app(PromoteIngredientIntakeItem::class);
    $first = $promoter->handle($admin, $enrichmentItem);
    $createdId = $intakeItem->fresh()->promoted_ingredient_id;
    $second = $promoter->handle($admin, $enrichmentItem);

    expect($first->status)->toBe(IngredientEnrichmentItemStatus::Applied)
        ->and($second->status)->toBe(IngredientEnrichmentItemStatus::Applied)
        ->and($intakeItem->fresh()->promoted_ingredient_id)->toBe($createdId)
        ->and(Ingredient::query()->count())->toBe(1)
        ->and($enrichmentBatch->fresh()->applied_count)->toBe(1);
});

/** @return array{0:IngredientIntakeBatch,1:IngredientIntakeItem,2:IngredientEnrichmentBatch,3:IngredientEnrichmentBatchItem} */
function promotionIntakeFixture(array $itemOverrides = []): array
{
    $intakeBatch = IngredientIntakeBatch::factory()->create([
        'status' => IngredientIntakeBatchStatus::ReadyForReview,
        'total_count' => 1,
        'ready_count' => 1,
    ]);
    $intakeItem = IngredientIntakeItem::factory()->for($intakeBatch, 'batch')->create([
        'status' => IngredientIntakeItemStatus::Ready,
        'original_current_name' => 'Coconut oil',
        'normalized_current_name' => 'coconut oil',
        ...$itemOverrides,
    ]);
    $enrichmentBatch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
        'mode' => 'intake',
        'total_count' => 1,
        'ready_count' => 1,
    ]);
    $intakeBatch->update(['ingredient_enrichment_batch_id' => $enrichmentBatch->id]);
    $subject = app(IngredientEnrichmentSubjectBuilder::class)->forIntake($intakeItem->fresh());
    $result = promotionIntakeResult($intakeItem->fresh());
    $enrichmentItem = IngredientEnrichmentBatchItem::factory()->for($enrichmentBatch, 'batch')->create([
        'ingredient_id' => null,
        'ingredient_intake_item_id' => $intakeItem->id,
        'catalog_key' => null,
        'status' => IngredientEnrichmentItemStatus::Ready,
        'snapshot' => [
            'subject_type' => 'intake',
            'subject_public_id' => (string) $intakeItem->public_id,
            'source_fingerprint' => $subject->fingerprint,
        ],
        'source_fingerprint' => $subject->fingerprint,
        'result' => $result,
    ]);

    return [$intakeBatch, $intakeItem, $enrichmentBatch, $enrichmentItem];
}

/** @return array<string, mixed> */
function promotionIntakeResult(IngredientIntakeItem $item, bool $incomplete = false): array
{
    $subject = app(IngredientEnrichmentSubjectBuilder::class)->forIntake($item->fresh());
    $locales = array_values(config('interface-translations.catalogue_locales', []));
    $translations = collect($locales)->map(function (string $locale): array {
        $headings = config("ingredient-enrichment.guidance.localized_headings.{$locale}");

        return [
            'locale' => $locale,
            'display_name' => "Coconut oil {$locale}",
            'saponification_name' => null,
            'info_markdown' => "## {$headings['overview']}\nA useful vegetable oil.\n\n## {$headings['formulation_use']}\nUse in the oil phase when suitable.",
        ];
    })->all();
    $category = $incomplete ? null : 'lipids';
    $subcategory = $incomplete ? null : 'vegetable_oils';
    $provenance = collect([
        ['field' => 'proposal.display_name', 'kind' => 'reviewer_supplied'],
        ['field' => 'proposal.inci_name', 'kind' => 'source_confirmed', 'source_urls' => ['https://eur-lex.europa.eu/eli/dec_impl/2025/1175/oj/eng']],
        ['field' => 'proposal.category', 'kind' => $incomplete ? 'unresolved' : 'reviewer_supplied'],
        ['field' => 'proposal.subcategory', 'kind' => $incomplete ? 'unresolved' : 'reviewer_supplied'],
        ['field' => 'proposal.saponification_name', 'kind' => 'reviewer_supplied'],
        ['field' => 'proposal.soap_inci_naoh_name', 'kind' => 'unresolved'],
        ['field' => 'proposal.soap_inci_koh_name', 'kind' => 'unresolved'],
        ['field' => 'proposal.info_markdown', 'kind' => 'reviewer_supplied'],
        ['field' => 'proposal.soapmaking_relevant', 'kind' => 'reviewer_supplied'],
    ])->map(fn (array $row): array => [
        'field' => $row['field'],
        'kind' => $row['kind'],
        'reasoning' => 'Recorded for the human reviewer.',
        'source_urls' => $row['source_urls'] ?? [],
    ])->all();
    foreach (array_keys($translations) as $index) {
        $provenance[] = [
            'field' => "proposal.translations.{$index}",
            'kind' => 'reviewer_supplied',
            'reasoning' => 'Recorded for the human reviewer.',
            'source_urls' => [],
        ];
    }

    return [
        'format' => config('ingredient-enrichment.result_format'),
        'schema_version' => config('ingredient-enrichment.schema_version'),
        'subject_type' => 'intake',
        'subject_public_id' => (string) $subject->subjectPublicId,
        'catalog_key' => null,
        'source_fingerprint' => $subject->fingerprint,
        'proposal' => [
            'display_name' => 'Coconut oil',
            'inci_name' => 'Cocos Nucifera Oil',
            'category' => $category,
            'subcategory' => $subcategory,
            'saponification_name' => 'Coconut',
            'soap_inci_naoh_name' => null,
            'soap_inci_koh_name' => null,
            'info_markdown' => "## Overview\nA useful vegetable oil for cosmetic formulation.\n\n## Formulation use\nUse in the oil phase when suitable.",
            'soapmaking_relevant' => false,
            'aliases' => [],
            'identifiers' => [],
            'cosing_functions' => [],
            'translations' => $translations,
            'market_labels' => [],
        ],
        'field_confidence' => [
            ['field' => 'proposal.inci_name', 'confidence' => 'verified'],
        ],
        'value_provenance' => $provenance,
        'evidence' => [[
            'field' => 'proposal.inci_name',
            'source_name' => 'EUR-Lex Common Ingredient Names Glossary',
            'source_url' => 'https://eur-lex.europa.eu/eli/dec_impl/2025/1175/oj/eng',
            'source_tier' => 'official',
            'confidence' => 'verified',
            'source_version' => '2025/1175',
            'source_updated_at' => null,
            'retrieved_at' => '2026-08-16T10:00:00+00:00',
        ]],
        'regulatory_findings' => [],
        'confidence' => 'medium',
        'warnings' => [],
        'unresolved_questions' => [],
    ];
}
