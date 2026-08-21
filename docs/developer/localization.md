# Localization

Last updated: 2026-08-20

## Scope

Koskalk uses two distinct translation domains:

1. Interface translations for Soapkraft-authored labels, messages, validation guidance, and other UI copy.
2. Platform-data translations for managed catalog and compliance records such as ingredient display names and regulatory descriptions.

Do not store platform catalog content in the interface translation table. Spatie's translation loader is the loading and storage engine for interface strings only.

The Filament admin remains English-only. It is the trusted editing interface for managing public application languages and translations.

## Interface translation foundation

The implemented interface layer uses Laravel localization with `spatie/laravel-translation-loader`.

- English source text remains version-controlled in `lang/en`.
- Non-English application strings are loaded at runtime from `language_lines`; there are no parallel application-owned locale files or translation-value seeders.
- `App\Services\Translations\EnglishTranslationSource` reads only the application-owned groups and key patterns declared in `config/interface-translations.php`.
- `App\Services\Translations\SyncInterfaceTranslations` inserts missing translation keys into `language_lines` without overwriting translations.
- `database/seeders/data/interface-translations.json` is the deterministic, version-controlled recovery catalogue for reviewed non-English values.
- `translations:catalogue:export` writes the current application-owned `language_lines` values to that catalogue without IDs or timestamps.
- `translations:catalogue:import` validates the complete catalogue before writing and requires an explicit conflict mode.
- `App\Models\InterfaceTranslation` is the application model for Spatie language lines.
- `supported_locales` controls which locales exist, their number-format locale, text direction, activation state, and display order.
- The Filament `Languages` and `Interface Translations` resources provide the trusted editing workflow.
- Translation placeholders are validated so translated strings cannot silently lose required parameters.

Laravel's fallback locale remains English. An absent translation therefore renders the English source instead of a broken key.

`language_lines` is not a mirror of every Laravel language file. Framework authentication errors, validator messages, pagination, password-reset messages, currency names, and homepage marketing blocks stay outside the interface editor. Laravel Lang supplies framework translations; Symfony Intl supplies currency reference data; future WordPress content will own marketing and long-form documentation.

## Editorial readiness before translation

Translation begins only after the English source is editorially approved. Existing user-facing copy is not assumed to be ready merely because it is visible in the application.

Before extracting a surface into translation keys, review its labels, headings, helper text, warnings, empty states, status messages, and accessible labels. Rewrite or remove text that is sales-like, repetitive, architectural, vague, or chemically inaccurate. Prefer natural task language that helps a maker act in the current workflow.

For the soap bench, the current terminology direction is:

- Use `Saponification` for the oil-and-lye stage, not `Core reaction`.
- Use `Formula additions` for the subsequent additive/fragrance stage, not `Post-reaction phases`. In cold-process soap, saponification can still be underway when these ingredients are added.

Do not mechanically translate existing hard-coded text and then revise it later in every locale. The required order is: approve English, establish terminology, extract stable keys, then translate and review each locale.

## Seed and synchronization behavior

The locale seeder currently registers:

- `en`: active and default
- `fr`, `es`, `de`, `it`, `nl`: registered but inactive

Registering a locale is not the same as translating or publishing it. The application may eventually support ten or more languages, but languages should be activated only after their required interface and platform content has been reviewed.

During the current owner-only pre-launch phase, deploy with the repository catalogue as the authoritative source:

```shell
php artisan migrate --force
php artisan db:seed --class='Database\Seeders\SupportedLocaleSeeder' --force
php artisan translations:sync
php artisan translations:catalogue:import --mode=authoritative --force --no-interaction
```

`translations:sync` creates missing application-owned rows with empty translation maps. It never machine-translates text and never overwrites an existing translation. The command is additive by default. Pass `--prune` only when rows outside the ownership manifest should be removed, such as after retiring a group or cleaning an older database.

