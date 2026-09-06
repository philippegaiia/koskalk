<?php

use App\Enums\IngredientCategory;
use App\Models\InterfaceTranslation;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\Translations\EnglishTranslationSource;
use App\Services\Translations\InterfaceTranslationCatalogue;
use App\Services\Translations\SyncInterfaceTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed('Database\\Seeders\\SupportedLocaleSeeder');

    $this->catalogueDirectory = storage_path('framework/testing/interface-translation-catalogues');
    $this->cataloguePath = $this->catalogueDirectory.'/'.Str::uuid().'.json';

    File::ensureDirectoryExists($this->catalogueDirectory);

    $this->writeCatalogue = function (array $translations, ?array $locales = null): void {
        $payload = [
            'format' => 'soapkraft-interface-translations',
            'version' => 1,
            'locales' => $locales ?? ['de', 'es', 'fr', 'it', 'nl'],
            'translations' => $translations,
        ];

        File::put(
            $this->cataloguePath,
            json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL,
        );
    };
});

afterEach(function () {
    File::delete($this->cataloguePath);
});

it('resolves the category short labels that the ingredients table renders', function () {
    $english = require lang_path('en/ingredients.php');

    foreach (IngredientCategory::cases() as $category) {
        // Proves the enum resolves through the translator rather than leaking a
        // dotted key onto the badge.
        expect((string) $category->localizedShortLabel())
            ->toBe($english['categories'][$category->value]['short_label']);
    }
});

it('commits a complete reviewed translation for every owned interface key', function (): void {
    $source = app(EnglishTranslationSource::class)->all();
    $catalogue = File::json(database_path('seeders/data/interface-translations.json'));
    $rows = collect($catalogue['translations'])
        ->keyBy(fn (array $row): string => $row['group'].'.'.$row['key']);

    foreach ($source as $fullKey => $english) {
        expect($rows)->toHaveKey($fullKey);

        if (blank($english)) {
            continue;
        }

        foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
            expect(trim((string) data_get($rows[$fullKey], "text.{$locale}")))
                ->not->toBe('', "Missing {$locale} translation for {$fullKey} ({$english})");
        }
    }
});

