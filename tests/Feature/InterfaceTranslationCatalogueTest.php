<?php

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