Do not use `translations:sync --prune` in deployment, and do not run the complete `DatabaseSeeder` to deploy translations. `SupportedLocaleSeeder` registers locale metadata only. The explicit catalogue import is scoped to application-owned interface keys in `language_lines`; it does not write to users, products, ingredients, media, or other tables, and it never deletes a database row because that row is absent from the catalogue.

The import conflict mode is mandatory:

- `--mode=authoritative` replaces the complete non-English locale map for each matching catalogue row. It can create missing rows and update existing rows. In production it additionally requires `--force`. Use this mode only while the committed catalogue is authoritative.
- `--mode=preserve-existing` creates missing rows and fills only locale values that are absent or blank. It preserves every non-blank database value. Use this mode after production editorial changes become authoritative.

Both modes validate the format, registered locales, application ownership, duplicates, value types, deterministic ordering, and English placeholders before opening the write transaction. Neither mode imports English values; canonical English remains in `lang/en`.

The development and review sequence for a new or changed key is:

1. Approve the English wording and add it to the owned `lang/en` group.
2. Run `translations:sync` locally to create any missing database rows.
3. Draft each missing locale from the English source, the complete key, nearby strings, and the task context. Codex can do this directly during the review task; no OpenAI API key or runtime translation integration is required.
4. Save only blank values in the local `language_lines` table, review them in the rendered interface, and revise them there without changing source files.
5. Export the reviewed local state:

   ```shell
   php artisan translations:catalogue:export
   ```

6. Review and commit `database/seeders/data/interface-translations.json` with the related English keys.
7. Deploy the English source, synchronize missing keys, and import the catalogue with the phase-appropriate explicit mode.
8. Activate a locale only after its required interface and platform content is complete.

The export is deterministic: rows are sorted by group and key, locale maps are sorted, Unicode and line breaks remain readable, placeholders are preserved, and database IDs and timestamps are excluded. A repeated export with unchanged values must produce no Git diff.

Automatic drafting is a separate operation from synchronization. It must fill only blank locale values, preserve reviewed database text, preserve placeholders, and provide enough task context to avoid literal word-for-word translation. A translation-provider integration must not be hidden inside deployment seeding.

The local and production databases remain independent content stores. The catalogue is the recovery and promotion boundary between them. During pre-launch, local review followed by catalogue export is authoritative and every deployment uses `--mode=authoritative --force`. When production Filament edits become authoritative, first export or otherwise reconcile the final production state, then permanently change the deployment import to:

```shell
php artisan translations:catalogue:import --mode=preserve-existing --no-interaction
```

Do not keep authoritative replacement in the deployment script after production editors begin making non-blank changes that are not first reconciled into Git.

## Fresh database recovery

A fresh database can recover interface translations without running the complete application seeder:

```shell
php artisan migrate
php artisan db:seed --class='Database\Seeders\SupportedLocaleSeeder'
php artisan translations:sync
php artisan translations:catalogue:import --mode=authoritative --no-interaction
```

This recreates application-owned rows from English keys and then applies the reviewed non-English catalogue. Rows intentionally absent from the catalogue are not deleted. English continues to load from `lang/en`.

Adding another locale means adding or managing its `supported_locales` record, synchronizing keys, completing the translation review, and only then activating it. Do not seed guessed translations.

Dutch framework files and the `nl_NL` number format are present, but Dutch remains inactive by default. A developer may activate it locally while reviewing a completed surface; that local trial does not change the seeded production state.

## Currency reference data

`symfony/intl` owns ISO currency names, symbols, fraction digits, and the current legal-tender list. `App\Services\CurrencyCatalog` is the application boundary around that data. Stored historical codes remain displayable, but users can select only current currencies.

Currency names are localized from Symfony's maintained ICU data. They do not belong in `language_lines`, and Soapkraft does not maintain a separate list of 156 translated currency names.

## Platform-data boundary

Platform data needs a separate translation model because it has different identity, lifecycle, and compliance requirements.

## Public and marketing pages