it('keeps changed ingredient editor catalogue copy and placeholders aligned', function (): void {
    $source = app(EnglishTranslationSource::class);
    $rows = collect(File::json(database_path('seeders/data/interface-translations.json'))['translations'])
        ->keyBy(fn (array $row): string => $row['group'].'.'.$row['key']);
    $expected = [
        'ingredients.editor.create.heading' => [
            'de' => 'Zutat hinzufügen',
            'es' => 'Añadir ingrediente',
            'fr' => 'Ajouter un ingrédient',
            'it' => 'Aggiungi ingrediente',
            'nl' => 'Ingrediënt toevoegen',
            'pt_BR' => 'Adicionar ingrediente',
        ],
        'ingredients.editor.create.intro' => [
            'de' => 'Gib einen Namen ein und wähle eine Kategorie. Ergänze einen INCI-Namen und weitere Angaben, wenn sie verfügbar sind.',
            'es' => 'Introduce un nombre y elige una categoría. Añade el nombre INCI y otros datos cuando estén disponibles.',
            'fr' => 'Saisissez un nom et choisissez une catégorie. Ajoutez un nom INCI et des informations complémentaires si disponibles.',
            'it' => 'Inserisci un nome e scegli una categoria. Aggiungi un nome INCI e altri dettagli quando disponibili.',
            'nl' => 'Voer een naam in en kies een categorie. Voeg een INCI-naam en aanvullende gegevens toe wanneer die beschikbaar zijn.',
            'pt_BR' => 'Informe um nome e escolha uma categoria. Adicione um nome INCI e outros detalhes quando estiverem disponíveis.',
        ],
        'ingredients.editor.edit.heading' => [
            'de' => 'Zutat :ingredient bearbeiten',
            'es' => 'Editar :ingredient',
            'fr' => 'Modifier :ingredient',
            'it' => 'Modifica :ingredient',
            'nl' => ':ingredient bewerken',
            'pt_BR' => 'Editar :ingredient',
        ],
        'ingredients.editor.tabs.documents' => [
            'de' => 'Leitfaden & Dateien',
            'es' => 'Guía y archivos',
            'fr' => 'Conseils et fichiers',
            'it' => 'Indicazioni e file',
            'nl' => 'Begeleiding en bestanden',
            'pt_BR' => 'Orientações e arquivos',
        ],
        'ingredients.editor.tabs.compliance' => [
            'de' => 'Regulierungsdaten',
            'es' => 'Datos regulatorios',
            'fr' => 'Données réglementaires',
            'it' => 'Dati normativi',
            'nl' => 'Regelgevingsgegevens',
            'pt_BR' => 'Dados regulatórios',
        ],
        'ingredients.editor.classification_prompt.heading' => [
            'de' => 'Diese Zutat klassifizieren',
            'es' => 'Ayuda a clasificar este ingrediente',
            'fr' => 'Aidez à classer cet ingrédient',
            'it' => 'Aiuta a classificare questo ingrediente',
            'nl' => 'Help dit ingrediënt classificeren',
            'pt_BR' => 'Ajude a classificar este ingrediente',
        ],
        'ingredients.editor.composition.create_and_add' => [
            'de' => 'Zutat erstellen und hinzufügen',
            'es' => 'Crear y añadir ingrediente',
            'fr' => 'Créer et ajouter l’ingrédient',
            'it' => 'Crea e aggiungi ingrediente',
            'nl' => 'Ingrediënt maken en toevoegen',
            'pt_BR' => 'Criar e adicionar ingrediente',
        ],
        'ingredients.editor.composition.quick_description' => [
            'de' => 'Erstellt sofort eine Zutat in :workspace und fügt sie dieser Mischung hinzu. Sie bleibt dort, wenn du diese Mischung abbrichst.',
            'es' => 'Crea un ingrediente en :workspace de inmediato y lo añade a esta mezcla. Permanecerá allí si cancelas esta mezcla.',
            'fr' => 'Crée immédiatement un ingrédient dans :workspace, puis l’ajoute à ce mélange. Il y restera si vous annulez ce mélange.',
            'it' => 'Crea subito un ingrediente in :workspace e lo aggiunge a questa miscela. Rimarrà lì se annulli questa miscela.',
            'nl' => 'Maakt direct een ingrediënt aan in :workspace en voegt het toe aan deze blend. Het blijft daar staan als je deze blend annuleert.',
            'pt_BR' => 'Cria imediatamente um ingrediente em :workspace e o adiciona a esta mistura. Ele permanecerá lá se você cancelar esta mistura.',
        ],
        'ingredients.editor.workspace_context.heading' => [
            'de' => 'In :workspace',
            'es' => 'En :workspace',
            'fr' => 'Dans :workspace',
            'it' => 'In :workspace',
            'nl' => 'In :workspace',
            'pt_BR' => 'Em :workspace',
        ],
        'ingredients.editor.workspace_context.eyebrow' => [
            'de' => 'Arbeitsbereichseinstellungen',
            'es' => 'Configuración del espacio de trabajo',
            'fr' => 'Paramètres de l’espace de travail',
            'it' => 'Impostazioni dello spazio di lavoro',
            'nl' => 'Werkruimte-instellingen',
            'pt_BR' => 'Configurações do espaço de trabalho',
        ],
        'ingredients.editor.workspace_context.description' => [
            'de' => 'Verwalte arbeitsbereichsspezifische Hinweise und den Materialcode für diese Zutat.',
            'es' => 'Gestiona las indicaciones y el código de material específicos de este espacio de trabajo para este ingrediente.',
            'fr' => 'Gérez les conseils et le code matière propres à cet espace de travail pour cet ingrédient.',
            'it' => 'Gestisci le indicazioni e il codice materiale specifici di questo spazio di lavoro per questo ingrediente.',
            'nl' => 'Beheer de werkruimterichtlijnen en materiaalcode voor dit ingrediënt.',
            'pt_BR' => 'Gerencie as orientações e o código de material específicos deste espaço de trabalho para este ingrediente.',
        ],
        'ingredients.editor.reference.aliases' => [
            'de' => 'Alternative Namen',
            'es' => 'Nombres alternativos',
            'fr' => 'Noms alternatifs',
            'it' => 'Nomi alternativi',
            'nl' => 'Alternatieve namen',
            'pt_BR' => 'Nomes alternativos',
        ],
        'ingredients.editor.reference.aromatic_compliance' => [
            'de' => 'Aromatische Handhabung',
            'es' => 'Gestión aromática',
            'fr' => 'Gestion aromatique',
            'it' => 'Gestione aromatica',
            'nl' => 'Aromatische verwerking',
            'pt_BR' => 'Tratamento aromático',
        ],
        'ingredients.editor.reference.required' => [
            'de' => 'Aktiviert',
            'es' => 'Activado',
            'fr' => 'Activé',
            'it' => 'Abilitato',
            'nl' => 'Ingeschakeld',
            'pt_BR' => 'Ativado',
        ],
        'ingredients.editor.reference.not_required' => [
            'de' => 'Nicht aktiviert',
            'es' => 'No activado',
            'fr' => 'Non activé',
            'it' => 'Non abilitato',
            'nl' => 'Niet ingeschakeld',
            'pt_BR' => 'Não ativado',
        ],
        'ingredients.editor.reference.notes' => [
            'de' => 'Quellennotizen',
            'es' => 'Notas de origen',
            'fr' => 'Notes de source',
            'it' => 'Note sulla fonte',
            'nl' => 'Bronnotities',
            'pt_BR' => 'Notas da fonte',
        ],
        'ingredients.editor.reference.guidance' => [
            'de' => 'Zutatenhinweise',
            'es' => 'Guía del ingrediente',
            'fr' => 'Conseils sur l’ingrédient',
            'it' => 'Indicazioni sull’ingrediente',
            'nl' => 'Ingrediëntrichtlijnen',
            'pt_BR' => 'Orientações sobre o ingrediente',
        ],
    ];
    $placeholders = static function (string $text): array {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches);

        return array_values(array_unique($matches[0]));
    };

    foreach ($expected as $fullKey => $translations) {
        [$group, $key] = explode('.', $fullKey, 2);
        $english = $source->get($group, $key);

        expect($rows)->toHaveKey($fullKey)
            ->and($translations)->toBe($rows[$fullKey]['text'])
            ->and($english)->toBeString();

        $expectedPlaceholders = $placeholders($english);

        foreach ($translations as $locale => $translation) {
            expect($placeholders($translation), "{$fullKey} [{$locale}]")
                ->toBe($expectedPlaceholders);
        }
    }
});

