# Platform Ingredient Translations Design

**Date:** 2026-07-11

## Goal

Allow platform-managed ingredient names and guidance to appear in the user's interface language without mixing catalog content with Laravel interface language lines or altering scientific and regulatory data.

## Decisions

- English remains the canonical editorial source in `ingredients.display_name` and `ingredients.info_markdown`.
- Non-English platform content lives in a dedicated `ingredient_translations` table.
- `spatie/laravel-translation-loader` remains responsible only for interface strings stored in `language_lines`.
- Private user-owned ingredients remain exactly as authored and do not participate in platform translation management.
- Missing or empty translations fall back to the canonical English ingredient value.
- A locale can be registered before its catalog translations are complete, but it should not be activated for users until interface and platform content have been reviewed.

## Data Model

Create `ingredient_translations` with:

- `id`
- `ingredient_id`, constrained to `ingredients` with cascade delete
- `locale`, constrained to the unique `supported_locales.code`
- nullable `display_name`
- nullable `info_markdown`
- timestamps
- a unique constraint on `ingredient_id` and `locale`
- an index on `locale` and `display_name` for catalog lookup

English does not require a translation row. Locale codes are stable identifiers. The foreign key restricts deletion while translations exist and cascades a deliberate locale-code update. Application validation also rejects English and unsupported locale choices before persistence.

The translation model belongs to an ingredient. The ingredient exposes a `translations()` relationship and explicit localized accessors or methods. Translation resolution must not silently change the existing canonical attributes used by admin workflows, imports, exports, snapshots, or private ingredients.

## Resolution Rules

For a platform ingredient:

1. Resolve the requested interface locale, normally `app()->getLocale()`.
2. If the locale is English, return the canonical ingredient value.
3. If a matching translation row contains a non-empty field, return it.
4. Otherwise return the canonical English field.

For a private user-owned ingredient, always return the authored canonical field regardless of interface locale.

Public workbench/catalog queries must eager-load only the requested locale's translation to avoid N+1 queries. Search should match the requested translated display name, the canonical English display name, and INCI. Sorting should use the translated display name when available and canonical English otherwise.

Saved recipes, versions, costings, and production batches continue storing readable snapshots as they do today. A later translation edit changes current catalog presentation but does not rewrite historical snapshots.

## Filament Workflow

The admin panel remains English-only. Platform translations are edited from the existing ingredient resource in a `Translations` section.

- Only platform ingredients show the translation editor.
- Each registered non-English locale can have at most one row.
- The locale selector uses `supported_locales` and shows inactive locales so content can be prepared before launch.
- English source values remain visible near the translation inputs for reference.
- Display name and guidance can be saved independently; an empty value uses English fallback.
- Native Filament components are used instead of an additional community translation plugin.

The initial scope optimizes editing one ingredient at a time. A bulk missing-translation dashboard can be added later when catalog translation volume demonstrates the need.

## Translation Boundaries

Initially translatable:

- ingredient display/common name
- ingredient guidance markdown

Canonical and not translated:

- INCI, soap INCI, CAS, and EC identifiers
- source keys, slugs, and supplier references
- SAP values, fatty-acid profiles, percentages, and calculation constants
- regulatory limits, dates, and source references

Official market-specific nomenclature is not an ingredient translation. It will use dedicated sourced regulatory-name records if markets requiring alternative official names or scripts are introduced.

## Validation And Safety

- Translation rows are accepted only for platform ingredients.
- `locale` must be a registered non-English locale.
- `display_name` follows the ingredient name length limit.
- Empty strings are normalized to `null`.
- Duplicate ingredient/locale rows are rejected by validation and the database unique constraint.
- Deleting an ingredient deletes its translations.

## Testing

Feature and model tests will cover:

- migration structure and uniqueness
- translated value resolution and English fallback
- private ingredients remaining as authored
- eager-loaded workbench payload names in the selected locale
- translated and English platform search matching
- Filament creation and editing of translation rows
- rejection of unknown locales, English translation rows, private ingredients, and duplicates
- absence of N+1 translation queries in the catalog builder where practical

## Rollout

The migration creates an empty translation table and does not modify current ingredient records. Existing behavior therefore remains English until translations are added. French, Spanish, German, and Italian rows can then be entered through Filament while those locales remain inactive. Locale activation is a separate release decision.

## Out Of Scope

- machine translation
- translating private user content
- translating canonical INCI or compliance constants
- bulk import/export of translations
- per-row draft, reviewed, and published workflow
- a standalone catalog translation dashboard
