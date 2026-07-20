<?php

use Database\Seeders\InterfaceTranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the approved product and formula terminology on the soap workbench', function () {
    $header = view('livewire.dashboard.partials.recipe-workbench.header', [
        'workbench' => [
            'recipe' => [
                'public_id' => 'test-product',
                'has_saved_formula' => true,
                'saved_formula_url' => '/products/test-product',
                'is_locked' => false,
            ],
        ],
    ])->render();
    $navigation = view('livewire.dashboard.partials.recipe-workbench.navigation')->render();
    $settings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();

    expect($header)
        ->toContain('Product name')
        ->toContain('Untitled soap')
        ->toContain('Product sheet')
        ->toContain('Lock product')
        ->toContain('More actions')
        ->toContain('Product details')
        ->toContain('Duplicate product')
        ->not->toContain('Formula name')
        ->not->toContain('Lock formula')
        ->and($navigation)
        ->toContain('aria-label="Product sections"')
        ->toContain('Label &amp; output')
        ->toContain('Instructions &amp; media')
        ->and($settings)
        ->toContain('Formula settings')
        ->toContain('Total oil weight')
        ->toContain('Enter amounts as')
        ->toContain('Calculate water by')
        ->toContain('Product use')
        ->toContain('Regulatory framework')
        ->toContain('IFRA category')
        ->toContain('No IFRA category selected')
        ->not->toContain('Formula setup')
        ->not->toContain('>Current<');
});

it('uses concise task focused copy for the soap formula sections', function () {
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();
    $saponification = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $additions = view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render();
    $qualities = view('livewire.dashboard.partials.recipe-workbench.formula-analysis')->render();
    $fattyAcids = view('livewire.dashboard.partials.recipe-workbench.fatty-acid-profile')->render();

    expect($ingredientBrowser)
        ->toContain('Add ingredients')
        ->toContain('Search by name or INCI')
        ->toContain('Properties')
        ->toContain('Soapkraft has not verified their data.')
        ->not->toContain('Ingredient browser')
        ->and($saponification)
        ->toContain('Saponification')
        ->toContain('Oils total 100%')
        ->toContain('Oils must total 100%')
        ->toContain('Lye and water')
        ->toContain('Add or drop an oil here')
        ->toContain('Total oils')
        ->not->toContain('Reaction core')
        ->not->toContain('Saponified oils + lye water')
        ->and($additions)
        ->toContain('Formula additions')
        ->toContain('Colorants, preservatives and other functional ingredients.')
        ->toContain('Fragrance oils, essential oils and aromatic extracts.')
        ->toContain('Drop an oil here to use it as an additive.')
        ->not->toContain('Post-reaction phases')
        ->and($qualities)
        ->toContain('These values are estimates. Process, additives and cure conditions affect the finished soap.')
        ->toContain('Points to review')
        ->toContain('Add oils with SAP and fatty-acid data to calculate soap qualities.')
        ->not->toContain('At a glance')
        ->and($fattyAcids)
        ->toContain('Individual fatty acids')
        ->toContain('Add oils with fatty-acid data to see the blended profile.')
        ->not->toContain('Grouped profile');
});

it('registers and seeds reviewed soap workbench translations for every supported locale', function () {
    expect(config('interface-translations.sources.workbench'))->toBe(['*']);

    $this->seed(InterfaceTranslationSeeder::class);

    foreach (['fr', 'es', 'de', 'it', 'nl'] as $locale) {
        app()->setLocale($locale);

        expect(__('workbench.header.product_name'))->not->toBe('workbench.header.product_name')
            ->and(__('workbench.saponification.title'))->not->toBe('workbench.saponification.title')
            ->and(__('workbench.additions.title'))->not->toBe('workbench.additions.title');
    }
});