it('localizes the high-visibility Inventory UX keys into every supported locale', function (string $key): void {
    $fullKey = "production_bench.{$key}";
    $english = app(EnglishTranslationSource::class)->get('production_bench', $key);
    expect($english)->not->toBe('');

    $catalogue = File::json(database_path('seeders/data/interface-translations.json'));
    $rows = collect($catalogue['translations'])
        ->keyBy(fn (array $row): string => $row['group'].'.'.$row['key']);

    expect($rows)->toHaveKey($fullKey);

    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        $value = trim((string) data_get($rows[$fullKey], "text.{$locale}"));

        expect($value, "{$fullKey} [{$locale}]")->not->toBe('')
            ->and($value, "{$fullKey} [{$locale}] must not be English fallback")->not->toBe($english);
    }
})->with([
    'inventory.stock_by_material',
    'inventory.lot_register',
    'inventory.current_position',
    'inventory.buffer_stock',
    'inventory.open_lots',
    'inventory.related_supplier_listings',
    'inventory.period_activity',
    'inventory.below_buffer',
    'inventory.filter_material_type',
    'inventory.filter_stock_state',
    'inventory.filter_demand',
    'inventory.lot_scope',
    'inventory.lot_register_search_help',
]);

it('does not ship English placeholders for Inventory translations', function (): void {
    $source = app(EnglishTranslationSource::class);
    $catalogue = File::json(database_path('seeders/data/interface-translations.json'));
    $rows = collect($catalogue['translations'])
        ->filter(fn (array $row): bool => $row['group'] === 'production_bench'
            && str_starts_with($row['key'], 'inventory.'));
    $languageNeutral = [
        'de:inventory.applied_filter' => 'Filter is the established German loanword.',
        'de:inventory.material' => 'Material is identical in German.',
        'de:inventory.optional' => 'Optional is identical in German.',
        'de:inventory.lot_material' => 'Material is identical in German.',
        'fr:inventory.date' => 'Date is identical in French.',
        'fr:inventory.source' => 'Source is identical in French.',
        'fr:inventory.stock' => 'Stock is the established French loanword.',
        'nl:inventory.applied_filter' => 'Filter is the established Dutch loanword.',
        'nl:inventory.filters' => 'Filters is identical in Dutch.',
        'pt_BR:inventory.item' => 'Item is the established Brazilian Portuguese loanword.',
        'pt_BR:inventory.material' => 'Material is identical in Brazilian Portuguese.',
        'pt_BR:inventory.lot_material' => 'Material is identical in Brazilian Portuguese.',
        'es:inventory.material' => 'Material is identical in Spanish.',
        'es:inventory.stock' => 'Stock is the established Spanish loanword.',
        'es:inventory.lot_material' => 'Material is identical in Spanish.',
    ];

    foreach ($rows as $row) {
        $english = $source->get('production_bench', $row['key']);

        foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
            $value = trim((string) data_get($row, "text.{$locale}"));
            $coordinate = "{$locale}:{$row['key']}";

            expect($value, $coordinate)->not->toBe('');

            if (! array_key_exists($coordinate, $languageNeutral)) {
                expect($value, "{$coordinate} must not be English fallback")
                    ->not->toBe($english);
            }
        }
    }
});

it('commits reviewed internal material code terminology for packaging workflows', function (): void {
    $source = app(EnglishTranslationSource::class);
    $catalogue = File::json(database_path('seeders/data/interface-translations.json'));
    $rows = collect($catalogue['translations'])
        ->keyBy(fn (array $row): string => $row['group'].'.'.$row['key']);

    foreach ([
        'packaging.editor.form.material_code.label' => 'Internal material code',
        'packaging.table.material_code' => 'Internal material code',
        'workbench.costing.packaging.material_code' => 'Internal material code',
        'workbench.packaging.plan.material_code' => 'Internal material code',
        'workbench.packaging.modal.material_code' => 'Internal material code (optional)',
    ] as $fullKey => $english) {
        [$group, $key] = explode('.', $fullKey, 2);

        expect($source->get($group, $key))->toBe($english)
            ->and($rows)->toHaveKey($fullKey)
            ->and(array_keys($rows[$fullKey]['text']))->toBe(['de', 'es', 'fr', 'it', 'nl', 'pt_BR']);
    }

    expect($source->get('packaging', 'editor.form.material_code.helper'))
        ->toContain('Koskalk does not generate it.')
        ->and($source->get('workbench', 'packaging.modal.material_code_helper'))
        ->toContain('Koskalk does not generate it.');
});

it('commits the reviewed classification helper description in every supported locale', function (): void {
    $catalogue = app(InterfaceTranslationCatalogue::class)
        ->read(database_path('seeders/data/interface-translations.json'));
    $description = collect($catalogue['translations'])
        ->first(fn (array $row): bool => $row['group'] === 'ingredients'
            && $row['key'] === 'editor.classification_prompt.description');

    expect($description['text'] ?? null)->toBe([
        'de' => 'Erzeuge einen Prompt, um Klassifizierung, Identifikatoren, COSING-Funktionen und kurze fachliche Hinweise zu recherchieren. Das Formular wird dadurch nicht geändert.',
        'es' => 'Genera un prompt para investigar la clasificación, los identificadores, las funciones COSING y notas profesionales breves. No modificará este formulario.',
        'fr' => 'Générez un prompt pour rechercher la classification, les identifiants, les fonctions COSING et de brèves notes professionnelles. Il ne modifiera pas ce formulaire.',
        'it' => 'Genera un prompt per ricercare classificazione, identificatori, funzioni COSING e brevi note professionali. Non modificherà questo modulo.',
        'nl' => 'Genereer een prompt om classificatie, identificatiegegevens, COSING-functies en korte professionele notities te onderzoeken. Dit formulier wordt niet gewijzigd.',
        'pt_BR' => 'Gere um prompt para pesquisar classificação, identificadores, funções COSING e notas profissionais concisas. Ele não alterará este formulário.',
    ]);
});

