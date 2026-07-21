<?php

use App\Models\InterfaceTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the approved product and formula terminology on the soap workbench', function () {
    $workbench = [
        'recipe' => [
            'public_id' => 'test-product',
            'has_saved_formula' => true,
            'saved_formula_url' => '/products/test-product',
            'is_locked' => false,
        ],
    ];
    $header = view('livewire.dashboard.partials.recipe-workbench.header', [
        'workbench' => $workbench,
    ])->render();
    $navigation = view('livewire.dashboard.partials.recipe-workbench.navigation', [
        'workbench' => $workbench,
    ])->render();
    $settings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();

    expect($header)
        ->toContain('Product name')
        ->toContain('Untitled soap')
        ->toContain('Lock product')
        ->toContain('More actions')
        ->toContain('Product details')
        ->toContain('Duplicate product')
        ->not->toContain('Formula name')
        ->not->toContain('Lock formula')
        ->and($navigation)
        ->toContain('aria-label="Product sections"')
        ->toContain('Product sheet')
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
        ->toContain('Fragrance and aromatics')
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

it('loads reviewed soap workbench translations from the database for every supported locale', function () {
    expect(config('interface-translations.sources.workbench'))->toBe(['*']);

    $translations = [
        'header.product_name' => ['fr' => 'Nom du produit', 'es' => 'Nombre del producto', 'de' => 'Produktname', 'it' => 'Nome del prodotto', 'nl' => 'Productnaam'],
        'header.breadcrumb' => ['fr' => 'Navigation du produit', 'es' => 'Navegación del producto', 'de' => 'Produktnavigation', 'it' => 'Navigazione del prodotto', 'nl' => 'Productnavigatie'],
        'header.save_before_locking' => ['fr' => 'Enregistrez le produit avant de le verrouiller.', 'es' => 'Guarda el producto antes de bloquearlo.', 'de' => 'Speichern Sie das Produkt, bevor Sie es sperren.', 'it' => 'Salva il prodotto prima di bloccarlo.', 'nl' => 'Sla het product op voordat je het vergrendelt.'],
        'saponification.title' => ['fr' => 'Saponification', 'es' => 'Saponificación', 'de' => 'Verseifung', 'it' => 'Saponificazione', 'nl' => 'Verzeping'],
        'additions.title' => ['fr' => 'Ajouts à la formule', 'es' => 'Adiciones a la fórmula', 'de' => 'Rezepturzusätze', 'it' => 'Aggiunte alla formula', 'nl' => 'Formuletoevoegingen'],
    ];

    foreach ($translations as $key => $text) {
        InterfaceTranslation::query()->create([
            'group' => 'workbench',
            'key' => $key,
            'text' => $text,
        ]);
    }

    foreach (['fr', 'es', 'de', 'it', 'nl'] as $locale) {
        app()->setLocale($locale);

        expect(__('workbench.header.product_name'))->toBe($translations['header.product_name'][$locale])
            ->and(__('workbench.header.breadcrumb'))->toBe($translations['header.breadcrumb'][$locale])
            ->and(__('workbench.header.save_before_locking'))->toBe($translations['header.save_before_locking'][$locale])
            ->and(__('workbench.saponification.title'))->toBe($translations['saponification.title'][$locale])
            ->and(__('workbench.additions.title'))->toBe($translations['additions.title'][$locale]);
    }
});

it('renders the cosmetic formula editor with contextual translations', function (string $locale, array $expected) {
    foreach ($expected as $key => $text) {
        InterfaceTranslation::query()->create([
            'group' => 'workbench',
            'key' => "cosmetic.{$key}",
            'text' => [$locale => $text],
        ]);
    }

    app()->setLocale($locale);

    $formula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();

    expect($formula)
        ->toContain($expected['title'])
        ->toContain($expected['instruction'])
        ->toContain($expected['move_up'])
        ->toContain($expected['move_down'])
        ->toContain($expected['remove_phase'])
        ->toContain($expected['drop_here'])
        ->toContain($expected['formula_total'])
        ->toContain($expected['add_phase'])
        ->not->toContain('Phases and full formula basis')
        ->not->toContain('Enter percentages or weights against the full batch weight.');
})->with([
    'French' => ['fr', [
        'title' => 'Ingrédients de la formule',
        'instruction' => 'Organisez les ingrédients par phase, puis saisissez un pourcentage ou un poids.',
        'move_up' => 'Monter',
        'move_down' => 'Descendre',
        'remove_phase' => 'Supprimer la phase',
        'drop_here' => 'Déposez des ingrédients ici',
        'formula_total' => 'Total de la formule',
        'add_phase' => 'Ajouter une phase',
    ]],
    'Spanish' => ['es', [
        'title' => 'Ingredientes de la fórmula',
        'instruction' => 'Organiza los ingredientes por fases e introduce un porcentaje o un peso.',
        'move_up' => 'Subir',
        'move_down' => 'Bajar',
        'remove_phase' => 'Eliminar fase',
        'drop_here' => 'Suelta ingredientes aquí',
        'formula_total' => 'Total de la fórmula',
        'add_phase' => 'Añadir fase',
    ]],
    'German' => ['de', [
        'title' => 'Rezepturbestandteile',
        'instruction' => 'Zutaten nach Phasen ordnen und als Prozentwert oder Gewicht eingeben.',
        'move_up' => 'Nach oben',
        'move_down' => 'Nach unten',
        'remove_phase' => 'Phase entfernen',
        'drop_here' => 'Zutaten hier ablegen',
        'formula_total' => 'Rezeptur gesamt',
        'add_phase' => 'Phase hinzufügen',
    ]],
    'Italian' => ['it', [
        'title' => 'Ingredienti della formula',
        'instruction' => 'Organizza gli ingredienti per fase, quindi inserisci una percentuale o un peso.',
        'move_up' => 'Sposta su',
        'move_down' => 'Sposta giù',
        'remove_phase' => 'Rimuovi fase',
        'drop_here' => 'Trascina qui gli ingredienti',
        'formula_total' => 'Totale formula',
        'add_phase' => 'Aggiungi fase',
    ]],
    'Dutch' => ['nl', [
        'title' => 'Formule-ingrediënten',
        'instruction' => 'Deel ingrediënten in per fase en voer daarna een percentage of gewicht in.',
        'move_up' => 'Omhoog',
        'move_down' => 'Omlaag',
        'remove_phase' => 'Fase verwijderen',
        'drop_here' => 'Sleep ingrediënten hierheen',
        'formula_total' => 'Formuletotaal',
        'add_phase' => 'Fase toevoegen',
    ]],
]);

it('localizes cosmetic workbench copy generated by JavaScript', function () {
    $formulaSection = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));
    $versionSection = file_get_contents(resource_path('js/recipe-workbench/sections/version-section.js'));

    expect($formulaSection)
        ->toContain("this.t('cosmetic.formula_balanced')")
        ->toContain("this.t('cosmetic.formula_unbalanced')")
        ->toContain("this.t('cosmetic.balance_label')")
        ->not->toContain("'Formula balanced'")
        ->not->toContain("'Formula must reach 100%'")
        ->and($versionSection)
        ->toContain("this.t('cosmetic.blend_only')")
        ->not->toContain("'Blend only'");

    expect(file_get_contents(resource_path('js/recipe-workbench/component.js')))
        ->toContain('this.t(`categories.${option.value}`)');
});
