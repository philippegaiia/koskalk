# Brazilian Portuguese Interface Translation Design

## Goal

Prepare a complete, reviewed Brazilian Portuguese (`pt_BR`) interface catalogue without adding an in-application translation provider, changing platform ingredient content, or activating Portuguese before the interface is ready.

## Scope

This work translates only application-owned interface strings from the approved English source into Brazilian Portuguese. It covers the complete `language_lines` catalogue, currently 2,286 keys, including labels, actions, help text, warnings, statuses, empty states, and accessibility text.

The following remain outside scope:

- platform ingredient display names and guidance in `ingredient_translations`
- private workspace content, including user-owned ingredient names and notes
- canonical INCI, chemical identifiers, formula values, and regulatory nomenclature
- the English-only Filament admin
- an OpenAI API integration, API key, background job, or automatic translation feature in Soapkraft

## Translation Workflow

### 1. Establish the Brazilian Portuguese glossary

Use GPT-5.6 Luna at medium reasoning to draft a short glossary from the English source and existing reviewed terminology. The glossary fixes recurring product terms before bulk translation, including workspace, ingredient, formula, batch, supplier, saponification, formula additions, compliance, allergen, IFRA, preservation, and product.

The glossary must distinguish canonical technical labels from ordinary interface language. It does not translate INCI or chemical identifiers and it retains the project decisions that use `Saponification` and `Formula additions` in English source terminology.

### 2. Translate in bounded catalogue batches

Use GPT-5.6 Luna at medium reasoning to translate ordinary strings in bounded batches grouped by application domain. Each translation request receives the English key, source text, placeholders, nearby strings, and approved glossary. It returns only a `pt_BR` value for each supplied key.

Drafts are written only to an isolated local working copy or dedicated local database state. The current authoritative catalogue stays unchanged until every batch is complete and validated. Reviewed non-blank values must never be overwritten by a later draft.

### 3. Automated validation after each batch

Every batch must pass these checks before it can be accepted:

- every supplied key has exactly one non-blank `pt_BR` value
- Laravel placeholders, HTML fragments, line breaks, and interpolation tokens are preserved
- catalogue key and locale ordering remain deterministic
- `pt_BR` values do not alter English source strings or other locale values
- translation output contains no commentary, Markdown fences, or duplicate keys

The completed staged catalogue must validate using the existing `InterfaceTranslationCatalogue` service and the focused Pest translation-catalogue tests.

### 4. Targeted editorial review

Use GPT-5.6 Terra at high reasoning only for higher-risk items and any Luna output flagged by validation or human sampling. The review set includes:

- ingredient taxonomy, soap chemistry, COSING, allergen, IFRA, preservation, and compliance text
- authentication, account, billing, deletion, validation, and safety-critical warnings
- long, ambiguous, or multi-sentence explanatory copy
- strings with placeholders, rich text, or uncertain terminology

Terra returns corrections only for the reviewed keys. It does not redo routine short labels that already follow the glossary.

### 5. Rendered sample review and promotion

Review representative public and authenticated screens in `pt_BR`: language selector, account, settings, ingredient list/editor, workbench, production bench, media library, and warnings. Resolve mixed-language or awkward wording before promotion.

When all interface values are reviewed, add `pt_BR` to the configured catalogue locales, import the complete catalogue into the local database in authoritative mode, export the deterministic catalogue, and commit the resulting JSON and tests. Keep `pt_BR` inactive until this rendered review is accepted. Activation then occurs in the existing Admin Languages resource.

## Data and Safety Rules

- English remains canonical and remains the fallback.
- The Brazilian Portuguese locale remains inactive throughout drafting and review.
- No partially translated `pt_BR` map may be added to the authoritative catalogue because the catalogue requires complete locale maps.
- Platform ingredient content still falls back to English until separately curated.
- Interface language and number-format choice remain independent. `pt_BR` number formatting is already registered as `1.234,56`.
- Translation drafting is an editor-assisted development task, never a deployment side effect.

## Verification

The implementation must add or extend focused tests for:

- `pt_BR` catalogue completeness and exact locale ordering once the locale is promoted into the catalogue
- placeholder preservation and catalogue parsing through `InterfaceTranslationCatalogue`
- Brazilian Portuguese loading through `language_lines` with English fallback only for intentionally untranslated platform content
- continued inability to select `pt_BR` while inactive, followed by selection once it is deliberately activated

Run the focused Pest localization suite, Laravel Pint, the existing deterministic catalogue checks, and Graphify after code or catalogue changes.
