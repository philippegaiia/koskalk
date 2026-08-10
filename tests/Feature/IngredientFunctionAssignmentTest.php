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

it('keeps verified functions read only in form data and exposes only manual choices', function (): void {
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

    expect(app(IngredientDataEntryService::class)->formData($ingredient))
        ->toMatchArray([
            'function_ids' => [$manual->id],
            'verified_function_names' => ['Emollient'],
        ]);
});
