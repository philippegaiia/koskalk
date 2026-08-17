<?php

use App\Models\InterfaceTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

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
        ->toContain('Product output')
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
        ->toContain('data-formula-balance-status')
        ->toContain('oilPercentageStatusLabel')
        ->toContain('Lye and water')
        ->toContain('Add or drop an oil here')
        ->toContain('Total oils')
        ->not->toContain('Oils total 100%')
        ->not->toContain('Oils must total 100%')
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

it('uses approved instructions and media terminology', function () {
    expect(__('workbench.instructions.title'))->toBe('Instructions & media')
        ->and(__('workbench.instructions.intro'))->toBe('Add the product description and image used on the Product page, then record the manufacturing procedure used at the bench.')
        ->and(__('workbench.instructions.presentation_title'))->toBe('Product presentation')
        ->and(__('workbench.instructions.description_label'))->toBe('Product description')
        ->and(__('workbench.instructions.description_help'))->toBe('Describe the finished product for its Product page. This field is text-only; use the featured image field for its main image.')
        ->and(__('workbench.instructions.featured_label'))->toBe('Featured product image')
        ->and(__('workbench.instructions.featured_help'))->toBe('JPG, PNG or WebP up to 3 MB. Minimum dimensions: 300 px on the short side and 500 px on the long side. Keep the original proportions or crop the image after upload.')
        ->and(__('workbench.instructions.procedure_label'))->toBe('Manufacturing procedure')
        ->and(__('workbench.instructions.procedure_help'))->toBe('Record the process steps, temperatures, timings, checks and cautions used at the bench. Add up to eight procedure images with Insert from Media Library in the toolbar.')
        ->and(__('workbench.instructions.draft_text_help'))->toBe('You can start writing now. Save the formula before attaching images.')
        ->and(__('workbench.instructions.save_changes'))->toBe('Save changes')
        ->and(__('workbench.instructions.all_saved'))->toBe('All changes saved')
        ->and(__('workbench.instructions.unsaved'))->toBe('Unsaved changes')
        ->and(__('workbench.instructions.saving'))->toBe('Saving…')
        ->and(__('workbench.instructions.saved_at', ['time' => '10:30']))->toBe('Saved at 10:30')
        ->and(__('workbench.instructions.save_failed'))->toBe('Save failed')
        ->and(__('workbench.instructions.leave_warning'))->toBe('You have unsaved changes. Leave without saving?');
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

it('loads contextual instructions and media translations from the database for every supported locale', function () {
    $originalLocale = app()->getLocale();

    $translations = [
        'fr' => [
            'instructions.title' => 'Instructions et médias',
            'instructions.presentation_title' => 'Présentation du produit',
            'instructions.description_help' => 'Présentez le produit fini pour sa fiche produit. Ce champ est uniquement textuel ; utilisez l’image principale séparée.',
            'instructions.featured_help' => 'JPG, PNG ou WebP jusqu’à 3 Mo. Dimensions minimales : 300 px pour le petit côté et 500 px pour le grand. Conservez les proportions d’origine ou recadrez l’image après l’import.',
            'instructions.procedure_label' => 'Mode opératoire de fabrication',
            'instructions.procedure_help' => 'Consignez les étapes, températures, durées, contrôles et précautions appliqués à l’atelier. Ajoutez jusqu’à huit images avec Insérer depuis la médiathèque.',
            'instructions.all_saved' => 'Toutes les modifications sont enregistrées',
            'instructions.save_failed' => 'Échec de l’enregistrement',
            'instructions.leave_warning' => 'Des modifications ne sont pas enregistrées. Quitter sans les enregistrer ?',
            'instructions.minimum_image_edges' => 'Le petit côté de l’image doit mesurer au moins :short pixels et le grand côté au moins :long pixels.',
        ],
        'es' => [
            'instructions.title' => 'Instrucciones y contenido multimedia',
            'instructions.presentation_title' => 'Presentación del producto',
            'instructions.description_help' => 'Describe el producto terminado para su ficha. Este campo es solo de texto; usa la imagen destacada por separado.',
            'instructions.featured_help' => 'JPG, PNG o WebP de hasta 3 MB. Dimensiones mínimas: 300 px en el lado corto y 500 px en el largo. Conserva las proporciones originales o recorta la imagen después de subirla.',
            'instructions.procedure_label' => 'Procedimiento de fabricación',
            'instructions.procedure_help' => 'Anota los pasos, las temperaturas, los tiempos, los controles y las precauciones utilizados en el taller. Añade hasta ocho imágenes con Insertar desde la biblioteca multimedia.',
            'instructions.all_saved' => 'Todos los cambios están guardados',
            'instructions.save_failed' => 'No se han podido guardar los cambios',
            'instructions.leave_warning' => 'Hay cambios sin guardar. ¿Quieres salir sin guardarlos?',
            'instructions.minimum_image_edges' => 'El lado corto de la imagen debe medir al menos :short píxeles y el lado largo al menos :long píxeles.',
        ],
        'de' => [
            'instructions.title' => 'Anleitung und Medien',
            'instructions.presentation_title' => 'Produktdarstellung',
            'instructions.description_help' => 'Beschreiben Sie das fertige Produkt für seine Produktseite. Dieses Feld ist nur für Text; verwenden Sie das separate Titelbild.',
            'instructions.featured_help' => 'JPG, PNG oder WebP bis 3 MB. Mindestmaße: 300 px an der kurzen und 500 px an der langen Seite. Behalten Sie das ursprüngliche Seitenverhältnis bei oder schneiden Sie das Bild nach dem Hochladen zu.',
            'instructions.procedure_label' => 'Herstellungsverfahren',
            'instructions.procedure_help' => 'Dokumentieren Sie Arbeitsschritte, Temperaturen, Zeiten, Kontrollen und Vorsichtsmaßnahmen. Fügen Sie bis zu acht Bilder über Aus Medienbibliothek einfügen hinzu.',
            'instructions.all_saved' => 'Alle Änderungen gespeichert',
            'instructions.save_failed' => 'Speichern fehlgeschlagen',
            'instructions.leave_warning' => 'Es gibt nicht gespeicherte Änderungen. Seite ohne Speichern verlassen?',
            'instructions.minimum_image_edges' => 'Die kurze Bildseite muss mindestens :short Pixel und die lange mindestens :long Pixel groß sein.',
        ],
        'it' => [
            'instructions.title' => 'Istruzioni e contenuti multimediali',
            'instructions.presentation_title' => 'Presentazione del prodotto',
            'instructions.description_help' => 'Descrivi il prodotto finito per la sua scheda. Questo campo è solo testo; usa separatamente l’immagine in evidenza.',
            'instructions.featured_help' => 'JPG, PNG o WebP fino a 3 MB. Dimensioni minime: 300 px sul lato corto e 500 px su quello lungo. Mantieni le proporzioni originali oppure ritaglia l’immagine dopo il caricamento.',
            'instructions.procedure_label' => 'Procedura di fabbricazione',
            'instructions.procedure_help' => 'Registra fasi, temperature, tempi, controlli e precauzioni seguiti in laboratorio. Aggiungi fino a otto immagini con Inserisci dalla libreria multimediale.',
            'instructions.all_saved' => 'Tutte le modifiche sono state salvate',
            'instructions.save_failed' => 'Salvataggio non riuscito',
            'instructions.leave_warning' => 'Sono presenti modifiche non salvate. Uscire senza salvarle?',
            'instructions.minimum_image_edges' => 'Il lato corto dell’immagine deve misurare almeno :short pixel e quello lungo almeno :long pixel.',
        ],
        'nl' => [
            'instructions.title' => 'Instructies en media',
            'instructions.presentation_title' => 'Productpresentatie',
            'instructions.description_help' => 'Beschrijf het afgewerkte product voor de productpagina. Dit veld is alleen tekst; gebruik de aparte uitgelichte afbeelding.',
            'instructions.featured_help' => 'JPG, PNG of WebP tot 3 MB. Minimale afmetingen: 300 px aan de korte zijde en 500 px aan de lange zijde. Behoud de oorspronkelijke verhoudingen of snijd de afbeelding bij na het uploaden.',
            'instructions.procedure_label' => 'Productiewijze',
            'instructions.procedure_help' => 'Leg de stappen, temperaturen, tijden, controles en voorzorgsmaatregelen aan de werkbank vast. Voeg maximaal acht afbeeldingen toe via Invoegen uit mediabibliotheek.',
            'instructions.all_saved' => 'Alle wijzigingen zijn opgeslagen',
            'instructions.save_failed' => 'Opslaan mislukt',
            'instructions.leave_warning' => 'Er zijn niet-opgeslagen wijzigingen. Wil je de pagina verlaten zonder op te slaan?',
            'instructions.minimum_image_edges' => 'De korte zijde van de afbeelding moet minimaal :short pixels zijn en de lange zijde minimaal :long pixels.',
        ],
    ];

    foreach (array_keys(reset($translations)) as $key) {
        InterfaceTranslation::query()->create([
            'group' => 'workbench',
            'key' => $key,
            'text' => collect($translations)->mapWithKeys(
                fn (array $localeTranslations, string $locale): array => [$locale => $localeTranslations[$key]],
            )->all(),
        ]);
    }

    app('translator')->setLoaded([]);

    try {
        foreach ($translations as $locale => $expected) {
            app()->setLocale($locale);

            expect(__('workbench.instructions.title'))->toBe($expected['instructions.title'])
                ->and(__('workbench.instructions.presentation_title'))->toBe($expected['instructions.presentation_title'])
                ->and(__('workbench.instructions.description_help'))->toBe($expected['instructions.description_help'])
                ->and(__('workbench.instructions.featured_help'))->toBe($expected['instructions.featured_help'])
                ->and(__('workbench.instructions.procedure_label'))->toBe($expected['instructions.procedure_label'])
                ->and(__('workbench.instructions.procedure_help'))->toBe($expected['instructions.procedure_help'])
                ->and(__('workbench.instructions.all_saved'))->toBe($expected['instructions.all_saved'])
                ->and(__('workbench.instructions.save_failed'))->toBe($expected['instructions.save_failed'])
                ->and(__('workbench.instructions.leave_warning'))->toBe($expected['instructions.leave_warning'])
                ->and(__('workbench.instructions.minimum_image_edges', ['short' => 300, 'long' => 500]))
                ->toBe(str_replace([':short', ':long'], ['300', '500'], $expected['instructions.minimum_image_edges']));
        }
    } finally {
        app()->setLocale($originalLocale);
        app('translator')->setLoaded([]);
    }
});

it('localizes Media Library validation errors for every supported locale', function () {
    $originalLocale = app()->getLocale();
    $messages = [
        'media_library.validation.picker_ready_workspace' => ['Choose a ready image from this workspace.', []],
        'media_library.validation.procedure_images_unavailable' => ['One or more procedure images are unavailable, still processing, or outside this workspace.', []],
        'media_library.validation.procedure_use_library' => ['Add procedure images with Insert from Media Library.', []],
        'media_library.validation.procedure_secure_url' => ['Procedure images must use their secure Media Library URL.', []],
        'media_library.validation.recipe_media_mismatch' => ['The selected recipe media does not belong to this formula.', []],
        'media_library.validation.upload_store_failed' => ['The image could not be stored. Please try again.', []],
        'media_library.validation.retry_failed_only' => ['Only failed images can be retried.', []],
        'media_library.validation.retry_source_missing' => ['The original upload is no longer available. Remove this image and upload it again.', []],
        'media_library.validation.upload_extension' => ['Choose a JPEG, PNG, WebP, HEIC, or HEIF image.', []],
        'media_library.validation.upload_invalid_image' => ['Choose a valid JPEG, PNG, WebP, HEIC, or HEIF image.', []],
        'media_library.validation.upload_size' => ['The file must not be larger than 10 MB.', ['max' => 10]],
        'media_library.validation.maximum_images' => ['Choose up to 3 images.', ['max' => 3]],
        'media_library.validation.selected_images_unavailable' => ['One or more selected images are unavailable or still processing.', []],
        'media_library.validation.workspace_unavailable' => ['The selected workspace is unavailable.', []],
        'media_library.validation.procedure_image_limit' => ['The manufacturing procedure may contain up to 8 images.', ['max' => 8]],
        'media_library.validation.description_text_only' => ['The product description is text-only. Choose images from the Media Library.', []],
    ];

    try {
        app()->setLocale('en');

        foreach ($messages as $key => [$english, $replace]) {
            expect(__($key, $replace))->toBe($english);
        }

        foreach (['fr', 'es', 'de', 'it', 'nl'] as $locale) {
            app()->setLocale($locale);

            foreach ($messages as $key => [$english, $replace]) {
                expect(__($key, $replace))
                    ->not->toBe($key)
                    ->not->toBe($english);
            }
        }
    } finally {
        app()->setLocale($originalLocale);
    }
});

it('does not leave raw Media Library validation messages in services', function () {
    $serviceSource = collect([
        app_path('Services/MediaAssetUploadService.php'),
        app_path('Services/MediaAssetUsageService.php'),
    ])->map(fn (string $path): string => file_get_contents($path))->join("\n");

    foreach ([
        'The image could not be stored. Please try again.',
        'Only failed images can be retried.',
        'The original upload is no longer available. Remove this image and upload it again.',
        'Choose a JPEG, PNG, WebP, HEIC, or HEIF image.',
        'Choose a valid JPEG, PNG, WebP, HEIC, or HEIF image.',
        'The image must not be larger than 10 MB.',
        'Choose up to {$maximum} images.',
        'One or more selected images are unavailable or still processing.',
        'The selected workspace is unavailable.',
    ] as $message) {
        expect($serviceSource)->not->toContain($message);
    }

    expect(file_get_contents(app_path('Services/RecipeSopMediaAssetSynchronizer.php')))
        ->toContain("__('media_library.validation.procedure_image_limit'");

    expect(file_get_contents(app_path('Services/RecipeContentUpdater.php')))
        ->toContain("__('media_library.validation.description_text_only'");
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
        ->toContain("this.t('status.add_percentage', { amount })")
        ->toContain("this.t('status.balanced')")
        ->toContain("this.t('status.remove_percentage', { amount })")
        ->toContain("this.t('cosmetic.balance_label')")
        ->not->toContain("this.t('cosmetic.formula_balanced')")
        ->not->toContain("this.t('cosmetic.formula_unbalanced')")
        ->not->toContain("'Formula balanced'")
        ->not->toContain("'Formula must reach 100%'")
        ->and($versionSection)
        ->toContain("this.t('cosmetic.blend_only')")
        ->not->toContain("'Blend only'");

    expect(file_get_contents(resource_path('js/recipe-workbench/component.js')))
        ->toContain("buildCategoryOptions(this.ingredients, this.t('categories.all'))");
});

it('commits localized formula balance actions for every supported locale', function () {
    $catalogue = File::json(database_path('seeders/data/interface-translations.json'));
    $rows = collect($catalogue['translations'])
        ->where('group', 'workbench')
        ->whereIn('key', [
            'status.add_percentage',
            'status.balanced',
            'status.remove_percentage',
        ])
        ->keyBy('key');

    foreach ([
        'status.add_percentage',
        'status.balanced',
        'status.remove_percentage',
    ] as $key) {
        expect($rows)->toHaveKey($key)
            ->and(array_keys($rows[$key]['text']))->toBe(['de', 'es', 'fr', 'it', 'nl', 'pt_BR']);
    }
});