it('commits reviewed workspace ingredient alerts and document picker copy', function (): void {
    $catalogue = app(InterfaceTranslationCatalogue::class)
        ->read(database_path('seeders/data/interface-translations.json'));
    $translations = collect($catalogue['translations'])
        ->keyBy(fn (array $row): string => $row['group'].'.'.$row['key']);

    expect($translations['ingredients.editor.status.invalid']['text'] ?? null)->toBe([
        'de' => 'Prüfe die markierten Felder.',
        'es' => 'Revisa los campos resaltados.',
        'fr' => 'Vérifiez les champs signalés.',
        'it' => 'Controlla i campi evidenziati.',
        'nl' => 'Controleer de gemarkeerde velden.',
        'pt_BR' => 'Revise os campos destacados.',
    ])->and($translations['ingredients.editor.validation.blend_required']['text'] ?? null)->toBe([
        'de' => 'Füge mindestens eine Zutat hinzu, um diese Mischung zu speichern.',
        'es' => 'Añade al menos un ingrediente para guardar esta mezcla.',
        'fr' => 'Ajoutez au moins un ingrédient pour enregistrer ce mélange.',
        'it' => 'Aggiungi almeno un ingrediente per salvare questa miscela.',
        'nl' => 'Voeg minstens één ingrediënt toe om dit mengsel op te slaan.',
        'pt_BR' => 'Adicione pelo menos um ingrediente para salvar esta mistura.',
    ])->and($translations['media_library.picker.choose_documents']['text'] ?? null)->toBe([
        'de' => 'Dokumente auswählen',
        'es' => 'Elegir documentos',
        'fr' => 'Choisir des documents',
        'it' => 'Scegli documenti',
        'nl' => 'Documenten kiezen',
        'pt_BR' => 'Escolher documentos',
    ])->and($translations['media_library.picker.document_upload_failed']['text'] ?? null)->toBe([
        'de' => 'Die PDF-Datei konnte nicht hochgeladen werden. Versuche es erneut.',
        'es' => 'No se pudo cargar el PDF. Inténtalo de nuevo.',
        'fr' => 'Le PDF n’a pas pu être importé. Réessayez.',
        'it' => 'Non è stato possibile caricare il PDF. Riprova.',
        'nl' => 'De PDF kon niet worden geüpload. Probeer het opnieuw.',
        'pt_BR' => 'Não foi possível enviar o PDF. Tente novamente.',
    ]);
});

it('commits reviewed workspace ingredient guidance copy', function (): void {
    $catalogue = app(InterfaceTranslationCatalogue::class)
        ->read(database_path('seeders/data/interface-translations.json'));
    $translations = collect($catalogue['translations'])
        ->keyBy(fn (array $row): string => $row['group'].'.'.$row['key']);

    foreach ([
        'ingredients.editor.workspace_guidance.eyebrow',
        'ingredients.editor.workspace_guidance.heading',
        'ingredients.editor.workspace_guidance.platform_badge',
        'ingredients.editor.workspace_guidance.workspace_badge',
        'ingredients.editor.workspace_guidance.helper',
        'ingredients.editor.workspace_guidance.read_only',
        'ingredients.editor.workspace_guidance.customize',
        'ingredients.editor.workspace_guidance.edit',
        'ingredients.editor.workspace_guidance.save',
        'ingredients.editor.workspace_guidance.cancel',
        'ingredients.editor.workspace_guidance.use_platform',
        'ingredients.editor.workspace_guidance.use_workspace',
        'ingredients.editor.workspace_guidance.platform_confirm',
        'ingredients.editor.workspace_guidance.platform_selected',
        'ingredients.editor.workspace_guidance.workspace_selected',
        'ingredients.editor.workspace_guidance.saved',
        'ingredients.editor.validation.workspace_guidance_forbidden',
        'ingredients.editor.validation.workspace_guidance_required',
        'ingredients.editor.validation.workspace_guidance_max',
        'ingredients.editor.validation.workspace_guidance_missing',
    ] as $fullKey) {
        expect($translations)->toHaveKey($fullKey)
            ->and(array_keys($translations[$fullKey]['text']))
            ->toBe(['de', 'es', 'fr', 'it', 'nl', 'pt_BR']);

        foreach ($translations[$fullKey]['text'] as $text) {
            expect(trim((string) $text))->not->toBe('');
        }
    }
});

