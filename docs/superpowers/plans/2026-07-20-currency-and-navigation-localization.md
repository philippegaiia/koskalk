# Currency and Navigation Localization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace manually maintained currency translations with Symfony Intl, restrict database translations to Soapkraft-owned copy, add Dutch, and contextually translate the authenticated side menu.

**Architecture:** `CurrencyCatalog` wraps Symfony Intl and is the only source for selectable currency codes and localized currency metadata. `EnglishTranslationSource` reads an explicit ownership manifest, while `translations:sync --prune` removes non-owned database rows only when requested. English navigation remains in `lang/en`; contextual non-English drafts are inserted into the local Spatie `language_lines` table for browser review.

**Tech Stack:** PHP 8.5, Laravel 13, Symfony Intl, Spatie Laravel Translation Loader, Livewire 4, Pest 4, Laravel Lang

---

## File structure

- Create `app/Services/CurrencyCatalog.php`: localized currency names, symbols, fraction digits, active legal-tender choices, and historical-code fallbacks.
- Create `config/currency.php`: the application default currency code only.
- Create `config/interface-translations.php`: explicit application-owned translation groups and key patterns.
- Create `lang/en/navigation.php`: canonical English side-menu copy.
- Create Dutch Laravel Lang files through `lang:add nl`.
- Modify `EnglishTranslationSource` and `SyncInterfaceTranslations`: ownership filtering and explicit pruning.
- Modify currency consumers in `User`, `SettingsIndex`, `RecipeWorkbenchViewDataBuilder`, and `RecipeVersionCostingSynchronizer`.
- Modify `app-shell.blade.php`: use complete navigation keys.
- Delete `config/currencies.php` and `lang/en/currencies.php` after all consumers migrate.
- Update focused Pest coverage and existing localization documentation.

### Task 1: Add maintained currency reference data

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `app/Services/CurrencyCatalog.php`
- Create: `config/currency.php`
- Create: `tests/Feature/CurrencyCatalogTest.php`

- [ ] **Step 1: Install Symfony Intl**

Run:

```bash
composer require symfony/intl --no-interaction
```

Expected: Composer selects the Symfony Intl version compatible with PHP 8.5 and the existing Symfony 8 packages, updates the lock file, and completes package discovery successfully.

- [ ] **Step 2: Generate the application service and test**

Run:

```bash
php artisan make:class Services/CurrencyCatalog --no-interaction
php artisan make:test --pest CurrencyCatalogTest --no-interaction
```

Expected: `app/Services/CurrencyCatalog.php` and `tests/Feature/CurrencyCatalogTest.php` exist.

- [ ] **Step 3: Write the failing currency catalogue tests**

Replace `tests/Feature/CurrencyCatalogTest.php` with:

```php
<?php

use App\Services\CurrencyCatalog;

it('provides localized names from maintained ICU data', function () {
    $catalog = app(CurrencyCatalog::class);

    expect($catalog->name('USD', 'fr'))->toBe('dollar des États-Unis')
        ->and($catalog->name('EUR', 'nl'))->toBe('euro')
        ->and($catalog->symbol('EUR', 'de'))->toBe('€')
        ->and($catalog->fractionDigits('JPY'))->toBe(0);
});

it('offers current legal tender while retaining historical display fallbacks', function () {
    $catalog = app(CurrencyCatalog::class);

    expect($catalog->selectableCodes())
        ->toContain('EUR', 'USD', 'GBP')
        ->not->toContain('HRK')
        ->and($catalog->name('HRK', 'en'))->toBe('Croatian Kuna')
        ->and($catalog->name('ZZZ', 'en'))->toBe('ZZZ');
});

it('can include a stored historical currency in localized choices', function () {
    $options = app(CurrencyCatalog::class)->options('de', ['HRK']);

    expect($options)->toHaveKey('HRK')
        ->and($options['HRK'])->toBe('Kroatische Kuna')
        ->and(array_keys($options))->not->toContain('ZWL');
});
```

- [ ] **Step 4: Run the test and confirm the missing behavior**

Run:

```bash
php artisan test --compact tests/Feature/CurrencyCatalogTest.php
```

Expected: FAIL because `CurrencyCatalog` has no implemented API.

- [ ] **Step 5: Implement the currency catalogue**

