# Hybrid Ingredient Enrichment Source Pipeline Design

**Date:** 2026-08-14

**Status:** Approved design awaiting user review

**Extends:** `2026-08-13-platform-ingredient-batch-enrichment-and-vocabulary-localization-design.md`

## Goal

Make Admin enrichment reliable and economical for a large initial ingredient catalogue. Structured sources provide identity and regulatory facts first. AI receives those facts, resolves stated ambiguities, writes concise ingredient and soapmaking guidance, and translates the approved field set. No research stage writes directly to the ingredient catalogue.

This design replaces the current live-web-search-first research strategy. It preserves the existing queued batch, proposal, review, approval, and apply workflow. Where the earlier design conflicts with this document, this document supersedes its external-research strategy, its colourant-only market-label boundary, and its identifier provenance contract.

## Problem

The first in-application research trials proved the queue and review workflow but produced incomplete proposals at high token cost. The model could not reliably access the JavaScript-driven Commission CosIng interface, so it searched broadly, consulted many irrelevant pages, and still omitted CosIng functions or identifiers. The review screen also emphasized the complete list of consulted URLs instead of the evidence supporting each proposed field.

The data exists, but the current access strategy is wrong. Deterministic data should not be rediscovered through general web search for every ingredient.

## Settled Decisions

- Use a hybrid deterministic pipeline.
- EU and US are the only active enrichment markets for the MVP.
- CosIng Checker may prefill EU proposals as a structured secondary mirror.
- A mirror-provided value is never described as officially verified unless an official source corroborates it.
- FDA GSRS/openFDA supplies US-maintained substance identity data such as UNII; a UNII is not a US cosmetic label name and does not imply approval.
- Store identity and registry identifiers separately from market declaration names.
- Allow multiple identifiers of the same scheme when sources identify multiple valid CAS or EC values.
- Apply dedicated EU and US rules to colourants.
- AI runs after structured lookup and is limited to ambiguity handling, concise editorial guidance, translations, and unresolved-gap research when necessary.
- Research creates a reviewable proposal. Approval and apply remain separate explicit actions.
- Existing reviewed ingredient values are preserved unless replacement is explicitly requested.
- Categories, subcategories, and CosIng functions are shared controlled vocabulary; their translations are not regenerated per ingredient.
- Regulatory authorization, permitted-use decisions, detailed chemistry, and markets beyond EU and US remain outside this MVP.

## Source Strategy

### Source tiers

Every field-level evidence record has a source tier:

1. **Official regulatory or government source** — European Commission/EUR-Lex, FDA/eCFR, FDA GSRS/openFDA, or another explicitly approved government source.
2. **Structured attributed mirror** — initially CosIng Checker for EU inventory and Annex candidate data.
3. **Cited editorial source** — initially individual COSMILE Europe pages for human-readable ingredient background when needed. Editorial sources may inform paraphrased guidance but do not verify a legal declaration or authorization.

Restricted commercial databases, retailers, marketplaces, generic blogs, SEO summaries, and unattributed community mirrors are not automated sources.

### Initial sources

#### EU