`homepage.*` is excluded from Laravel database synchronization. WordPress owns the public `soapkraft.com` site and long-form documentation, while Laravel remains the application at `app.soapkraft.com`. The WordPress domain is configured and its homepage design is complete, but the design has not yet been implemented in WordPress. Until it is, the current Laravel homepage remains the implementation reference.

WordPress owns marketing and long-form end-user documentation. The application retains concise task-focused interface copy and links to the relevant documentation only when deeper explanation is useful.

The content hierarchy is: concise interface copy first, contextual help when the current task needs a short explanation, and WordPress documentation for complete methods, examples, and editorial material. Contextual translations are reviewed in the rendered interface before production promotion; source-level correctness alone is not sufficient.

### Canonical data that is not translated

Keep these values once on their canonical records:

- stable IDs, codes, and slugs
- CAS, EC, and other chemical identifiers
- SAP values, fatty-acid profiles, percentages, limits, and calculation constants
- dates, source references, and regulatory rule logic
- canonical INCI or other controlled nomenclature identifiers

Numbers are stored as numbers. Locale affects parsing and display formatting, not stored values.

### Localized platform content

Translate human-facing editorial fields separately:

- ingredient common or display names
- descriptions, functions, instructions, and helper content
- product type and category labels
- compliance regime labels, summaries, and explanatory warnings

The initial resolution order should be requested locale, then English source value. User-entered private data should remain as authored unless a separate user translation feature is deliberately added later.

### Regulatory names are not ordinary translations

Do not assume that an INCI label can simply be translated by locale. Different markets may require a specific official name or local script. For example, an ingredient may need canonical INCI, English, and Chinese inventory names at the same time.

Model those names as sourced regulatory nomenclature tied to the ingredient or substance, regulatory regime or naming system, locale/script, source version, and effective dates. They should not overwrite the canonical INCI field and should not live in `language_lines`.

## Implemented ingredient translation architecture

Platform ingredient translations use the dedicated `ingredient_translations` table. English remains canonical in `ingredients.display_name` and `ingredients.info_markdown`; each non-English row belongs to one platform ingredient and one registered locale.

`Ingredient::localizedDisplayName()` and `Ingredient::localizedInfoMarkdown()` resolve the requested locale and then fall back to canonical English. Private user-owned ingredients always remain as authored. Workbench and catalog queries eager-load only the current locale candidates so translation resolution does not introduce N+1 queries.

Translations are edited in the English-only Filament ingredient editor. The native `Translations` section lists registered non-English locales and does not require `spatie/laravel-translatable` or an additional Filament translation plugin.

The first platform catalog population happens only after the canonical ingredient database has been reviewed. That initial import should insert reviewed rows into `ingredient_translations` for the approved platform ingredients. Later platform ingredients created in production receive their translations manually in the production Filament editor. Private user-owned ingredients are never bulk-translated.

The implemented first slice translates only ingredient display names and guidance. Other platform models should be inventoried before their own typed translation tables are introduced. A bulk missing-translation dashboard and per-row editorial workflow remain deferred until catalog volume requires them.

Dedicated regulatory-name records are still required for compliance-sensitive official nomenclature tied to a regime or naming system and carrying source metadata.

The first workbench content inventory and the proposed split between microcopy, contextual help, documentation, and platform data are recorded in [content-audit.md](./content-audit.md).

## Agent guardrails

- Start translation work with an English content and terminology review; do not translate unreviewed source copy.
- Keep English interface source strings in code and synchronize their keys into the database.
- Never place ingredient, product type, compliance, or other platform records in `language_lines`.
- Keep canonical English ingredient values on `ingredients` and non-English editorial values in `ingredient_translations`.
- Never translate scientific values, identifiers, or calculation constants.
- Treat official market nomenclature as versioned regulatory data, not casual UI translation.
- Keep Filament admin labels and navigation English-only unless this decision is explicitly changed.
- Preserve English fallback and do not activate incomplete locales.