it('commits reviewed labels for additional ingredient identifier schemes', function (): void {
    $catalogue = app(InterfaceTranslationCatalogue::class)
        ->read(database_path('seeders/data/interface-translations.json'));
    $translations = collect($catalogue['translations'])
        ->keyBy(fn (array $row): string => $row['group'].'.'.$row['key']);

    expect($translations['ingredients.editor.identity.identifier_schemes.inchikey']['text'] ?? null)->toBe([
        'de' => 'InChIKey',
        'es' => 'InChIKey',
        'fr' => 'InChIKey',
        'it' => 'InChIKey',
        'nl' => 'InChIKey',
        'pt_BR' => 'InChIKey',
    ])->and($translations['ingredients.editor.identity.identifier_schemes.pubchem_cid']['text'] ?? null)->toBe([
        'de' => 'PubChem CID',
        'es' => 'PubChem CID',
        'fr' => 'PubChem CID',
        'it' => 'PubChem CID',
        'nl' => 'PubChem CID',
        'pt_BR' => 'CID PubChem',
    ]);
});

it('commits the reviewed preservatives and preservation boosters label', function (): void {
    $catalogue = app(InterfaceTranslationCatalogue::class)
        ->read(database_path('seeders/data/interface-translations.json'));
    $label = collect($catalogue['translations'])
        ->first(fn (array $row): bool => $row['group'] === 'ingredients'
            && $row['key'] === 'subcategories.preservatives.label');

    expect($label['text'] ?? null)->toBe([
        'de' => 'Konservierungsmittel & Konservierungsverstärker',
        'es' => 'Conservantes y potenciadores de la conservación',
        'fr' => 'Conservateurs et boosters de conservation',
        'it' => 'Conservanti e coadiuvanti della conservazione',
        'nl' => 'Conserveermiddelen en conserveringsboosters',
        'pt_BR' => 'Conservantes e potencializadores de conservação',
    ]);
});

it('exports a deterministic human-reviewable catalogue without database metadata', function () {
    InterfaceTranslation::query()->create([
        'group' => 'public',
        'key' => 'navigation.product',
        'text' => [
            'nl' => "Product\nopenen",
            'fr' => 'Produit',
            'de' => 'Produkt',
            'it' => 'Prodotto',
            'es' => 'Producto',
            'pt_BR' => 'Produto',
        ],
    ]);
    InterfaceTranslation::query()->create([
        'group' => 'auth',
        'key' => 'login.heading',
        'text' => [],
    ]);
    InterfaceTranslation::query()->create([
        'group' => 'homepage',
        'key' => 'hero.title',
        'text' => ['fr' => 'Accueil'],
    ]);

    $this->artisan('translations:catalogue:export', ['--path' => $this->cataloguePath])
        ->assertSuccessful();

    $firstExport = File::get($this->cataloguePath);

    $this->artisan('translations:catalogue:export', ['--path' => $this->cataloguePath])
        ->assertSuccessful();

    $decoded = json_decode($firstExport, true, 512, JSON_THROW_ON_ERROR);

    expect(File::get($this->cataloguePath))->toBe($firstExport)
        ->and($firstExport)->toEndWith(PHP_EOL)
        ->and($decoded['locales'])->toBe(['de', 'es', 'fr', 'it', 'nl', 'pt_BR'])
        ->and($decoded['translations'])->toBe([
            [
                'group' => 'auth',
                'key' => 'login.heading',
                'text' => [],
            ],
            [
                'group' => 'public',
                'key' => 'navigation.product',
                'text' => [
                    'de' => 'Produkt',
                    'es' => 'Producto',
                    'fr' => 'Produit',
                    'it' => 'Prodotto',
                    'nl' => "Product\nopenen",
                    'pt_BR' => 'Produto',
                ],
            ],
        ])
        ->and($firstExport)->not->toContain(
            '"id"',
            '"created_at"',
            '"updated_at"',
            '"group": "homepage"',
        )
        ->and(InterfaceTranslation::query()->where('group', 'homepage')->where('key', 'hero.title')->exists())
        ->toBeTrue();
});

it('keeps the reviewed Brazilian locale inactive until it is enabled', function (): void {
    expect(SupportedLocale::query()->where('code', 'pt_BR')->value('is_active'))->toBeFalse()
        ->and(config('interface-translations.catalogue_locales'))->toBe(['de', 'es', 'fr', 'it', 'nl', 'pt_BR']);

    $this->artisan('translations:catalogue:export', ['--path' => $this->cataloguePath])
        ->assertSuccessful();

    expect(File::json($this->cataloguePath)['locales'])
        ->toBe(['de', 'es', 'fr', 'it', 'nl', 'pt_BR']);
});

