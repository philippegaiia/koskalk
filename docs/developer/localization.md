# Localization

Last updated: 2026-07-11

## Scope

Koskalk uses two distinct translation domains:

1. Interface translations for application labels, messages, validation text, and other UI copy.
2. Platform-data translations for managed catalog and compliance records such as ingredient display names and regulatory descriptions.

Do not store platform catalog content in the interface translation table. Spatie's translation loader is the loading and storage engine for interface strings only.

The Filament admin remains English-only. It is the trusted editing interface for managing public application languages and translations.

## Interface translation foundation

The implemented interface layer uses Laravel localization with `spatie/laravel-translation-loader`.

- English source text remains version-controlled in `lang/en` and other Laravel translation files.
- `App\Services\Translations\EnglishTranslationSource` reads the English source of truth.
- `App\Services\Translations\SyncInterfaceTranslations` inserts missing translation keys into `language_lines` without overwriting translations.
- `App\Models\InterfaceTranslation` is the application model for Spatie language lines.
- `supported_locales` controls which locales exist, their number-format locale, text direction, activation state, and display order.
- The Filament `Languages` and `Interface Translations` resources provide the trusted editing workflow.
- Translation placeholders are validated so translated strings cannot silently lose required parameters.

Laravel's fallback locale remains English. An absent translation therefore renders the English source instead of a broken key.

## Seed and synchronization behavior

The locale seeder currently registers:

- `en`: active and default
- `fr`, `es`, `de`, `it`: registered but inactive

Registering a locale is not the same as translating or publishing it. The application may eventually support ten or more languages, but languages should be activated only after their required interface and platform content has been reviewed.

Run these commands during deployment when the related changes are present:

```shell
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\SupportedLocaleSeeder --force
php artisan translations:sync
```

`translations:sync` creates missing key rows with empty translation maps. It never machine-translates text and never overwrites an existing translation. Run it after adding or renaming English translation keys.

Adding another locale means adding or managing its `supported_locales` record, synchronizing keys, completing the translation review, and only then activating it. Do not seed guessed translations.

## Platform-data boundary

Platform data needs a separate translation model because it has different identity, lifecycle, and compliance requirements.

## Public and marketing pages

Public pages such as the homepage keep their layout and English source copy in version-controlled Blade and Laravel language files. Homepage content is translated by complete semantic blocks, such as a heading, paragraph, button label, benefit, workflow step, or SEO field, using stable Spatie interface translation keys.

English homepage changes are expected to go through the normal code review and deployment process. This is intentional: marketing copy changes often accompany product positioning, claims, links, or layout changes that should remain traceable in the repository. Non-English values can be reviewed and updated through the English-only Filament translation editor.

Do not introduce a page builder, WordPress, Ghost, or a separate marketing CMS unless publishing volume and editorial staffing create a demonstrated need.

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

- Keep English interface source strings in code and synchronize their keys into the database.
- Never place ingredient, product type, compliance, or other platform records in `language_lines`.
- Keep canonical English ingredient values on `ingredients` and non-English editorial values in `ingredient_translations`.
- Never translate scientific values, identifiers, or calculation constants.
- Treat official market nomenclature as versioned regulatory data, not casual UI translation.
- Keep Filament admin labels and navigation English-only unless this decision is explicitly changed.
- Preserve English fallback and do not activate incomplete locales.