Create `config/currency.php`:

```php
<?php

return [
    'default' => 'EUR',
];
```

Implement `app/Services/CurrencyCatalog.php`:

```php
<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Intl\Currencies;

class CurrencyCatalog
{
    /** @var list<string>|null */
    private ?array $selectableCodes = null;

    /**
     * @return list<string>
     */
    public function selectableCodes(): array
    {
        if ($this->selectableCodes !== null) {
            return $this->selectableCodes;
        }

        $codes = [];

        foreach (array_keys(Currencies::getNames('en')) as $code) {
            try {
                if (Currencies::isValidInAnyCountry($code, legalTender: true, active: true)) {
                    $codes[] = $code;
                }
            } catch (InvalidArgumentException|RuntimeException) {
                continue;
            }
        }

        sort($codes);

        return $this->selectableCodes = $codes;
    }

    /**
     * @param  list<string>  $includeCodes
     * @return array<string, string>
     */
    public function options(?string $locale = null, array $includeCodes = []): array
    {
        $codes = array_values(array_unique([
            ...$this->selectableCodes(),
            ...array_map(fn (string $code): string => strtoupper($code), $includeCodes),
        ]));

        return collect($codes)
            ->mapWithKeys(fn (string $code): array => [
                $code => $this->name($code, $locale),
            ])
            ->sort()
            ->all();
    }

    public function name(string $code, ?string $locale = null): string
    {
        $code = strtoupper($code);

        try {
            return Currencies::getName($code, $locale ?? app()->getLocale());
        } catch (InvalidArgumentException|RuntimeException) {
            return $code;
        }
    }

    public function symbol(string $code, ?string $locale = null): string
    {
        $code = strtoupper($code);

        try {
            return Currencies::getSymbol($code, $locale ?? app()->getLocale());
        } catch (InvalidArgumentException|RuntimeException) {
            return $code;
        }
    }

    public function fractionDigits(string $code): int
    {
        try {
            return Currencies::getFractionDigits(strtoupper($code));
        } catch (InvalidArgumentException|RuntimeException) {
            return 2;
        }
    }

    public function isSelectable(string $code): bool
    {
        return in_array(strtoupper($code), $this->selectableCodes(), true);
    }
}
```

- [ ] **Step 6: Run the focused test**

Run:

```bash
php artisan test --compact tests/Feature/CurrencyCatalogTest.php
```

Expected: PASS. If ICU uses a capitalization variant, assert the exact value returned by the installed dataset without replacing contextual names with hand-maintained translations.

- [ ] **Step 7: Commit the currency source**

```bash
git add composer.json composer.lock app/Services/CurrencyCatalog.php config/currency.php tests/Feature/CurrencyCatalogTest.php
git commit -m "feat: use maintained currency reference data"
```

### Task 2: Replace the manual currency catalogue throughout the app

**Files:**
- Modify: `app/Models/User.php`
- Modify: `app/Livewire/Dashboard/SettingsIndex.php`
- Modify: `app/Services/RecipeWorkbenchViewDataBuilder.php`
- Modify: `app/Services/RecipeVersionCostingSynchronizer.php`
- Modify: `resources/views/livewire/dashboard/settings-index.blade.php`
- Modify: `tests/Feature/RecipeWorkbenchViewDataBuilderTest.php`
- Modify: `tests/Feature/SettingsSecurityTest.php`
- Delete: `config/currencies.php`
- Delete: `lang/en/currencies.php`

- [ ] **Step 1: Add failing integration assertions**

Add to `tests/Feature/RecipeWorkbenchViewDataBuilderTest.php`:

```php
it('uses localized maintained currency choices and preserves the stored currency', function () {
    app()->setLocale('fr');

    $productFamily = ProductFamily::factory()->create(['slug' => 'soap']);
    $user = User::factory()->create();
    $user->company()->update(['default_currency' => 'HRK']);

    mock(RecipeWorkbenchService::class, function ($mock): void {
        $mock->shouldReceive('currentVersionPayloadUsingCatalog')->andReturn(null);
        $mock->shouldReceive('packagingCatalogPayload')->andReturn([]);
        $mock->shouldReceive('phaseBlueprints')->andReturn([]);
    });
    mock(RecipeWorkbenchIngredientCatalogBuilder::class, fn ($mock) => $mock->shouldReceive('build')->andReturn([]));
    mock(RecipeWorkbenchIfraOptionsBuilder::class, function ($mock): void {
        $mock->shouldReceive('categories')->andReturn([]);
        $mock->shouldReceive('defaultCategoryId')->andReturn(null);
    });

    $payload = app(RecipeWorkbenchViewDataBuilder::class)->build($productFamily, null, $user);

    expect($payload['currencies']['USD'])->toBe('dollar des États-Unis')
        ->and($payload['currencies'])->toHaveKey('HRK')
        ->and($payload['currencies'])->not->toHaveKey('ZWL');
});
```

Add a `SettingsSecurityTest` assertion that `ZZZ` fails `companyCurrency` validation and `EUR` succeeds. Use the existing authenticated workspace fixture and Livewire test style in that file.

- [ ] **Step 2: Run the integration tests and confirm failure**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/SettingsSecurityTest.php
```

Expected: FAIL because consumers still read `config('currencies')` and `__('currencies.*')`.

- [ ] **Step 3: Inject and use `CurrencyCatalog`**

Make these exact behavioral changes:

```php
// App\Models\User::defaultCurrency()
return $this->company()?->default_currency ?? config('currency.default', 'EUR');
```

```php
// RecipeWorkbenchViewDataBuilder constructor
private readonly CurrencyCatalog $currencyCatalog,

// build()
'currencies' => $this->currencyCatalog->options(app()->getLocale(), [$defaultCurrency]),
```

Delete the old private `currencyOptions()` method.

Add `CurrencyCatalog` to the `RecipeVersionCostingSynchronizer` constructor and replace `normalizeCurrency()` with:

```php
private function normalizeCurrency(mixed $value): string
{
    $currency = strtoupper(trim((string) $value));

    return $this->currencyCatalog->isSelectable($currency)
        ? $currency
        : config('currency.default', 'EUR');
}
```

In `SettingsIndex`, inject `CurrencyCatalog` through Livewire's `boot()` lifecycle hook:

```php
private CurrencyCatalog $currencyCatalog;

public function boot(CurrencyCatalog $currencyCatalog): void
{
    $this->currencyCatalog = $currencyCatalog;
}
```

Validate with `Rule::in($this->currencyCatalog->selectableCodes())`. Pass search options from `render()`:

```php
public function render(): View
{
    $currencyOptions = collect($this->currencyCatalog->options(
        app()->getLocale(),
        [$this->companyCurrency],
    ))->map(fn (string $name, string $code): array => [
        'id' => $code,
        'label' => "{$code} — {$name}",
        'searchText' => "{$code} {$name}",
    ])->values()->all();

    return view('livewire.dashboard.settings-index', compact('currencyOptions'));
}
```

Update the Blade combobox to use `:options="$currencyOptions"`.

- [ ] **Step 4: Delete the manual catalogue and English currency names**

Delete only these two files after `rg "config\\('currencies|__('currencies" app resources tests` returns no runtime consumers:

```text
config/currencies.php
lang/en/currencies.php
```

- [ ] **Step 5: Run focused integration tests**

Run:

```bash
php artisan test --compact tests/Feature/CurrencyCatalogTest.php tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/SettingsSecurityTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit the integration**

```bash
git add app/Models/User.php app/Livewire/Dashboard/SettingsIndex.php app/Services/RecipeWorkbenchViewDataBuilder.php app/Services/RecipeVersionCostingSynchronizer.php resources/views/livewire/dashboard/settings-index.blade.php tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/SettingsSecurityTest.php config/currencies.php lang/en/currencies.php
git commit -m "refactor: replace manual currency catalogue"
```

### Task 3: Restrict and prune interface translation rows

**Files:**
- Create: `config/interface-translations.php`
- Modify: `app/Services/Translations/EnglishTranslationSource.php`
- Modify: `app/Services/Translations/SyncInterfaceTranslations.php`
- Modify: `app/Console/Commands/SyncInterfaceTranslationsCommand.php`
- Modify: `tests/Feature/InterfaceTranslationFoundationTest.php`

- [ ] **Step 1: Rewrite the source and synchronization tests to express ownership**

Replace currency/homepage expectations with:

```php
$source = app(EnglishTranslationSource::class);

expect($source->get('auth', 'login.heading'))->toBe('Sign in to your workspace')
    ->and($source->get('auth', 'failed'))->toBeNull()
    ->and($source->get('validation', 'required'))->toBeNull()
    ->and($source->get('homepage', 'hero.title'))->toBeNull()
    ->and($source->get('currencies', 'EUR'))->toBeNull()
    ->and($source->all())->toHaveKey('public.language.label');
```

Add a pruning test:

```php
it('prunes only rows outside the application-owned source when explicitly requested', function () {
    InterfaceTranslation::query()->create([
        'group' => 'auth',
        'key' => 'login.heading',
        'text' => ['fr' => 'Connexion'],
    ]);
    InterfaceTranslation::query()->create([
        'group' => 'validation',
        'key' => 'required',
        'text' => [],
    ]);
    InterfaceTranslation::query()->create([
        'group' => 'homepage',
        'key' => 'hero.title',
        'text' => [],
    ]);

    $result = app(SyncInterfaceTranslations::class)->handle(prune: true);

    expect($result['pruned'])->toBe(2)
        ->and(InterfaceTranslation::query()->where('group', 'auth')->where('key', 'login.heading')->value('text'))
        ->toBe(['fr' => 'Connexion'])
        ->and(InterfaceTranslation::query()->whereIn('group', ['validation', 'homepage'])->exists())
        ->toBeFalse();
});
```

Update the command test to call `Artisan::call('translations:sync', ['--prune' => true])` and assert output contains `pruned`.

- [ ] **Step 2: Run the foundation test and confirm failure**

Run:

```bash
php artisan test --compact tests/Feature/InterfaceTranslationFoundationTest.php
```

Expected: FAIL because the source still scans every English file and the command has no prune option.

- [ ] **Step 3: Define explicit ownership**

Create `config/interface-translations.php`:

```php
<?php

return [
    'sources' => [
        'navigation' => ['*'],
        'number_formats' => ['*'],
        'public' => ['*'],
        'auth' => [
            'password_requirements',
            'password_optional_reset',
            'login.*',
            'verification.*',
        ],
    ],
];
```

Change `EnglishTranslationSource::all()` to iterate this manifest, load only `lang/en/{group}.php`, flatten it with `Arr::dot()`, and retain keys matching `Str::is($patterns, $key)`. Missing files return no keys rather than loading unrelated language files.

- [ ] **Step 4: Implement explicit pruning**

Change the service signature and result shape:

```php
/** @return array{created: int, existing: int, pruned: int} */
public function handle(bool $prune = false): array
```

After additive synchronization, compute the owned full keys and delete non-owned rows only when `$prune` is true. Count every deleted row in `pruned`.

Change the command signature and invocation:

```php
#[Signature('translations:sync {--prune : Delete database rows that are not application-owned English keys}')]
```

```php
$result = $this->synchronizer->handle(prune: (bool) $this->option('prune'));
```

Report created, existing, and pruned counts.

- [ ] **Step 5: Run and verify the foundation test**

Run:

```bash
php artisan test --compact tests/Feature/InterfaceTranslationFoundationTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit translation ownership**

```bash
git add config/interface-translations.php app/Services/Translations/EnglishTranslationSource.php app/Services/Translations/SyncInterfaceTranslations.php app/Console/Commands/SyncInterfaceTranslationsCommand.php tests/Feature/InterfaceTranslationFoundationTest.php
git commit -m "refactor: limit interface translation ownership"
```

### Task 4: Register Dutch and its number format

**Files:**
- Modify: `database/seeders/SupportedLocaleSeeder.php`
- Modify: `config/number-formats.php`
- Modify: `lang/en/number_formats.php`
- Modify: `app/Support/NumberLocale.php`
- Create: `lang/nl.json`
- Create: `lang/nl/actions.php`
- Create: `lang/nl/auth.php`
- Create: `lang/nl/http-statuses.php`
- Create: `lang/nl/pagination.php`
- Create: `lang/nl/passwords.php`
- Create: `lang/nl/validation.php`
- Modify: `tests/Feature/InterfaceTranslationFoundationTest.php`
- Modify: `tests/Feature/NumberFormatPreferenceTest.php`

- [ ] **Step 1: Add failing Dutch registry and number-format assertions**

Expect seeded codes to equal:

```php
['en', 'fr', 'es', 'de', 'it', 'nl']
```

Assert the Dutch record contains `Nederlands`, `nl_NL`, `ltr`, sort order `60`, and remains inactive. Add number-format assertions:

```php
expect(NumberLocale::isSupported('nl_NL'))->toBeTrue()
    ->and(NumberLocale::formatDecimal(1234.56, 2, 'nl_NL'))->toBe('1234,56');