it('sorts exported keys bytewise instead of relying on the database collation', function () {
    InterfaceTranslation::query()->create([
        'group' => 'media_library',
        'key' => 'processing_stages.storing_document',
        'text' => [],
    ]);
    InterfaceTranslation::query()->create([
        'group' => 'media_library',
        'key' => 'processing.failed',
        'text' => [],
    ]);

    $this->artisan('translations:catalogue:export', ['--path' => $this->cataloguePath])
        ->assertSuccessful();

    $translations = json_decode(
        File::get($this->cataloguePath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['translations'];

    expect(Arr::map(
        $translations,
        fn (array $translation): string => "{$translation['group']}.{$translation['key']}",
    ))->toBe([
        'media_library.processing.failed',
        'media_library.processing_stages.storing_document',
    ]);
});

it('round trips Unicode multiline and placeholder values and remains idempotent', function () {
    $translations = [
        'de' => 'Original: :name',
        'es' => 'Original: :name',
        'fr' => "Original : :name\nRéutilisable",
        'it' => 'Originale: :name',
        'nl' => 'Origineel: :name',
        'pt_BR' => 'Original: :name',
    ];

    InterfaceTranslation::query()->create([
        'group' => 'media_library',
        'key' => 'original_filename',
        'text' => $translations,
    ]);

    $this->artisan('translations:catalogue:export', ['--path' => $this->cataloguePath])
        ->assertSuccessful();

    InterfaceTranslation::query()->delete();

    $this->artisan('translations:catalogue:import', [
        '--path' => $this->cataloguePath,
        '--mode' => 'authoritative',
    ])->assertSuccessful();

    $this->artisan('translations:catalogue:import', [
        '--path' => $this->cataloguePath,
        '--mode' => 'authoritative',
    ])->assertSuccessful();

    expect(InterfaceTranslation::query()->count())->toBe(1)
        ->and(InterfaceTranslation::query()->firstOrFail()->text)->toBe([
            'de' => 'Original: :name',
            'es' => 'Original: :name',
            'fr' => "Original : :name\nRéutilisable",
            'it' => 'Originale: :name',
            'nl' => 'Origineel: :name',
            'pt_BR' => 'Original: :name',
        ]);
});

it('rejects malformed catalogue data before writing anything', function (string $contents) {
    $existing = InterfaceTranslation::query()->create([
        'group' => 'auth',
        'key' => 'login.heading',
        'text' => ['fr' => 'Texte existant'],
    ]);

    File::put($this->cataloguePath, $contents);

    $this->artisan('translations:catalogue:import', [
        '--path' => $this->cataloguePath,
        '--mode' => 'authoritative',
    ])->assertFailed();

    expect($existing->refresh()->text)->toBe(['fr' => 'Texte existant'])
        ->and(InterfaceTranslation::query()->count())->toBe(1);
})->with([
    'invalid JSON' => ['{"format":'],
    'missing required key' => [json_encode([
        'format' => 'soapkraft-interface-translations',
        'version' => 1,
        'locales' => ['fr'],
        'translations' => [['group' => 'auth', 'text' => ['fr' => 'Connexion']]],
    ], JSON_THROW_ON_ERROR)],
    'duplicate translation key' => [json_encode([
        'format' => 'soapkraft-interface-translations',
        'version' => 1,
        'locales' => ['fr'],
        'translations' => [
            ['group' => 'auth', 'key' => 'login.heading', 'text' => ['fr' => 'Connexion']],
            ['group' => 'auth', 'key' => 'login.heading', 'text' => ['fr' => 'Autre']],
        ],
    ], JSON_THROW_ON_ERROR)],
    'English database value' => [json_encode([
        'format' => 'soapkraft-interface-translations',
        'version' => 1,
        'locales' => ['en', 'fr'],
        'translations' => [
            ['group' => 'auth', 'key' => 'login.heading', 'text' => ['en' => 'Sign in', 'fr' => 'Connexion']],
        ],
    ], JSON_THROW_ON_ERROR)],
    'unsupported locale' => [json_encode([
        'format' => 'soapkraft-interface-translations',
        'version' => 1,
        'locales' => ['fr', 'xx'],
        'translations' => [
            ['group' => 'auth', 'key' => 'login.heading', 'text' => ['fr' => 'Connexion', 'xx' => 'Unknown']],
        ],
    ], JSON_THROW_ON_ERROR)],
    'partial locale map' => [json_encode([
        'format' => 'soapkraft-interface-translations',
        'version' => 1,
        'locales' => ['de', 'es', 'fr'],
        'translations' => [
            [
                'group' => 'auth',
                'key' => 'login.heading',
                'text' => ['de' => 'Anmelden', 'fr' => 'Connexion'],
            ],
        ],
    ], JSON_THROW_ON_ERROR)],
    'unowned key' => [json_encode([
        'format' => 'soapkraft-interface-translations',
        'version' => 1,
        'locales' => ['fr'],
        'translations' => [
            ['group' => 'validation', 'key' => 'required', 'text' => ['fr' => 'Requis']],
        ],
    ], JSON_THROW_ON_ERROR)],
]);

it('rejects catalogue translations that are not sorted by group and key', function (): void {
    ($this->writeCatalogue)([
        [
            'group' => 'public',
            'key' => 'navigation.product',
            'text' => [
                'de' => 'Produkt',
                'es' => 'Producto',
                'fr' => 'Produit',
                'it' => 'Prodotto',
                'nl' => 'Product',
            ],
        ],
        [
            'group' => 'auth',
            'key' => 'login.heading',
            'text' => [
                'de' => 'Anmelden',
                'es' => 'Iniciar sesión',
                'fr' => 'Connexion',
                'it' => 'Accedi',
                'nl' => 'Inloggen',
            ],
        ],
    ]);

    $this->artisan('translations:catalogue:import', [
        '--path' => $this->cataloguePath,
        '--mode' => 'authoritative',
    ])->assertFailed();
});

it('rejects catalogue values that change named placeholders', function () {
    ($this->writeCatalogue)([
        [
            'group' => 'media_library',
            'key' => 'original_filename',
            'text' => [
                'de' => 'Originaldatei',
                'es' => 'Original: :name',
                'fr' => 'Original : :name',
                'it' => 'Originale: :name',
                'nl' => 'Origineel: :name',
            ],
        ],
    ]);

    $this->artisan('translations:catalogue:import', [
        '--path' => $this->cataloguePath,
        '--mode' => 'authoritative',
    ])->assertFailed();

    expect(InterfaceTranslation::query()->exists())->toBeFalse();
});

it('updates matching translations in explicit catalogue-authoritative mode', function () {
    InterfaceTranslation::query()->create([
        'group' => 'auth',
        'key' => 'login.heading',
        'text' => ['fr' => 'Ancienne connexion', 'es' => 'Conexión anterior'],
    ]);

    ($this->writeCatalogue)([
        [
            'group' => 'auth',
            'key' => 'login.heading',
            'text' => ['es' => 'Accede a tu espacio', 'fr' => 'Connectez-vous à votre espace'],
        ],
    ], ['es', 'fr']);

    $this->artisan('translations:catalogue:import', [
        '--path' => $this->cataloguePath,
        '--mode' => 'authoritative',
    ])->assertSuccessful();

    expect(InterfaceTranslation::query()->firstOrFail()->text)->toBe([
        'es' => 'Accede a tu espacio',
        'fr' => 'Connectez-vous à votre espace',
    ]);
});

it('fills only blank or missing values in preserve-existing mode', function () {
    InterfaceTranslation::query()->create([
        'group' => 'auth',
        'key' => 'login.heading',
        'text' => [
            'de' => '',
            'fr' => 'Traduction de production',
        ],
    ]);

    ($this->writeCatalogue)([
        [
            'group' => 'auth',
            'key' => 'login.heading',
            'text' => [
                'de' => 'Melde dich in deinem Arbeitsbereich an',
                'es' => 'Accede a tu espacio',
                'fr' => 'Traduction du catalogue',
            ],
        ],
    ], ['de', 'es', 'fr']);

    $this->artisan('translations:catalogue:import', [
        '--path' => $this->cataloguePath,
        '--mode' => 'preserve-existing',
    ])->assertSuccessful();

    expect(InterfaceTranslation::query()->firstOrFail()->text)->toBe([
        'de' => 'Melde dich in deinem Arbeitsbereich an',
        'es' => 'Accede a tu espacio',
        'fr' => 'Traduction de production',
    ]);
});

it('flushes affected translation caches after the import transaction returns', function () {
    InterfaceTranslation::query()->create([
        'group' => 'auth',
        'key' => 'login.heading',
        'text' => ['fr' => 'Ancienne connexion'],
    ]);

    ($this->writeCatalogue)([
        [
            'group' => 'auth',
            'key' => 'login.heading',
            'text' => ['fr' => 'Connectez-vous à votre espace'],
        ],
    ], ['fr']);

    $baselineTransactionLevel = DB::connection()->transactionLevel();
    $forgetTransactionLevels = [];

    Cache::shouldReceive('forget')
        ->atLeast()
        ->once()
        ->andReturnUsing(function (string $key) use (&$forgetTransactionLevels): bool {
            $forgetTransactionLevels[] = DB::connection()->transactionLevel();

            return true;
        });

    $this->artisan('translations:catalogue:import', [
        '--path' => $this->cataloguePath,
        '--mode' => 'authoritative',
    ])->assertSuccessful();

    expect($forgetTransactionLevels)->toContain($baselineTransactionLevel);
});

it('does not delete rows missing from the catalogue or modify unrelated tables', function () {
    $user = User::factory()->create();
    $missingFromCatalogue = InterfaceTranslation::query()->create([
        'group' => 'auth',
        'key' => 'login.email',
        'text' => ['fr' => 'Adresse e-mail'],
    ]);

    ($this->writeCatalogue)([
        [
            'group' => 'auth',
            'key' => 'login.heading',
            'text' => ['fr' => 'Connectez-vous à votre espace'],
        ],
    ], ['fr']);

    $this->artisan('translations:catalogue:import', [
        '--path' => $this->cataloguePath,
        '--mode' => 'authoritative',
    ])->assertSuccessful();

    expect($missingFromCatalogue->refresh()->text)->toBe(['fr' => 'Adresse e-mail'])
        ->and($user->refresh()->only(['name', 'email']))->toBe($user->only(['name', 'email']))
        ->and(InterfaceTranslation::query()->count())->toBe(2);
});

it('recovers reviewed translations after locale seeding and synchronization on an empty table', function () {
    ($this->writeCatalogue)([
        [
            'group' => 'auth',
            'key' => 'login.heading',
            'text' => [
                'de' => 'Melde dich in deinem Arbeitsbereich an',
                'es' => 'Accede a tu espacio',
                'fr' => 'Connectez-vous à votre espace',
                'it' => 'Accedi al tuo spazio di lavoro',
                'nl' => 'Meld je aan bij je werkruimte',
            ],
        ],
    ]);

    InterfaceTranslation::query()->delete();

    app(SyncInterfaceTranslations::class)->handle();

    $this->artisan('translations:catalogue:import', [
        '--path' => $this->cataloguePath,
        '--mode' => 'authoritative',
    ])->assertSuccessful();

    expect(InterfaceTranslation::query()
        ->where('group', 'auth')
        ->where('key', 'login.heading')
        ->firstOrFail()
        ->text)->toBe([
            'de' => 'Melde dich in deinem Arbeitsbereich an',
            'es' => 'Accede a tu espacio',
            'fr' => 'Connectez-vous à votre espace',
            'it' => 'Accedi al tuo spazio di lavoro',
            'nl' => 'Meld je aan bij je werkruimte',
        ]);
});

it('recovers the committed reviewed catalogue after synchronization on an empty table', function () {
    InterfaceTranslation::query()->delete();

    app(SyncInterfaceTranslations::class)->handle();

    $this->artisan('translations:catalogue:import', [
        '--mode' => 'authoritative',
    ])->assertSuccessful();

    expect(InterfaceTranslation::query()
        ->where('group', 'media')
        ->where('key', 'current_image')
        ->firstOrFail()
        ->text)->toBe([
            'de' => 'Aktuelles Bild',
            'es' => 'Imagen actual',
            'fr' => 'Image actuelle',
            'it' => 'Immagine attuale',
            'nl' => 'Huidige afbeelding',
            'pt_BR' => 'Imagem atual',
        ])
        ->and(InterfaceTranslation::query()
            ->where('group', 'media_library')
            ->where('key', 'title')
            ->firstOrFail()
            ->text)->toBe([
                'de' => 'Medienbibliothek',
                'es' => 'Biblioteca multimedia',
                'fr' => 'Médiathèque',
                'it' => 'Libreria multimediale',
                'nl' => 'Mediabibliotheek',
                'pt_BR' => 'Biblioteca de mídia',
            ]);
});

it('owns every media library key including the sidebar navigation key', function () {
    $source = app(EnglishTranslationSource::class);
    $mediaLibrary = Arr::dot(require lang_path('en/media_library.php'));

    expect($source->all())->toHaveKey('navigation.items.media_library');

    foreach ($mediaLibrary as $key => $value) {
        expect($value)->toBeString()
            ->and($source->get('media_library', $key))->toBe($value);
    }
});

it('commits every localized media batch upload string', function () {
    $catalogue = File::json(database_path('seeders/data/interface-translations.json'));
    $requiredKeys = [
        'batch_file_failed',
        'batch_limit',
        'batch_position',
        'batch_quota',
        'choose_files',
        'picker.choose_file',
        'picker.no_file_selected',
        'remove_file',
        'selected_files',
        'upload_selected',
    ];

    $rows = collect($catalogue['translations'])
        ->where('group', 'media_library')
        ->keyBy('key');

    foreach ($requiredKeys as $key) {
        expect($rows)->toHaveKey($key)
            ->and(array_keys($rows[$key]['text']))->toBe(['de', 'es', 'fr', 'it', 'nl', 'pt_BR']);
    }
});

it('commits the localized direct square crop guidance', function () {
    $catalogue = File::json(database_path('seeders/data/interface-translations.json'));
    $requiredKeys = [
        'crop.instruction',
        'crop.preview',
    ];

    $rows = collect($catalogue['translations'])
        ->where('group', 'media_library')
        ->keyBy('key');

    foreach ($requiredKeys as $key) {
        expect($rows)->toHaveKey($key)
            ->and(array_keys($rows[$key]['text']))->toBe(['de', 'es', 'fr', 'it', 'nl', 'pt_BR']);
    }
});

it('commits every localized destructive media action string', function () {
    $catalogue = File::json(database_path('seeders/data/interface-translations.json'));
    $requiredKeys = [
        'panel.deleting',
        'panel.delete_everywhere_confirm',
        'panel.delete_everywhere_warning',
        'panel.delete_impact_join',
        'panel.delete_impact_other',
        'panel.delete_impact_recipes',
        'panel.delete_in_use',
    ];
    $rows = collect($catalogue['translations'])
        ->where('group', 'media_library')
        ->keyBy('key');

    foreach ($requiredKeys as $key) {
        expect($rows)->toHaveKey($key)
            ->and(array_keys($rows[$key]['text']))->toBe(['de', 'es', 'fr', 'it', 'nl', 'pt_BR']);
    }
});

it('commits every production lifecycle key for every supported locale', function () {
    $englishKeys = collect(app(EnglishTranslationSource::class)->all())
        ->keys()
        ->filter(fn (string $key): bool => str_starts_with($key, 'production_bench.'))
        ->map(fn (string $key): string => Str::after($key, 'production_bench.'))
        ->sort()
        ->values()
        ->all();
    $rows = collect(File::json(database_path('seeders/data/interface-translations.json'))['translations'])
        ->where('group', 'production_bench')
        ->keyBy('key');
    $locales = ['de', 'es', 'fr', 'it', 'nl', 'pt_BR'];

    expect($rows->keys()->sort()->values()->all())->toBe($englishKeys);

    foreach ($rows as $row) {
        expect(array_keys($row['text']))->toBe($locales);

        foreach ($row['text'] as $translation) {
            expect(trim($translation))->not->toBe('');
        }
    }
});

it('commits the canonical alkali copy with intact placeholders in every locale', function (): void {
    $catalogue = File::json(database_path('seeders/data/interface-translations.json'));
    $rows = collect($catalogue['translations'])
        ->keyBy(fn (array $row): string => $row['group'].'.'.$row['key']);

    foreach ([
        'ingredients.alkalis.validation.canonical_missing' => [':key'],
        'ingredients.alkalis.koh_with_purity' => [':name', ':purity'],
        'ingredients.editor.validation.soapmaking_alkalis_platform_only' => [],
        'workbench.costing.ingredients.koh_with_purity' => [':name', ':purity'],
    ] as $fullKey => $placeholders) {
        expect($rows)->toHaveKey($fullKey);

        foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
            $text = trim((string) data_get($rows[$fullKey], "text.{$locale}"));
            expect($text, "{$fullKey} [{$locale}]")->not->toBe('');

            foreach ($placeholders as $placeholder) {
                expect($text, "{$fullKey} [{$locale}]")->toContain($placeholder);
            }
        }
    }
});
