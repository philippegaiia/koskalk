# Platform Ingredient Batch Enrichment and Vocabulary Localization Design

**Date:** 2026-08-13
**Status:** Approved for implementation planning

## Goal

Make initial platform-catalogue preparation practical when many ingredients must be entered. An Admin enters a minimum English identity in the development database, then a source-backed batch workflow researches, enriches, and translates those platform ingredients through validated JSONL. The workflow remains review-gated and repeatable without adding an in-application AI provider.

The same work completes workspace localization of the fixed COSING function vocabulary. Existing category and subcategory translations remain the shared vocabulary used by every ingredient.

## Product Context

The application is in development. Existing deployments are for testing and learning the deployment process, not the authoritative production editorial workflow. The development database is the current ingredient-curation workspace.

The current classification helper generates readable research instructions for an external model. It does not parse the response, populate ingredient fields, create `ingredient_translations`, or preserve development records across a fresh deployment. Interface translation synchronization and catalogue import do not operate on ingredient records.

This design adds a development-first enrichment workflow. A separate deterministic platform-catalogue export and seed flow remains required before the curated development database becomes deployable catalogue data.

## Settled Decisions

- English remains the canonical platform editorial source.
- Only platform ingredients participate in managed enrichment and translation.
- Private workspace ingredients remain exactly as authored.
- The first enrichment pass covers core identity, classification, COSING assignments, concise guidance, and ingredient translations.
- Specialist chemistry and compliance enrichment is deferred to family-specific passes.
- Research is performed outside the application, including from Codex in the repository workspace, and returned as structured JSONL.
- Import is a dry run by default and writes only with explicit approval.
- Existing reviewed values are preserved by default.
- Categories, subcategories, and COSING functions are shared controlled vocabularies, not per-ingredient translated content.
- Canonical identifiers, backing keys, numeric values, and source references are never translated.
- For a platform record curated for colourant use, the applicable CI designation is the label-ready EU common ingredient name and may be stored in `inci_name`.

## Scope

### Included

- Localize all active COSING function labels and descriptions through the existing interface translation catalogue.
- Resolve category, subcategory, and COSING labels through the workspace locale with English fallback.
- Export incomplete platform ingredients as deterministic, research-ready JSONL.
- Generate source-backed enrichment JSONL in bounded batches.
- Validate and preview enrichment results without database writes.
- Apply valid results explicitly and atomically per ingredient.
- Populate core ingredient identity, classification, verified COSING assignments, English guidance, and `ingredient_translations`.
- Populate the label-ready EU common ingredient name, including the applicable CI designation for a platform record curated for non-hair colourant use.
- Support safe reruns that fill gaps without erasing reviewed work.

### Deferred

- SAP, iodine, INS, and fatty-acid enrichment for lipids and waxes.
- Allergen, IFRA, and detailed composition enrichment for aromatic materials.
- Detailed Annex IV matching, use conditions, and market-specific regulatory enrichment for colourants.
- Automated OpenAI or other AI-provider calls from the application.
- Queues, background enrichment jobs, and unattended publication.
- End-user translation-correction proposals.
- Deterministic export and production seeding of the complete curated platform ingredient catalogue.

## Translation Model

### Shared controlled vocabulary

Categories and subcategories are fixed global vocabulary. Their labels and descriptions already resolve through keys under:

```text
ingredients.categories.{backing_value}.label
ingredients.categories.{backing_value}.description
ingredients.subcategories.{backing_value}.label
ingredients.subcategories.{backing_value}.description
```

The current authoritative interface catalogue contains the category and subcategory translations, including Brazilian Portuguese. Adding or enriching an ingredient does not create or update these translations.

The 65 COSING functions follow the same pattern:

```text
ingredients.functions.{function_key}.label
ingredients.functions.{function_key}.description
```

This adds 130 shared interface entries. English remains canonical in the existing function seed data. Each `IngredientFunction` exposes localized label and description resolvers with canonical English fallback. All workspace rendering and the classification prompt use those resolvers. Admin remains English-only.

The function backing key and verified assignment evidence remain unchanged. For example, an ingredient stores `emollient`; the workspace may render `Émollient` or `Emoliente` according to locale.

### Per-ingredient editorial translation

Only these platform ingredient fields are translated per ingredient:

- `display_name`
- `saponification_name`, when relevant
- `info_markdown`