- [European Commission cosmetic ingredient database information](https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database_en) establishes the role and non-binding status of CosIng.
- [European Commission CosIng glossary information](https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database/cosing-glossary-ingredients_en) and the current glossary decision provide official common ingredient names.
- EUR-Lex Regulation (EC) No 1223/2009 and its current Annexes remain authoritative for restrictions and authorization. A CosIng inventory entry does not establish that an ingredient is permitted.
- [CosIng Checker API](https://cosingchecker.com/api/) supplies structured candidate inventory and Annex data under its published CC BY 4.0 terms and caching guidance. Every response retains attribution, the reported upstream version or synchronization date, and retrieval time.

#### US

- [FDA cosmetic ingredient naming guidance](https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names) and 21 CFR 701.3 govern ordinary cosmetic ingredient declaration names.
- [FDA GSRS](https://www.fda.gov/industry/fda-data-standards-advisory-board/fdas-global-substance-registration-system) and openFDA substance endpoints supply identity mappings such as UNII, names, and related codes. UNII availability is identity evidence only.
- [FDA colour additives permitted for cosmetics](https://www.fda.gov/cosmetics/cosmetic-ingredient-names/color-additives-permitted-use-cosmetics), the applicable eCFR sections, and FDA colour-additive guidance supply US colour names, certification status, application areas, and restrictions.

#### Editorial guidance

- Individual COSMILE Europe pages may be cited and paraphrased for general ingredient background. Its database must not be bulk copied because its legal notice does not grant an unrestricted bulk-ingestion right.

### Confidence states

Each proposed source-backed field receives one state:

- **Verified:** an exact official source supports the exact value and identity.
- **Supported:** an accepted secondary source supports the value and no accepted source contradicts it.
- **Conflicting:** accepted sources disagree, or the returned identity differs by plant part, extraction, chemical form, hydration state, or another material distinction.
- **Unresolved:** no sufficiently reliable value was found.

Confidence belongs to a field, not only to the ingredient as a whole. The item-level confidence summarizes the lowest material field state and never hides a field conflict.

## Data Model

### Identity and registry identifiers

Continue using `ingredient_identifiers` as the only source of truth for identifier values. Retain the existing CAS, EC/EINECS, UNII, ECHA List Number, InChIKey, and PubChem CID schemes. Add `pubchem_sid` because mixtures such as argan oil may have a PubChem substance record without a compound structure, and add `cosing_ref` for the stable CosIng inventory record reference.

Identifiers are multi-valued. At most one value per scheme may be primary for prominent display, but additional valid CAS, EC, or other values must remain available. A source's jurisdiction does not make a CAS or UNII market-specific; the evidence records which source supplied the mapping.

Add an `ingredient_identifier_evidence` relation so one identifier can retain evidence from more than one source without duplicating its value. Each evidence row stores source name, exact URL, source tier, upstream version/update date when available, retrieval timestamp, and confidence. The enrichment batch result remains the immutable proposal audit; applying accepted evidence reconciles the identifier evidence relation through the identity domain service.

### Market declaration names

`ingredient_market_labels` represents exact declaration text for a supported market. It is distinct from universal identity and is extended from colourants to all platform ingredients.

Each market row contains:

- ingredient;
- market code (`EU` or `US` for the MVP);
- exact declaration name;
- source name and URL;
- verification/confidence state;
- optional upstream version and retrieval metadata;
- effective dates when applicable;
- review metadata.

Extend the existing market-label record with the source tier, upstream version/update date, retrieval timestamp, and confidence state. One current market declaration may have one accepted source record for the MVP; additional consulted evidence remains in the immutable enrichment batch result.

For ordinary EU ingredients, the declaration candidate is the applicable current common ingredient name. For ordinary US cosmetics, the declaration candidate is the English common or usual name required by 21 CFR 701.3. An international/INCI name may follow parenthetically when appropriate, but it does not automatically replace the required English name.

The declaration resolver uses an explicit market row when present. Existing legacy fallback behavior remains only where it is demonstrably safe and is made visible during implementation planning; a missing US declaration must not silently fall back to an EU/INCI value and claim to be market-adapted.

### Colour regulatory mapping

Colourants require data beyond a printed name. Keep the following concepts distinct:

- canonical CI identity/number where applicable;
- EU printed common ingredient name;
- US FDA-listed declaration name or allowed abbreviation;
- FDA certification requirement;
- permitted application areas such as eye area, generally permitted, or external use;
- restrictions and applicable EU Annex/FDA CFR citations.

For example, `CI 19140` is the EU declaration, while the US declaration may be `FD&C Yellow No. 5` or the acceptable combined display `FD&C Yellow No. 5 (CI 19140)`. A CI number alone is not an acceptable US substitute. A declaration mapping never implies authorization, compliance with restrictions, or FDA batch certification.

Detailed colour authorization logic remains deferred, but the enrichment proposal may surface sourced candidate restrictions for review without applying them as compliance-ready rules.

### Controlled vocabulary and translated content

Category, subcategory, and CosIng function keys remain canonical shared vocabulary. Their labels and descriptions are translated once through the existing interface translation catalogue.

Per-ingredient enrichment generates only the existing editorial fields:

- display name;
- saponification name when relevant;
- concise information Markdown;
- configured translations of those fields.

Identifiers, declaration names, evidence, regulatory references, vocabulary keys, dates, percentages, and units are never translated. A market declaration may contain multilingual or parenthetical text only when the market rule itself requires or permits that exact declaration.

## Pipeline Architecture

Each batch item passes through independently persisted stages:

1. **Identity preparation** — normalize the Admin-entered English name, selected category, existing INCI, aliases, and existing identifiers without changing the ingredient.
2. **EU structured lookup** — query CosIng Checker using the strongest available identity signals and collect exact candidate records, functions, identifiers, and references.
3. **EU official corroboration** — verify the common ingredient name and any material Annex claims against Commission/EUR-Lex sources that are technically accessible. Uncorroborated mirror data remains supported, not verified.
4. **US identity lookup** — query GSRS/openFDA by exact names and known identifiers, preserving all exact candidate mappings.
5. **US declaration lookup** — derive a reviewable common/usual-name proposal from official FDA naming rules and exact identity evidence. For colourants, use the dedicated FDA colour lookup and applicable CFR record.
6. **Conflict evaluation** — compare plant species, plant part, extraction, chemical form, identifier mappings, and market names. Do not merge materially different substances.
7. **AI editorial pass** — provide the structured verified/supported facts to the configured model. The model writes concise English guidance, includes a soapmaking section only when relevant, translates configured editorial fields, and states unresolved ambiguities. General web search is disabled by default and may be enabled only for an explicit unresolved-gap stage with the current source allow-list.
8. **Schema and evidence validation** — normalize the result, validate controlled values and field-level evidence, build the change plan, and assign review status.

Every stage exposes a small adapter contract so source access, normalization, identity matching, confidence evaluation, and AI editorial work can be tested independently.

Add a `research_stages` JSON value to each batch item, keyed by stable stage name. Each stage stores its status, normalized output, source metadata, completion time, and safe failure information. This is resumable working state, not canonical ingredient data. The final normalized proposal and evidence remain in the existing result and plan fields.

## Batch Execution and Cost Controls

- An Admin may select one or many incomplete platform ingredients in Filament and start one batch.
- The application continues dispatching one queued job per ingredient. This isolates failures and permits targeted retries while preserving batch-level progress.
- Structured lookups run before any paid AI call.
- Source responses use the configured Laravel cache store according to each source's terms. Cache keys include source, normalized query or record identifier, upstream version when available, and response shape version. Stage results retain the exact provenance needed for audit even after a cache entry expires.
- The AI input contains normalized facts and only the evidence needed for editorial work. It does not receive the full raw source payload or the complete CosIng vocabulary repeatedly when a compact allowed-key list is sufficient.
- One normal AI call produces English guidance and all configured editorial translations for an ingredient.
- A retry runs only failed or unresolved stages. Completed deterministic stages remain reusable unless the source cache/version or ingredient fingerprint has changed.
- Batch and item token counts, model, reasoning effort, structured-source calls, optional web searches, and attempt counts remain visible.

## Review and Approval

The existing batch review page remains the workflow entry point. It changes from a raw result dump into a field-level comparison.

For each field, show:

- current database value;
- proposed value;
- source confidence state;
- exact supporting source link and source tier;
- source update/version and retrieval date when available;
- conflict or unresolved explanation;
- whether approval will add, preserve, or explicitly replace the value.

Raw consulted URLs are collapsed into a secondary diagnostic area. They are not presented as if every URL supports every field.

Available actions:

- **Approve item:** approve the complete reviewed proposal.
- **Approve safe batch:** approve only warning-free items with no conflicting or unresolved required field. Both verified and supported fields are eligible because this remains an explicit Admin approval; their distinct confidence labels remain visible and persist after apply.
- **Edit proposal:** correct proposed values before approval while retaining the original research result and audit trail.
- **Retry gaps:** rerun only failed or unresolved stages.
- **Reject or leave pending:** make no catalogue change.
- **Apply approved:** apply approved proposals through existing domain services in per-ingredient transactions.

Conflicting items are excluded from safe bulk approval. An edited proposal is marked as Admin-edited and must be revalidated before approval. Apply rechecks the source fingerprint so research cannot overwrite a later Admin edit.

## Apply Semantics

- Research and retry never write canonical ingredient data.
- Approval records who accepted the proposal but does not mutate the catalogue.
- Apply uses the existing identity synchronizer, function assignment service, translation service, and market-label service rather than writing relationship tables directly.
- Applying CosIng functions extends the existing pivot provenance with confidence, source tier, and upstream version/update metadata; the exact source reference and checked date remain mandatory.
- Existing reviewed values remain unless the plan marks that exact field for replacement.
- Additional valid identifiers, including secondary CAS and EC values, are preserved.
- An unchanged plan performs no metadata-only catalogue mutation.
- Each ingredient applies atomically and independently from other batch items.
- Reapplying the same accepted result is idempotent.
- Applied source confidence and provenance remain inspectable from the ingredient or its latest enrichment audit.

## Failure Handling

- A transport, rate-limit, timeout, or source-format failure records the source and pipeline stage, retains completed stages, and offers targeted retry.
- An unavailable mirror prevents new mirror-prefill data but does not invalidate previously cached responses whose age/version remains acceptable.
- No exact identity match produces an unresolved item, not a guessed merge.
- Multiple exact-looking records with material identity differences produce a conflict requiring review.
- A disagreement between a mirror and an official source uses the official value as the verified candidate and records the discrepancy.
- Missing official corroboration leaves an otherwise acceptable mirror value supported.
- Missing US common/usual declaration evidence never turns a UNII synonym or EU INCI into an automatically verified US label.
- A colour found in CosIng but absent from the applicable FDA colour list does not receive a guessed US declaration or authorization.
- AI output that fails schema, evidence, controlled-vocabulary, translation, or guidance validation is retained diagnostically and is not approvable until corrected or retried.
- Safe error messages identify the failed stage and request identifier without exposing API keys or full provider payloads.

## MVP Scope

### Included

- Existing Filament batch creation, queue processing, review, approval, and apply flow.
- Selection of one or many platform ingredients.
- Deterministic CosIng Checker integration with attribution and caching.
- EU official common-name corroboration where accessible.
- FDA GSRS/openFDA identity lookup and UNII support.
- EU and US declaration proposals for ordinary ingredients.
- Dedicated FDA colour name and restriction lookup.
- Multiple identifiers per scheme.
- CosIng function assignment proposals.
- Concise English ingredient overview, formulation use, and relevant soapmaking guidance.
- Existing configured ingredient translations.
- Field-level provenance, confidence, warnings, conflicts, and unresolved questions.
- Targeted stage retry and batch cost metrics.

### Deferred

- Markets other than EU and US. Each jurisdiction is a separate onboarding project; there is no generic South America profile.
- Automated legal or regulatory approval decisions.
- Full colour authorization and formula-level restriction evaluation.
- SAP, iodine, INS, fatty-acid profiles, usage percentages, and detailed formulation chemistry.
- IFRA, allergen, and aromatic composition passes.
- Bulk ingestion from COSMILE, SpecialChem, or another restricted database.
- A paid commercial regulatory API unless the structured public-source approach proves insufficient.
- Production catalogue seeding and deployment synchronization, which remain a separate curated-data workflow.

## Testing Strategy

Focused Pest coverage must prove:

- source adapters normalize recorded representative responses without live network calls;
- cache keys include source and response/upstream version inputs;
- an exact CosIng mirror result becomes supported until officially corroborated;
- official corroboration upgrades only the supported fields it actually confirms;
- official/mirror disagreement becomes a visible conflict or verified official replacement with discrepancy warning, according to field rules;
- argan and apricot fixtures retain multiple source identifiers and exact plant-part identity;
- GSRS UNII is stored as an identifier and never used automatically as label text;
- ordinary US declaration proposals remain distinct from EU/INCI values;
- EU and US colour declarations differ correctly and a bare CI value is rejected for US output;
- colour certification and application restrictions remain distinct from declaration names;
- partial stage failure retains completed stage results;
- retry gaps dispatches only failed or unresolved stages;
- AI is not called until deterministic lookup and conflict evaluation complete;
- normal AI input contains normalized facts rather than uncontrolled raw search results;
- source-backed fields expose exact evidence in the review plan;
- safe batch approval excludes conflicts, unresolved required fields, and validation failures;
- Admin-edited proposals are revalidated and retain edit attribution;
- reviewed existing values and secondary CAS/EC identifiers survive default and explicit replacement flows;
- stale fingerprints block apply;
- unchanged apply performs no catalogue write;
- per-ingredient apply is atomic and idempotent;
- private workspace ingredients cannot enter managed enrichment;
- categories, subcategories, and CosIng functions continue using shared localized vocabulary;
- translated guidance preserves the approved English field boundaries.

Live source contract checks may run separately from the normal test suite. They must not make routine tests depend on third-party availability.

## Acceptance Criteria

- An Admin can select one or many minimally entered platform ingredients and start a batch without using the command line.
- Argan oil and apricot kernel oil produce exact reviewable EU identity candidates, multiple source identifiers where applicable, FDA UNIIs, market-specific declaration candidates, and CosIng function candidates with field-level evidence.
- The normal flow uses structured source calls before one bounded AI editorial call.
- A JavaScript-only official search interface does not prevent the structured EU lookup from producing a supported proposal.
- Officially corroborated and mirror-supported values are visibly distinguished.
- A source conflict never disappears into a single high-confidence ingredient result.
- US declaration names, FDA UNIIs, CAS/EC values, and EU/INCI names are stored as distinct concepts.
- EU and US colour output uses their respective declaration rules and never treats a CI number as proof of FDA certification or authorization.
- Batch review emphasizes exact field evidence instead of unrelated consulted URLs.
- An Admin can bulk-approve warning-free safe items and individually resolve exceptional items.
- Retrying an item repeats only the incomplete stages.
- Applying an approved item preserves reviewed work, retains secondary identifiers, and leaves an inspectable provenance trail.
- The pipeline can be rerun for newly entered development ingredients without rebuilding or manually copying one JSON file per ingredient.

## Implementation Planning Boundary

The implementation plan must begin with the migrations for `pubchem_sid` and `cosing_ref`, identifier evidence, market-label provenance, function-assignment provenance, and resumable batch stage state. It must then extend the current colourant-only market-label validation and resolver behavior to ordinary ingredients. These changes must reuse the existing enrichment batch and identity modules and must not create a parallel ingredient identity store.

The plan must sequence deterministic source adapters and fixtures before prompt changes. The current OpenAI web-search-first client remains available only as a controlled fallback until the new adapters are proven, then the normal path must stop depending on general web search.
