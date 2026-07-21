# Localization

Last updated: 2026-07-21

## Scope

Koskalk uses two distinct translation domains:

1. Interface translations for Soapkraft-authored labels, messages, validation guidance, and other UI copy.
2. Platform-data translations for managed catalog and compliance records such as ingredient display names and regulatory descriptions.

Do not store platform catalog content in the interface translation table. Spatie's translation loader is the loading and storage engine for interface strings only.

The Filament admin remains English-only. It is the trusted editing interface for managing public application languages and translations.

## Interface translation foundation

The implemented interface layer uses Laravel localization with `spatie/laravel-translation-loader`.

- English source text remains version-controlled in `lang/en`.
- Non-English application strings live only in `language_lines`; there are no parallel application-owned locale files or translation-value seeders.
- `App\Services\Translations\EnglishTranslationSource` reads only the application-owned groups and key patterns declared in `config/interface-translations.php`.
- `App\Services\Translations\SyncInterfaceTranslations` inserts missing translation keys into `language_lines` without overwriting translations.
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

Run these commands during deployment when the related changes are present:

```shell
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\SupportedLocaleSeeder --force
php artisan translations:sync
```

`translations:sync` creates missing application-owned rows with empty translation maps. It never machine-translates text and never overwrites an existing translation. The command is additive by default. Pass `--prune` only when rows outside the ownership manifest should be removed, such as after retiring a group or cleaning an older database.

`SupportedLocaleSeeder` registers locale metadata only. `DatabaseSeeder` does not insert non-English interface copy. Reviewed translations are database content: editing a locale value in Filament takes effect without a deployment, and later deployments must preserve it.

The translation sequence for a new or changed key is:

1. Approve the English wording and add it to the owned `lang/en` group.
2. Deploy the English source and run `translations:sync` to create any missing database rows.
3. Draft each missing locale from the English source, the complete key, nearby strings, and the task context.
4. Save the draft in `language_lines`, review it in the rendered interface, and revise it there without changing source files.
5. Activate a locale only after its required interface and platform content is complete.

Automatic drafting is a separate operation from synchronization. It must fill only blank locale values, preserve reviewed database text, preserve placeholders, and provide enough task context to avoid literal word-for-word translation. A translation-provider integration must not be hidden inside deployment seeding.

Adding another locale means adding or managing its `supported_locales` record, synchronizing keys, completing the translation review, and only then activating it. Do not seed guessed translations.

Dutch framework files and the `nl_NL` number format are present, but Dutch remains inactive by default. A developer may activate it locally while reviewing a completed surface; that local trial does not change the seeded production state.

## Currency reference data

`symfony/intl` owns ISO currency names, symbols, fraction digits, and the current legal-tender list. `App\Services\CurrencyCatalog` is the application boundary around that data. Stored historical codes remain displayable, but users can select only current currencies.

Currency names are localized from Symfony's maintained ICU data. They do not belong in `language_lines`, and Soapkraft does not maintain a separate list of 156 translated currency names.

## Platform-data boundary

Platform data needs a separate translation model because it has different identity, lifecycle, and compliance requirements.

## Public and marketing pages

`homepage.*` is excluded from Laravel database synchronization. The public marketing site and long-form documentation will move to WordPress later; that work is outside the current application-localization slice. When it starts, WordPress must first reproduce the current Laravel homepage, then the project must explicitly decide whether it replaces `/` or launches separately.

WordPress can own marketing and long-form end-user documentation. The application should retain concise task-focused interface copy and link to the relevant documentation only when deeper explanation is useful.

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