```

- [ ] **Step 2: Run tests and confirm failure**

Run:

```bash
php artisan test --compact tests/Feature/InterfaceTranslationFoundationTest.php tests/Feature/NumberFormatPreferenceTest.php
```

Expected: FAIL because Dutch is not registered.

- [ ] **Step 3: Publish Laravel Lang Dutch files**

Run:

```bash
php artisan lang:add nl --no-interaction
```

Expected: the Dutch Laravel Lang files are created under `lang/nl` and `lang/nl.json`.

- [ ] **Step 4: Add the Dutch locale and number format**

Add to `SupportedLocaleSeeder`:

```php
[
    'code' => 'nl',
    'name' => 'Dutch',
    'native_name' => 'Nederlands',
    'number_locale' => 'nl_NL',
    'text_direction' => 'ltr',
    'is_active' => false,
    'is_default' => false,
    'sort_order' => 60,
],
```

Add `nl_NL` to `config/number-formats.php`, add `Dutch · 1.234,56` to `lang/en/number_formats.php`, and include `nl_NL` in `NumberLocale::usesDecimalComma()`.

- [ ] **Step 5: Run focused tests**

Run:

```bash
php artisan test --compact tests/Feature/InterfaceTranslationFoundationTest.php tests/Feature/NumberFormatPreferenceTest.php tests/Feature/AuthFlowTest.php
```

Expected: PASS, with the existing authentication translations unaffected and Dutch framework files available for later activation.

- [ ] **Step 6: Commit Dutch support**

```bash
git add database/seeders/SupportedLocaleSeeder.php config/number-formats.php lang/en/number_formats.php app/Support/NumberLocale.php lang/nl.json lang/nl tests/Feature/InterfaceTranslationFoundationTest.php tests/Feature/NumberFormatPreferenceTest.php
git commit -m "feat: register Dutch localization"
```

### Task 5: Translate the authenticated side menu in context

**Files:**
- Create: `lang/en/navigation.php`
- Modify: `resources/views/layouts/app-shell.blade.php`
- Create: `tests/Feature/DashboardSidebarLocalizationTest.php`
- Modify: `tests/Feature/DashboardPageTest.php`
- Modify: `tests/Feature/DashboardSidebarTest.php`

- [ ] **Step 1: Generate and write the failing menu localization test**

Run:

```bash
php artisan make:test --pest DashboardSidebarLocalizationTest --no-interaction
```

Create rows for `navigation.items.overview`, `navigation.items.formulas`, `navigation.items.ingredients`, `navigation.items.packaging`, `navigation.items.compliance`, `navigation.items.account`, `navigation.items.settings`, and `navigation.actions.sign_out`. Test each locale through an authenticated dashboard request using this dataset:

```php
])->with([
    'French' => ['fr', ['Aperçu', 'Formules', 'Ingrédients', 'Emballages', 'Conformité', 'Compte', 'Paramètres', 'Se déconnecter']],
    'Spanish' => ['es', ['Resumen', 'Fórmulas', 'Ingredientes', 'Envases', 'Cumplimiento', 'Cuenta', 'Ajustes', 'Cerrar sesión']],
    'German' => ['de', ['Übersicht', 'Rezepturen', 'Inhaltsstoffe', 'Verpackungen', 'Konformität', 'Konto', 'Einstellungen', 'Abmelden']],
    'Italian' => ['it', ['Panoramica', 'Formule', 'Ingredienti', 'Imballaggi', 'Conformità', 'Account', 'Impostazioni', 'Esci']],
    'Dutch' => ['nl', ['Overzicht', 'Formules', 'Ingrediënten', 'Verpakkingen', 'Regelgeving', 'Account', 'Instellingen', 'Uitloggen']],
]);
```

Also assert that `Admin` remains visible in English according to the existing shell behavior.

- [ ] **Step 2: Run the test and confirm hard-coded English failure**

Run:

```bash
php artisan test --compact tests/Feature/DashboardSidebarLocalizationTest.php tests/Feature/DashboardPageTest.php tests/Feature/DashboardSidebarTest.php
```

Expected: FAIL because the shell is hard-coded.

- [ ] **Step 3: Add canonical English navigation copy**

Create `lang/en/navigation.php`:

```php
<?php

