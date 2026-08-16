<?php

use App\Enums\IngredientFunctionSource;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Services\IngredientDataEntryService;
use App\Services\IngredientFunctionAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('promotes an identical manual function to reviewed CosIng provenance', function (): void {
    $ingredient = Ingredient::factory()->create();
    $function = IngredientFunction::factory()->create([
        'key' => 'emollient',
        'is_active' => true,
    ]);
    $service = app(IngredientFunctionAssignmentService::class);

    $service->syncManual($ingredient, [$function->id]);
    $service->syncCosIng(
        ingredient: $ingredient,
        functionKeys: [$function->key],
        sourceReference: 'https://single-market-economy.ec.europa.eu/example#123',
        checkedAt: CarbonImmutable::parse('2026-08-08'),
    );

    $assignment = $ingredient->fresh()->functions()->firstOrFail()->pivot;

    expect($assignment->source)->toBe(IngredientFunctionSource::CosIng->value)
        ->and($assignment->source_reference)->toContain('#123');
});

it('does not let a later manual selection overwrite reviewed CosIng provenance', function (): void {
    $ingredient = Ingredient::factory()->create();
    $function = IngredientFunction::factory()->create([
        'key' => 'humectant',
        'is_active' => true,
    ]);
    $service = app(IngredientFunctionAssignmentService::class);

    $service->syncCosIng(
        ingredient: $ingredient,
        functionKeys: [$function->key],
        sourceReference: 'https://single-market-economy.ec.europa.eu/example#456',
        checkedAt: CarbonImmutable::parse('2026-08-08'),
    );
    $service->syncManual($ingredient, [$function->id]);

    expect($ingredient->fresh()->functions()->firstOrFail()->pivot->source)
        ->toBe(IngredientFunctionSource::CosIng->value);
});

it('copies reference assignments as inherited provenance', function (): void {
    $source = Ingredient::factory()->create();
    $target = Ingredient::factory()->create();
    $function = IngredientFunction::factory()->create([
        'key' => 'skin_conditioning',
        'is_active' => true,
    ]);
    $service = app(IngredientFunctionAssignmentService::class);

    $service->syncCosIng(
        ingredient: $source,
        functionKeys: [$function->key],
        sourceReference: 'https://single-market-economy.ec.europa.eu/example#789',
        checkedAt: CarbonImmutable::parse('2026-08-08'),
    );
    $source->load('functions');
    $service->copyTo($source, $target);

    $assignment = $target->fresh()->functions()->firstOrFail()->pivot;

    expect($assignment->source)->toBe(IngredientFunctionSource::Inherited->value)
        ->and($assignment->source_reference)->toContain('#789');
});

it('exposes both the manual selection and complete administrator-reviewed function selection', function (): void {
    $ingredient = Ingredient::factory()->create();
    $verified = IngredientFunction::factory()->create(['key' => 'emollient', 'name' => 'Emollient']);
    $manual = IngredientFunction::factory()->create(['key' => 'humectant', 'name' => 'Humectant']);
    $assignments = app(IngredientFunctionAssignmentService::class);

    $assignments->syncCosIng(
        ingredient: $ingredient,
        functionKeys: [$verified->key],
        sourceReference: 'https://single-market-economy.ec.europa.eu/example#789',
        checkedAt: CarbonImmutable::parse('2026-08-08'),
    );
    $assignments->syncManual($ingredient, [$manual->id]);

    $formData = app(IngredientDataEntryService::class)->formData($ingredient);

    expect($formData['function_ids'])->toBe([$manual->id])
        ->and($formData['reviewed_function_ids'])->toEqualCanonicalizing([$verified->id, $manual->id])
        ->and($formData['verified_function_names'])->toBe(['Emollient']);
});

