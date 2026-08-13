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
- A platform colourant keeps its `CI xxxxx` value in `inci_name` as its canonical INCI declaration regardless of the selected market.
- Colourants may have one market-label override per supported market. The override changes printed labelling; it does not duplicate the ingredient, translate the CI value, or imply that the colourant is authorized for that use.
- EU and US are the only initial markets for colour-label resolution. Other jurisdictions are separate future market packs, not a shared regional regime.

## Scope

### Included

- Localize all active COSING function labels and descriptions through the existing interface translation catalogue.
- Resolve category, subcategory, and COSING labels through the workspace locale with English fallback.
- Export incomplete platform ingredients as deterministic, research-ready JSONL.
- Generate source-backed enrichment JSONL in bounded batches.
- Validate and preview enrichment results without database writes.
- Apply valid results explicitly and atomically per ingredient.
- Populate core ingredient identity, classification, verified COSING assignments, English guidance, and `ingredient_translations`.
- Populate the canonical `CI xxxxx` INCI value for a platform colourant.
- Let an Admin maintain a colourant's printed declaration and regulatory source through a dedicated **Market colour labels** action.
- Research and import source-backed EU and US colour-label proposals for colourants.
- Resolve a colourant's printed ingredient-list value for the market selected by the recipe's regulatory regime.
- Support safe reruns that fill gaps without erasing reviewed work.

### Deferred

- SAP, iodine, INS, and fatty-acid enrichment for lipids and waxes.
- Allergen, IFRA, and detailed composition enrichment for aromatic materials.
- Detailed colourant authorization, permitted-use conditions, purity requirements, and restriction matching. A market-label record is a naming rule, not proof that the colourant is permitted.
- Colour-label packs for markets other than EU and US. Each future jurisdiction is researched and enabled independently; there is no generic South America profile.
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

They continue to use `ingredient_translations`. Each field falls back independently to canonical English. The INCI value, market colour labels, CAS, EC/EINECS, UNII, ECHA List Number, InChIKey, PubChem CID, category values, subcategory values, COSING keys, regulatory references, dates, percentages, and units remain canonical.

### Colourant identity and market labels

