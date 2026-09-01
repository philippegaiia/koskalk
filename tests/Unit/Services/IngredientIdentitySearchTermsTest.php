<?php

use App\Services\IngredientEnrichment\IngredientIdentitySearchTerms;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the original term and removes parenthetical qualifiers', function (): void {
    $variants = app(IngredientIdentitySearchTerms::class)->variants('Sclerocarya Birrea (Marula) Kernel Oil');

    expect($variants)->toContain('Sclerocarya Birrea (Marula) Kernel Oil')
        ->and($variants)->toContain('Sclerocarya Birrea Kernel Oil');
});

it('converts kernel to seed for registry-friendly discovery', function (): void {
    $variants = app(IngredientIdentitySearchTerms::class)->variants('Sclerocarya Birrea Kernel Oil');

    expect($variants)->toContain('Sclerocarya Birrea Seed Oil');
});

it('handles the plural kernel form and does not duplicate unchanged terms', function (): void {
    $variants = app(IngredientIdentitySearchTerms::class)->variants('Apricot Kernels');

    expect($variants)->toContain('Apricot Seed')
        ->and($variants)->toHaveCount(2);

    $unchanged = app(IngredientIdentitySearchTerms::class)->variants('Coconut Oil');

    expect($unchanged)->toBe(['Coconut Oil']);
});