it('lets an administrator review all function assignments while preserving retained COSING provenance', function (): void {
    $ingredient = Ingredient::factory()->create();
    $retainedCosIng = IngredientFunction::factory()->create(['key' => 'abrasive']);
    $removedCosIng = IngredientFunction::factory()->create(['key' => 'absorbent']);
    $addedManual = IngredientFunction::factory()->create(['key' => 'anticaking']);
    $service = app(IngredientFunctionAssignmentService::class);

    $service->syncCosIng(
        ingredient: $ingredient,
        functionKeys: [$retainedCosIng->key, $removedCosIng->key],
        sourceReference: 'https://example.com/cosing',
        checkedAt: CarbonImmutable::parse('2026-08-14'),
    );
    $service->syncReviewed($ingredient, [$retainedCosIng->id, $addedManual->id]);

    $assignments = $ingredient->fresh()->functions()->get()->keyBy('id');

    expect($assignments)->toHaveKeys([$retainedCosIng->id, $addedManual->id])
        ->and($assignments)->not->toHaveKey($removedCosIng->id)
        ->and($assignments[$retainedCosIng->id]->pivot->source)->toBe(IngredientFunctionSource::CosIng->value)
        ->and($assignments[$retainedCosIng->id]->pivot->source_reference)->toBe('https://example.com/cosing')
        ->and($assignments[$addedManual->id]->pivot->source)->toBe(IngredientFunctionSource::Manual->value);
});

it('merges row-specific CosIng evidence without changing omitted reviewed assignments', function (): void {
    $ingredient = Ingredient::factory()->create();
    $existing = IngredientFunction::factory()->create(['key' => 'emollient']);
    $new = IngredientFunction::factory()->create(['key' => 'skin_conditioning']);
    $service = app(IngredientFunctionAssignmentService::class);

    $service->syncCosIng($ingredient, [$existing->key], 'https://example.com/existing', CarbonImmutable::parse('2026-08-01'));
    $service->mergeCosIng($ingredient, [[
        'key' => $new->key,
        'source_reference' => 'https://example.com/new',
        'source_checked_at' => CarbonImmutable::parse('2026-08-13'),
    ]]);

    $assignments = $ingredient->fresh()->functions()->get()->keyBy('key');

    expect($assignments)->toHaveKeys([$existing->key, $new->key])
        ->and($assignments[$existing->key]->pivot->source_reference)->toBe('https://example.com/existing')
        ->and($assignments[$new->key]->pivot->source_reference)->toBe('https://example.com/new');
});

it('replaces only CosIng assignments and keeps manual and inherited provenance', function (): void {
    $ingredient = Ingredient::factory()->create();
    $cosing = IngredientFunction::factory()->create(['key' => 'emollient']);
    $manual = IngredientFunction::factory()->create(['key' => 'humectant']);
    $service = app(IngredientFunctionAssignmentService::class);

    $service->syncCosIng($ingredient, [$cosing->key], 'https://example.com/cosing', CarbonImmutable::parse('2026-08-01'));
    $service->syncManual($ingredient, [$manual->id]);
    $service->replaceCosIng($ingredient, []);

    $assignments = $ingredient->fresh()->functions()->get()->keyBy('key');

    expect($assignments)->not->toHaveKey($cosing->key)
        ->and($assignments[$manual->key]->pivot->source)->toBe(IngredientFunctionSource::Manual->value);
});

it('promotes a manual assignment with the evidence supplied for the verified row', function (): void {
    $ingredient = Ingredient::factory()->create();
    $function = IngredientFunction::factory()->create(['key' => 'soothing']);
    $service = app(IngredientFunctionAssignmentService::class);

    $service->syncManual($ingredient, [$function->id]);
    $service->mergeCosIng($ingredient, [[
        'key' => $function->key,
        'source_reference' => 'https://example.com/official-soothing',
        'source_checked_at' => CarbonImmutable::parse('2026-08-13'),
        'source_tier' => 'structured_mirror',
        'confidence' => 'supported',
        'source_version' => 'inventory-2026-03-21',
        'source_updated_at' => '2026-03-21',
    ]]);

    $assignment = $ingredient->fresh()->functions()->firstOrFail()->pivot;

    expect($assignment->source)->toBe(IngredientFunctionSource::CosIng->value)
        ->and($assignment->source_reference)->toBe('https://example.com/official-soothing')
        ->and(CarbonImmutable::parse($assignment->source_checked_at)->toDateString())->toBe('2026-08-13')
        ->and($assignment->source_tier)->toBe('structured_mirror')
        ->and($assignment->confidence)->toBe('supported')
        ->and($assignment->source_version)->toBe('inventory-2026-03-21');
});