They continue to use `ingredient_translations`. Each field falls back independently to canonical English. The label-ready common ingredient name, CAS, EC/EINECS, UNII, ECHA List Number, InChIKey, PubChem CID, category values, subcategory values, COSING keys, regulatory references, dates, percentages, and units remain canonical.

For EU cosmetic labelling, Article 19(1)(g) of [Regulation (EC) No 1223/2009](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32009R1223) requires CI nomenclature where applicable for colourants other than colourants intended to colour hair. The [European Commission glossary guidance](https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database/cosing-glossary-ingredients_en) and current [Implementing Decision (EU) 2025/1175](https://eur-lex.europa.eu/eli/dec_impl/2025/1175/oj/eng) state that the CI number should therefore be listed as the common ingredient name for those colourants.

CI and INCI remain technically distinct nomenclatures, but the generated EU ingredient list needs one label-ready common ingredient name. Soapkraft's current `inci_name` field supplies that output. For a platform record curated for colourant use, it may therefore contain a value such as `CI 77491`. That controlled designation is not translated. An underlying chemical or INCI identity may still be relevant and must remain in the research evidence rather than being treated as a translation. Ambiguous or role-dependent cases remain under Admin review until the specialist colourant model is designed.

## Core Enrichment Workflow

### 1. Minimal Admin entry

An Admin creates or edits a platform ingredient with the minimum reliable English information:

- display name;
- label-ready common ingredient name or supplier identity when known, including a CI designation for a record curated for colourant use;
- category selected by the Admin;
- optional subcategory, identifiers, aliases, and source notes.

The workflow does not require the Admin to complete translations or specialist data by hand.

### 2. Deterministic export

An export command selects incomplete platform ingredients. It supports explicit ingredient selection and an incomplete-only batch mode. Private ingredients are excluded.

Each JSONL record contains:

- schema version;
- stable `catalog_key`;
- a fingerprint of the exported source state;
- current canonical fields and existing translations;
- the Admin-selected category;
- allowed category, compatible subcategory, COSING function, identifier-scheme, and locale values;
- requested output fields;
- the concise-guidance contract;
- the source and verification rules required by the research result.

Ordering is deterministic so an unchanged export produces no diff.

### 3. External research

Codex or another external research model processes the exported records in bounded batches. Routine editorial translation may use a lower-cost model. Canonical identity, COSING assignments, and regulatory distinctions require source-backed research against authoritative primary sources.

The result is JSONL only. It contains no Markdown fences or explanatory text outside the schema.

### 4. Dry-run review

The import command validates every result and displays a field-level change summary. Dry run is the default and performs no writes.

The summary distinguishes:

- new values;
- preserved existing values;
- explicitly requested replacements;
- warnings and unresolved questions;
- invalid or stale records.

### 5. Explicit apply

An explicit apply option writes valid records. Each ingredient is handled in its own database transaction. An invalid ingredient is skipped and reported without preventing unrelated valid records from being applied.

Applied ingredients remain marked as requiring Admin review. Import never activates a locale, changes a private ingredient, or publishes unsupported specialist data.

## Research Result Contract

Each result identifies the exported ingredient by `catalog_key` and source fingerprint. Its proposal may contain:

- canonical English display name;
- label-ready common ingredient name and normalized identifier proposals, including a CI designation where applicable and any distinct underlying chemical or INCI identity in the evidence;
- category review and compatible subcategory proposal;
- verified COSING function assignments;
- concise English `info_markdown`;
- optional English `saponification_name`;
- one translation row per configured non-English target locale;
- evidence attached to identity and COSING proposals;
- confidence, warnings, and unresolved questions.

Category review reports a proposed correction rather than silently replacing the Admin-selected category. Practical formulation roles remain guidance unless an official COSING source verifies the exact assignment for the exact ingredient.

Every verified COSING proposal contains the unchanged function key and an official source reference. Unsupported function claims fail validation as verified assignments; they may remain plain-language guidance.

## Ingredient Guidance Contract

`info_markdown` begins as useful introductory editorial content rather than a complete technical monograph. The English source is approximately 100–180 words with up to three short sections:

1. **Overview** — what the ingredient is and its common cosmetic role.
2. **Formulation use** — what it generally contributes and any material distinction a beginner should understand.
3. **Soapmaking** — included only when relevant, explaining practical effects in plain language.

For a carrier oil, soapmaking guidance may summarize likely contributions to hardness, cleansing, conditioning, or lather. It does not invent an exact usage percentage, SAP value, fatty-acid profile, or safety conclusion.

Initial guidance excludes unsupported therapeutic claims, supplier-specific properties presented as universal, detailed specialist data, claims of regulatory approval, and precise usage limits without an exact verified source.

The research result retains evidence and warnings. Public guidance remains concise. Non-English guidance preserves the approved English meaning and introduces no additional claims.

Admin edits are authoritative. Safe reruns preserve non-empty guidance by default and may propose a replacement only when replacement is explicitly requested and shown in the dry-run diff.

## Validation and Safety

A result is eligible for apply only when:

- its schema version is supported;
- `catalog_key` identifies an existing platform ingredient;
- the source fingerprint still matches the current ingredient state;
- category, subcategory, COSING, identifier, and locale keys are recognized;
- the subcategory belongs to the proposed category;
- a CI-form common ingredient name applies to a non-hair colourant record; ambiguous or role-dependent identity is retained for Admin review;
- each verified COSING assignment carries acceptable official evidence;
- translated fields preserve their canonical field boundaries;
- required target locale rows are present;
- no private ingredient or deferred specialist field is targeted.

The importer uses existing ingredient identity, function-assignment, data-entry, and translation modules rather than writing relationship tables independently.

Existing non-empty values are preserved unless an explicit replacement mode is selected. Replacement remains visible in dry-run output. A stale fingerprint blocks that record so later Admin edits cannot be overwritten by an older research result.

Repeated import of the same accepted result is idempotent.

## Error Handling

- Malformed JSONL reports the line number and does not apply that line.
- Unknown or duplicate `catalog_key` values report record-level errors.
- Invalid vocabulary or locale values report the rejected field and allowed values.
- Missing or unacceptable COSING evidence prevents that assignment from being written while leaving the record available for correction.
- Stale fingerprints instruct the Admin to export and research the current state again.
- Per-ingredient transactions prevent partially updated ingredient relationships.
- The command exits unsuccessfully when any record fails and prints counts for applied, unchanged, skipped, warned, and failed records.

## Testing

Focused Pest coverage proves:

- deterministic export ordering and fingerprints;
- explicit selection and incomplete-only export;
- exclusion of private ingredients;
- dry run performs no writes;
- valid apply uses the existing domain modules;
- each ingredient is atomic;
- stale fingerprints are rejected;
- invalid categories, incompatible subcategories, COSING keys, identifier schemes, and locales are rejected;
- CI-form common ingredient names are limited to applicable non-hair colourant records and ambiguous identities remain reviewable;
- verified COSING assignments require evidence;
- practical roles do not become verified assignments;
- existing reviewed fields are preserved by default;
- explicit replacement is visible and applied only when requested;
- translated fields resolve independently with English fallback;
- rerunning an accepted result is idempotent;
- deferred specialist fields cannot be imported;
- all category, subcategory, and COSING rendering paths use localized resolvers;
- every active COSING function has complete catalogue translations for configured locales.

## Acceptance Criteria

- An Admin can create many minimally described platform ingredients in the development database.
- Incomplete platform ingredients can be exported as deterministic research JSONL.
- Codex can research the batch and return schema-valid enrichment JSONL.
- Import previews all changes without writing by default.
- Explicit apply populates supported core fields and per-ingredient translations without overwriting reviewed work.
- Concise guidance includes practical soapmaking information when relevant.
- Categories, subcategories, and COSING functions render in the workspace locale through shared vocabulary translations.
- Canonical backing values, identifiers, and evidence remain unchanged.
- A platform record curated for applicable non-hair colourant use can use its CI designation as the `inci_name` consumed by ingredient-list generation.
- Specialist chemistry and compliance records remain untouched for later family-specific enrichment.
- The workflow can be rerun safely as new platform ingredients are entered or existing ones are gradually improved.

## Follow-up Designs

After the core pipeline is proven, create separate designs for:

1. lipid and wax chemistry enrichment;
2. aromatic allergen, IFRA, and composition enrichment;
3. Annex IV and market-regulation enrichment for colourants;
4. deterministic curated platform-catalogue export and production seeding;
5. optional in-application AI-provider automation.