return [
    'items' => [
        'overview' => 'Overview',
        'formulas' => 'Formulas',
        'ingredients' => 'Ingredients',
        'packaging' => 'Packaging',
        'compliance' => 'Compliance',
        'account' => 'Account',
        'settings' => 'Settings',
        'home' => 'Home',
    ],
    'menu' => [
        'close' => 'Close menu',
        'collapse' => 'Collapse menu',
        'toggle' => 'Show or hide menu',
    ],
    'user' => [
        'aria_label' => 'Signed-in user',
        'signed_in' => 'Signed in',
        'free_account' => 'Free account',
    ],
    'actions' => [
        'sign_out' => 'Sign out',
    ],
    'status' => [
        'coming_soon' => 'Coming soon',
    ],
];
```

- [ ] **Step 4: Replace every customer-facing shell string with its complete key**

Use `__('navigation.items.overview')` and the corresponding keys for links, screen-reader labels, title text, signed-in metadata, sign-out, the default page heading, and the Home link. Leave both visible `Admin` strings literal and English-only.

Update existing dashboard assertions from the old navigation wording (`Dashboard`, `Recipes`, `Packaging Items`) to the approved English wording only where the assertion refers to the side menu.

- [ ] **Step 5: Run English and contextual locale tests**

Run:

```bash
php artisan test --compact tests/Feature/DashboardSidebarLocalizationTest.php tests/Feature/DashboardPageTest.php tests/Feature/DashboardSidebarTest.php tests/Feature/PackagingItemsIndexTest.php
```

Expected: PASS.

- [ ] **Step 6: Synchronize, prune, and insert local contextual drafts**

Run:

```bash
php artisan db:seed --class=Database\\Seeders\\SupportedLocaleSeeder --no-interaction
php artisan translations:sync --prune
```

Then merge the complete contextual draft set into the local database:

```bash
php artisan tinker --execute '$drafts = [
    "items.overview" => ["fr" => "Aperçu", "es" => "Resumen", "de" => "Übersicht", "it" => "Panoramica", "nl" => "Overzicht"],
    "items.formulas" => ["fr" => "Formules", "es" => "Fórmulas", "de" => "Rezepturen", "it" => "Formule", "nl" => "Formules"],
    "items.ingredients" => ["fr" => "Ingrédients", "es" => "Ingredientes", "de" => "Inhaltsstoffe", "it" => "Ingredienti", "nl" => "Ingrediënten"],
    "items.packaging" => ["fr" => "Emballages", "es" => "Envases", "de" => "Verpackungen", "it" => "Imballaggi", "nl" => "Verpakkingen"],
    "items.compliance" => ["fr" => "Conformité", "es" => "Cumplimiento", "de" => "Konformität", "it" => "Conformità", "nl" => "Regelgeving"],
    "items.account" => ["fr" => "Compte", "es" => "Cuenta", "de" => "Konto", "it" => "Account", "nl" => "Account"],
    "items.settings" => ["fr" => "Paramètres", "es" => "Ajustes", "de" => "Einstellungen", "it" => "Impostazioni", "nl" => "Instellingen"],
    "items.home" => ["fr" => "Accueil", "es" => "Inicio", "de" => "Startseite", "it" => "Home", "nl" => "Start"],
    "menu.close" => ["fr" => "Fermer le menu", "es" => "Cerrar el menú", "de" => "Menü schließen", "it" => "Chiudi il menu", "nl" => "Menu sluiten"],
    "menu.collapse" => ["fr" => "Réduire le menu", "es" => "Contraer el menú", "de" => "Menü einklappen", "it" => "Riduci il menu", "nl" => "Menu inklappen"],
    "menu.toggle" => ["fr" => "Afficher ou masquer le menu", "es" => "Mostrar u ocultar el menú", "de" => "Menü ein- oder ausblenden", "it" => "Mostra o nascondi il menu", "nl" => "Menu tonen of verbergen"],
    "user.aria_label" => ["fr" => "Utilisateur connecté", "es" => "Usuario conectado", "de" => "Angemeldeter Benutzer", "it" => "Utente connesso", "nl" => "Ingelogde gebruiker"],
    "user.signed_in" => ["fr" => "Connecté", "es" => "Sesión iniciada", "de" => "Angemeldet", "it" => "Accesso effettuato", "nl" => "Ingelogd"],
    "user.free_account" => ["fr" => "Compte gratuit", "es" => "Cuenta gratuita", "de" => "Kostenloses Konto", "it" => "Account gratuito", "nl" => "Gratis account"],
    "actions.sign_out" => ["fr" => "Se déconnecter", "es" => "Cerrar sesión", "de" => "Abmelden", "it" => "Esci", "nl" => "Uitloggen"],
    "status.coming_soon" => ["fr" => "Bientôt disponible", "es" => "Próximamente", "de" => "Demnächst verfügbar", "it" => "Prossimamente", "nl" => "Binnenkort beschikbaar"],
]; foreach ($drafts as $key => $text) { App\Models\InterfaceTranslation::query()->where("group", "navigation")->where("key", $key)->update(["text" => $text]); }'
```

Activate `nl` only in the local database for the trial:

```bash
php artisan tinker --execute 'App\Models\SupportedLocale::query()->where("code", "nl")->update(["is_active" => true]);'
```

Expected: local `language_lines` contains only application-owned groups, `navigation` rows contain all five locale values, currency/homepage/framework rows are gone, and Dutch appears in the local selector. No production database is touched.

- [ ] **Step 7: Commit menu localization code and tests**

```bash
git add lang/en/navigation.php resources/views/layouts/app-shell.blade.php tests/Feature/DashboardSidebarLocalizationTest.php tests/Feature/DashboardPageTest.php tests/Feature/DashboardSidebarTest.php
git commit -m "feat: localize authenticated navigation"
```

### Task 6: Update documentation and verify the complete slice

**Files:**
- Modify: `docs/developer/localization.md`
- Modify: `docs/developer/current-state.md`
- Modify: `docs/developer/content-audit.md`

- [ ] **Step 1: Update the localization boundary documentation**

Record these concrete decisions:

- Symfony Intl owns localized currency reference data.
- `language_lines` contains only application-authored interface copy.
- `translations:sync` is additive unless `--prune` is explicitly passed.
- Dutch is registered but remains inactive by default until a complete surface is reviewed.
- `homepage.*` is excluded from Laravel database synchronization because WordPress owns future marketing translation.
- Contextual draft translations are reviewed in the rendered interface before production promotion.

- [ ] **Step 2: Format modified PHP files**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: PASS and any formatting corrections are applied.

- [ ] **Step 3: Run the focused verification suite**

Run:

```bash
php artisan test --compact tests/Feature/CurrencyCatalogTest.php tests/Feature/InterfaceTranslationFoundationTest.php tests/Feature/NumberFormatPreferenceTest.php tests/Feature/AuthFlowTest.php tests/Feature/DashboardSidebarLocalizationTest.php tests/Feature/DashboardPageTest.php tests/Feature/DashboardSidebarTest.php tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/SettingsSecurityTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/PackagingItemsIndexTest.php
```

Expected: all tests pass.

- [ ] **Step 4: Verify dependency and synchronization state**

Run:

```bash
composer show symfony/intl --no-ansi
php artisan translations:sync --prune
php artisan test --compact tests/Feature/InterfaceTranslationFoundationTest.php
```

Expected: Symfony Intl is installed; sync reports zero unexpected creations on its second run; the foundation test passes.

- [ ] **Step 5: Refresh the graph and inspect the working tree**

Run:

```bash
graphify update .
git diff --check
git status --short
```

Expected: graph update succeeds, no whitespace errors exist, and unrelated pre-existing changes remain unstaged and untouched.

- [ ] **Step 6: Commit documentation separately**

```bash
git add docs/developer/localization.md docs/developer/current-state.md docs/developer/content-audit.md
git commit -m "docs: record interface translation ownership"
```
