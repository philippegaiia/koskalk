<?php

use App\Data\IngredientClassificationPromptInput;
use App\Models\IngredientFunction;
use App\Services\IngredientClassificationPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a locale-aware classification prompt with strict evidence boundaries', function (): void {
    IngredientFunction::factory()->create([
        'key' => 'humectant',
        'name' => 'Humectant',
        'is_active' => true,
    ]);

    $prompt = app(IngredientClassificationPromptBuilder::class)->build(
        new IngredientClassificationPromptInput(
            name: 'Vegetable glycerin',
            inciName: 'GLYCERIN',
            casNumber: '56-81-5',
            ecNumber: '200-289-5',
            supplierNotes: 'Palm-free supplier grade',
            responseLocale: 'fr',
        ),
    );

    expect($prompt)
        ->toContain('Answer in: French — Français (fr).')
        ->toContain('Keep category, subcategory, and function backing values exactly as supplied.')
        ->toContain('"name": "Vegetable glycerin"', '"inci_name": "GLYCERIN"')
        ->toContain('"cas_number": "56-81-5"', '"ec_number": "200-289-5"')
        ->toContain('"supplier_notes": "Palm-free supplier grade"')
        ->toContain('humectants_polyols', 'glycerin_glycols', 'humectant')
        ->toContain('A catalogue category describes the primary practical material role')
        ->toContain('Practical formulation roles must not be returned as COSING assignments')
        ->toContain('does not establish authorization under EU Cosmetics Regulation Annex V')
        ->toContain('directly accessed official European Commission COSING source name and URL')
        ->toContain('Label mirrors and secondary sources as secondary evidence')
        ->toContain('Review the exact ingredient and every declared component of a commercial blend')
        ->toContain('current consolidated Regulation (EC) No 1223/2009')
        ->toContain('Annex II', 'Annex III', 'Annex IV', 'Annex V', 'Annex VI')
        ->toContain('official FDA prohibited and restricted cosmetic ingredient information')
        ->toContain('applicable official 21 CFR provision')
        ->toContain('Prohibited, Restricted, No specific restriction found, or Not verified')
        ->toContain('No specific restriction found must not be described as approval')
        ->toContain('EU regulatory status:')
        ->toContain('U.S. FDA regulatory status:')
        ->toContain('exact matched substance or blend component')
        ->toContain('Do not issue a regulatory conclusion for a blend until its components are established')
        ->toContain('Do not include a commercial product example unless')
        ->toContain('Do not provide a usage level unless')
        ->toContain('Do not infer natural origin')
        ->toContain('Practical formulation roles: describe useful non-COSING roles in plain text only')
        ->not->toContain("Respond in the user's language when it is evident")
        ->not->toContain('Additional suggested functions:');
});

it('uses English explicitly and falls back safely for an unknown response locale', function (): void {
    $builder = app(IngredientClassificationPromptBuilder::class);

    $englishPrompt = $builder->build(new IngredientClassificationPromptInput(
        name: 'Glycerin',
        inciName: null,
        casNumber: null,
        ecNumber: null,
        supplierNotes: null,
        responseLocale: 'en',
    ));

    $unknownLocalePrompt = $builder->build(new IngredientClassificationPromptInput(
        name: 'Glycerin',
        inciName: null,
        casNumber: null,
        ecNumber: null,
        supplierNotes: null,
        responseLocale: 'xx-ZZ',
    ));

    expect($englishPrompt)->toContain('Answer in: English (en).')
        ->and($unknownLocalePrompt)->toContain('Answer in: xx-ZZ.');
});