For EU cosmetic labelling, Article 19(1)(g) of [Regulation (EC) No 1223/2009](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32009R1223) requires CI nomenclature where applicable for colourants other than colourants intended to colour hair. The [European Commission glossary guidance](https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database/cosing-glossary-ingredients_en) and current [Implementing Decision (EU) 2025/1175](https://eur-lex.europa.eu/eli/dec_impl/2025/1175/oj/eng) state that the CI number should therefore be listed as the common ingredient name for those colourants.

Soapkraft therefore stores a colourant's `CI xxxxx` declaration, such as `CI 77491`, in `inci_name`. This is the ingredient's stable canonical value in the application and is not translated or changed when a recipe market changes.

The US labelling system requires a special printed value. The [FDA cosmetic ingredient naming guidance](https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names) states that a CI number cannot replace the ingredient name required in the United States, although it may follow the US name in parentheses. Certified and certification-exempt colour additives use their applicable US names, such as `FD&C Red No. 40`, `Red 40`, or `Iron Oxides`, under the FDA rules applicable to that additive.

The normalized market-label relation stores at most one current row per platform colourant and supported market. A row contains:

- the ingredient;
- the market code;
- the exact declaration value to print;
- the authoritative source name and URL;
- optional effective dates and review metadata.

The **Market colour labels** Admin action creates or updates these rows. It initially offers only EU and US. It may show the canonical CI value as the proposed EU declaration, while the US declaration requires an explicit source-backed value. Adding another interface locale does not add a regulatory market, and an existing experimental regulatory regime does not make its market supported by this colour-label workflow.

Ingredient-list generation resolves colourants through one small market-label interface:

1. Non-colourants continue to print their canonical `inci_name`.
2. A colourant prints its market-label row when one exists for the selected market.
3. EU may fall back to the canonical `CI xxxxx` value.
4. US generation without a source-backed market-label row reports a blocking validation error rather than silently printing the CI value.

This resolution changes only the printed name. Colourant authorization and use restrictions remain a separate deferred compliance concern.

## Core Enrichment Workflow

### 1. Minimal Admin entry

An Admin creates or edits a platform ingredient with the minimum reliable English information:

- display name;
- canonical INCI name or supplier identity when known, using `CI xxxxx` for a colourant;
- category selected by the Admin;
- optional subcategory, identifiers, aliases, and source notes.

The workflow does not require the Admin to complete translations or specialist data by hand.

For a colourant, the Admin can also open **Market colour labels** to review or enter the EU and US printed declaration values and their sources. This action is available independently of batch import so a single newly entered colourant can be completed or corrected without rerunning an entire catalogue batch.

### 2. Deterministic export

An export command selects incomplete platform ingredients. It supports explicit ingredient selection and an incomplete-only batch mode. Private ingredients are excluded.

Each JSONL record contains:

- schema version;
- stable `catalog_key`;
- a fingerprint of the exported source state;
- current canonical fields and existing translations;
- current colour market-label rows;
- the Admin-selected category;
- allowed category, compatible subcategory, COSING function, identifier-scheme, locale, and supported market values;
- requested output fields;
- the concise-guidance contract;
- the source and verification rules required by the research result.

Ordering is deterministic so an unchanged export produces no diff.

### 3. External research

Codex or another external research model processes the exported records in bounded batches. Routine editorial translation may use a lower-cost model. Canonical identity, COSING assignments, and colour market-label distinctions require source-backed research against authoritative primary sources. Initial colour research is limited to EU and US declaration names.

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

Applied ingredients remain marked as requiring Admin review. Import may write validated market-label rows for a platform colourant through the same domain module used by the Admin action. It never activates a locale or market, changes a private ingredient, or publishes unsupported specialist data.

## Research Result Contract

Each result identifies the exported ingredient by `catalog_key` and source fingerprint. Its proposal may contain:

- canonical English display name;
- canonical INCI and normalized identifier proposals, using `CI xxxxx` for a colourant;
- source-backed EU and US printed market-label proposals when the ingredient is a colourant;
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
- `CI xxxxx` is used only for a colourant record, with ambiguous identity retained for Admin review;
- every proposed market-label row targets EU or US, contains an exact printed declaration, and carries an authoritative source;
- a US colour declaration is not accepted when it merely repeats a bare CI value;
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
- Missing or unacceptable market-label evidence prevents that market row from being written while leaving the canonical colourant available for correction.
- US ingredient-list generation identifies each colourant missing its required market label and does not present the result as market-adapted output.
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
- `CI xxxxx` canonical values are limited to colourant records and remain unchanged when the recipe market changes;
- the Admin market-label action maintains at most one current declaration per colourant and market with its source;
- EU colour labelling uses an explicit market label when present and otherwise falls back to canonical `CI xxxxx`;
- US colour labelling uses the source-backed US declaration and rejects a missing or bare-CI declaration;
- non-colourants continue to print their canonical INCI value;
- market-label records do not mark a colourant as authorized or permitted;
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
- A platform colourant keeps `CI xxxxx` as its canonical `inci_name` for every market.
- An Admin can enter or correct a colourant's exact printed declaration and source for EU or US through **Market colour labels**.
- Ingredient-list generation selects the colourant declaration for the recipe market, with canonical CI fallback for EU and a required explicit US value.
- No country-specific ingredient duplicate is created, and changing the interface language does not change the selected regulatory market.
- Specialist chemistry and compliance records remain untouched for later family-specific enrichment.
- The workflow can be rerun safely as new platform ingredients are entered or existing ones are gradually improved.

## Follow-up Designs

After the core pipeline is proven, create separate designs for:

1. lipid and wax chemistry enrichment;
2. aromatic allergen, IFRA, and composition enrichment;
3. colourant authorization, restrictions, and future jurisdiction market packs;
4. deterministic curated platform-catalogue export and production seeding;
5. optional in-application AI-provider automation.
