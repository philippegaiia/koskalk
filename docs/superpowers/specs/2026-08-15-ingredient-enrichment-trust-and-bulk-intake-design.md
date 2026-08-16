# Ingredient Enrichment Trust and Bulk Intake Design

**Date:** 2026-08-15

**Status:** Approved

**Extends:**

- `2026-08-13-platform-ingredient-batch-enrichment-and-vocabulary-localization-design.md`
- `2026-08-14-hybrid-ingredient-enrichment-source-pipeline-design.md`

## Supersession

Where this document conflicts with either earlier design, this document supersedes it for the intake and enrichment workflow. In particular, it supersedes:

- the `Approve safe batch` action;
- any model that combines source tier, field confidence, and reviewer decision into one status;
- the earlier prohibition on AI-proposed sodium and potassium saponified declaration names.

The AI-proposal exception applies only to visibly unverified, review-gated soap declaration proposals described below. Deterministic source stages must still never present a constructed name as a CosIng or official glossary result.

After this design is approved and before implementation begins, the committed ingredient-enrichment rule must be updated to match this boundary. A design document does not silently override repository rules used by implementation agents.

## Goal

Make it practical to build a large platform catalogue from incomplete source lists without weakening the integrity of active ingredients. An Admin can paste or upload dozens of ingredient names, optionally provide known INCI names and batch context, run the existing source-backed enrichment pipeline, edit the proposals using their own knowledge, and promote only reviewed rows into the platform catalogue.

This design also corrects the trust model exposed by the first enrichment implementation. Source support and human approval are independent facts. Automated research proposes; the human reviewer has the final decision.

## Product Boundary

An active platform ingredient continues to require the canonical fields and valid classification expected by the rest of the application. Category and subcategory are not made nullable in the real catalogue.

Incomplete bulk input lives in a separate intake area. Intake rows cannot appear in formulas, ingredient search, declarations, compliance evaluation, or user-facing catalogue pages. Promotion is the only path from an intake row to an active platform ingredient.

The MVP supports EU and US enrichment. The intake format remains market-neutral so future jurisdiction packs can enrich the same staged identity without changing the intake schema.

## Settled Decisions

- Add a separate ingredient-intake staging layer instead of weakening `ingredients` invariants.
- Support both pasted tabular data and CSV upload.
- Require at least one of current/common name or INCI name per row; allow either or both.
- Treat a supplied INCI as reviewer-provided identity input. Research may propose a correction, but it never silently replaces the supplied value.
- Allow optional batch-level context, initially an ingredient-family hint such as `colourants`.
- A family hint guides source selection and research; it is not a verified category assignment.
- Run deterministic sources first, then the controlled approved-site gap-research stage for unresolved fields selected by the batch workflow.
- Keep every row independent so one failure does not prevent other rows from reaching review.
- Keep source tier, field confidence, value provenance, and reviewer decision as separate dimensions.
- Permit the reviewer to edit or supply values using external sources and professional knowledge.
- Require human approval before an intake row can be promoted.
- Generate the platform `catalog_key` during promotion, not during raw intake.
- Keep category and subcategory compulsory at promotion.
- Detect duplicates without turning similarity review into a blocking merge workflow.
- Remove the ambiguous `Approve safe batch` action. Approval remains an explicit per-item human decision; applying approved items may remain a batch action.
- Human approval may accept a structurally valid proposal whose source confidence remains conflicting or unresolved. Confidence and warnings inform the reviewer; they do not overrule the reviewer's decision. Missing or incompatible catalogue-required values still fail validation.

## Intake Experience

### Batch creation

The Admin opens **Ingredient intake**, creates a batch, and chooses one input method:

1. paste rows into a small spreadsheet-like input; or
2. upload a CSV file.

The two supported columns are:

- `current_name`
- `inci_name`

At least one column must be non-empty on every accepted row. Column headings are recognized case-insensitively through a small documented alias set. Blank rows are ignored. The preview reports malformed rows before anything is queued.

A batch may contain one or more accepted rows. Seventy rows is a capacity acceptance case, not a minimum batch size.

Example:

```text
current_name	inci_name
Coconut oil	Cocos Nucifera Oil
Red pigment	CI 77491
Argan oil
	Prunus Amygdalus Dulcis Oil
```

Batch metadata contains a human-readable name, optional notes, and an optional family hint. For a list of 60–70 colours, the reviewer may select `Colourants`; the research pipeline then prioritizes CI identity and EU/US colour declaration sources without pre-approving a category.

### Intake preview

Before starting research, the preview shows:

- accepted rows;
- missing-name errors;
- exact duplicates within the uploaded batch;
- exact matches against the current platform catalogue;
- non-blocking possible catalogue matches.

The reviewer can remove rows or correct cells inline. The original submitted text remains in the intake audit after normalization.

## Duplicate Handling

Duplicate assistance is intentionally lightweight.

### Exact normalized match

An exact normalized match on a supplied INCI or current name is prominent and pauses research for that row until the reviewer chooses one outcome:

- enrich the existing platform ingredient; or
- confirm that the intake row represents a distinct material.

Confirmation is recorded once on the intake item. Other rows in the batch continue normally while an exact-match row waits. Choosing `enrich existing` creates a fresh subject snapshot from the linked ingredient and its reviewed values. Choosing `distinct material` creates the intake subject snapshot. Dispatch and apply both enforce that the resolution matches the subject snapshot; changing the resolution invalidates completed research for that row and requires a fresh run.

### Similar match

A fuzzy or synonym-like match displays a discreet warning and links to the possible existing ingredient. It does not block research, approval, or promotion. The reviewer may dismiss it or select the existing ingredient as the intended target.

Similarity never merges records automatically. Parenthetical text, plant part, chemical form, salt, extraction, colour index, and manufactured derivatives are identity-bearing unless exact source evidence establishes equivalence.

## Data Model

### Intake batch

Add an intake batch record containing:

- public identifier;
- name and optional notes;
- input method and original file metadata when applicable;
- optional family hint;
- creator and timestamps;
- aggregate status and row counts.

Uploaded artifacts use private storage. Deleting an intake batch deletes its unneeded private artifacts after the database transaction succeeds.

### Intake item

Each row stores:

- intake batch;
- original row number;
- original and normalized current name;
- original and normalized INCI name;
- row status;
- duplicate candidates and reviewer resolution;
- linked existing ingredient when the reviewer chooses enrichment of an existing record;
- promoted ingredient when a new record is created;
- failure code and safe diagnostic detail;
- approval and promotion audit metadata.

An intake item remains immutable as submitted after research starts. Reviewer edits belong to the enrichment proposal and its audit trail rather than rewriting the original input.

### Enrichment subject

Introduce a small enrichment-subject snapshot contract so the research pipeline accepts either:

- an existing platform ingredient; or
- an ingredient intake item.

An enrichment batch item targets exactly one subject. Existing ingredient enrichment keeps its present behavior. Intake enrichment supplies the entered names, batch hint, duplicate context, and empty canonical state through the same normalized research stages.

The persistence design must enforce the exclusive target invariant at the database and application layers. Research services consume the subject snapshot instead of branching on Eloquent models throughout the pipeline.

Concretely, `ingredient_enrichment_batch_items` gains a nullable `ingredient_intake_item_id` foreign key. Its existing `ingredient_id` remains nullable, and `catalog_key` becomes nullable because a new intake item has no platform key before promotion. A database check constraint requires exactly one of `ingredient_id` and `ingredient_intake_item_id` to be non-null. Separate unique constraints cover `(ingredient_enrichment_batch_id, ingredient_id)` and `(ingredient_enrichment_batch_id, ingredient_intake_item_id)`. The UI uses the intake identity as the row label until promotion rather than inventing a catalogue key.

Subject foreign keys restrict deletion so the enrichment audit cannot silently lose the subject required by this invariant. Deleting a platform ingredient with enrichment or intake history is blocked with an actionable dependency message until the related audit batch is deleted. Migration rollback restores the earlier direct-enrichment `nullOnDelete` behavior.

Research results identify their subject explicitly. Existing-ingredient results retain their catalogue key; intake results have no catalogue key before promotion and instead carry the intake subject type and public identifier. Validation cross-checks this subject reference and fingerprint against the batch item rather than requiring an invented catalogue key.

## Research and Evidence Model

### Source tier

Every evidence record uses one source tier:

- **Official** — a primary government or regulatory source.
- **Official mirror** — a structured mirror that attributes its data to an official upstream dataset.
- **Approved secondary** — an allowed non-government source suitable for the proposed field.
- **Reviewer supplied** — a source or professional judgement entered by the human reviewer.

Cached results retain the tier and provenance of the original response. Reading a value from cache never creates a new source or retrieval claim.

### Field confidence

Every material proposed value retains the existing confidence vocabulary:

- **Verified** — exact applicable evidence supports the exact value and identity.
- **Supported** — accepted evidence supports the value without exact official corroboration.
- **Conflicting** — accepted evidence disagrees or identifies a materially different substance, plant part, salt, extraction, or chemical form.
- **Unresolved** — no sufficiently reliable value was found.

`Conflicting` remains distinct from `Unresolved` throughout storage, validation, batch summaries, and review. It is the more urgent review signal because evidence exists but disagrees. A field's confidence cannot exceed the evidence supporting that exact field value.

### Reviewer decision

Source tier and field confidence remain separate from the review decision:

- **Pending**
- **Approved**
- **Edited and approved**
- **Rejected**

Human approval establishes that the value is accepted for this catalogue. It does not relabel supporting evidence as official or upgrade field confidence.

`Edited and approved` is a derived review label, not a separate stored workflow status. An approved item displays it when the existing edit audit contains `edited_fields`, `edited_by_user_id`, or `edited_at`.

Rejection is a terminal workflow action. Add `Rejected` to the enrichment item status and record `rejected_by_user_id`, `rejected_at`, and an optional reviewer reason. Rejected items remain auditable, are excluded from apply, and may be explicitly returned to review rather than silently retried.

### Reviewer and AI proposals

Some useful names, especially sodium and potassium saponified declarations, may not be located in the deterministic sources. Their value provenance is recorded independently:

- **Source confirmed**
- **AI proposed**
- **Reviewer supplied**
- **Unresolved**

The AI may propose NaOH and KOH saponified declaration names using the verified base identity and available naming evidence. Such a proposal is visibly marked `AI proposed`, carries its reasoning and any supporting links, and is never presented as an official CosIng or glossary result. It becomes catalogue data only after explicit review. Sodium and potassium values remain independently editable and approvable.

## Corrected Research Pipeline

Each intake or existing-ingredient subject passes through these stages:

1. **Identity preparation** — retain the supplied strings, normalize only formatting that cannot change identity, and collect existing reviewed values when applicable.
2. **US identity lookup** — score all GSRS/openFDA candidates using exact identifiers, normalized exact names, plant part, chemical form, and conflicts. The first search result has no privileged status. When the intake row lacks INCI, an unambiguous exact identity candidate may supply an INCI search value for the following EU stage without silently replacing reviewer input.
3. **EU structured lookup** — query the approved CosIng mirror using the strongest exact names and identifiers available after identity lookup, preserving every plausible record rather than selecting a derivative by textual similarity.
4. **EU official corroboration** — corroborate the exact proposed value against the exact official glossary or regulatory entry. Evidence for a soap salt, derivative, or related plant part cannot verify the base ingredient.
5. **Market declaration lookup** — build EU and US declaration proposals from the matched identity and the market-specific naming rules. A US label is separate from UNII and EU INCI.
6. **Preliminary conflict evaluation** — compare the deterministic identity and declaration candidates before any model call. Supply the resulting conflicts and unresolved questions to gap research and editorial work.
7. **Controlled gap research** — search only the configured approved websites for unresolved fields. Results remain supported or unresolved unless an exact official record is matched.
8. **Editorial pass** — write concise useful guidance and translations from normalized research facts, including the preliminary conflict signal. Editorial generation cannot manufacture deterministic identity evidence.
9. **Final validation and confidence** — correlate every field confidence with the evidence supporting that exact field value, retain valid partial proposals, identify invalid fields precisely, and make independent rows reviewable or retryable.

These are logical pipeline steps, not a mandate to rename persisted research-stage keys. Preserve the existing stable keys where their meaning remains accurate: market declaration continues under `us_declaration`, preliminary conflict evaluation under `conflict_evaluation`, controlled gap research remains an auditable substep of `ai_editorial`, and final checks remain under `validation`. Existing batch rows do not require a stage-key data migration solely because this document uses clearer product-language labels.

Identity normalization is conservative. Removing punctuation or parenthetical text is allowed only when it preserves token boundaries and a source-backed identity matcher confirms equivalence. It cannot concatenate or merge otherwise distinct names.

Legacy fallback values remain visible as legacy/unverified until corroborated. Their presence cannot upgrade a proposal to official or source-confirmed status.

The normalized research context contains a `research_family` used only for adapter and prompt selection. A verified existing category takes precedence; otherwise an intake family hint such as `colourants` may populate it. `research_family = colourants` routes US declaration research through the FDA colour-additive stage even though the intake row has no approved category. This routing context is never persisted as the ingredient category and never satisfies promotion classification requirements.

## Review and Promotion

The review page presents the input, current value when enriching an existing ingredient, proposed value, source tier, field confidence, value provenance, exact supporting evidence, and reviewer decision together.

The reviewer can:

- edit any proposed field;
- add a value from their own source or knowledge;
- approve the item;
- reject it;
- retry only failed or unresolved stages;
- resolve an exact duplicate;
- leave the item pending.

Approval saves successfully, closes its modal, and immediately updates the visible item state. It does not mutate the catalogue.

Approval always revalidates the current proposal after reviewer edits. Schema, category/subcategory compatibility, field evidence, market declarations, identifiers, and promotion requirements are checked against the edited values before the item can enter the approved state. An edit invalidates any earlier approval result.

Field confidence, warnings, and unresolved optional values do not block individual human approval. Approval is blocked only by an invalid subject/fingerprint, malformed data, or a missing/incompatible catalogue-required value. The former `safeApprovalBlockers` policy is removed with `Approve safe batch`.

**Apply approved** processes every approved item independently:

- an existing-ingredient subject uses the current enrichment apply services;
- a new intake subject creates the platform ingredient, generates its catalogue key, applies the approved proposal through domain services, records the promotion link, and activates it only when all required catalogue invariants pass.

Promotion requires a reviewed display identity, category, compatible subcategory where required, and any other invariant already required for platform ingredients. Missing optional enrichment fields do not block promotion; they remain visible gaps for later passes.

A promoted intake ingredient has already completed its human review. Promotion therefore records `taxonomy_source = admin_reviewed_enrichment`, `taxonomy_reviewed_by_user_id` as the approving reviewer, and `taxonomy_reviewed_at` from the approval/application audit. It sets `requires_admin_review = false`. Optional unresolved fields remain visible in the enrichment audit and may be enriched later; they do not trigger an unexplained second review cycle.

Promotion and canonical writes occur in one per-item transaction. A failure leaves the intake item approved but unpromoted and retryable. Reapplying a promoted item is idempotent.

## Identifier and Provenance Safety

Identifiers remain multi-valued and are reconciled by scheme plus normalized value. Applying a proposal preserves existing identifier evidence unless the proposal explicitly supplies a replacement for that exact evidence record. A malformed, absent, or partial evidence payload cannot erase reviewed evidence.

Official corroboration upgrades only the exact identifier or field it supports. Evidence confidence and field confidence must agree; an official source attached to a different value cannot make the proposed value official.

Imported market declarations retain import provenance without inventing a human review timestamp. Human approval metadata is recorded only when the reviewer actually approves or edits the declaration.

## Operational Behavior

- One queued job processes one subject, preserving row-level failure isolation.
- Intake capacity is configured independently from direct existing-ingredient enrichment. Supporting at least seventy intake rows does not silently widen the direct-selection batch limit.
- Deterministic stages run before paid AI work.
- The batch records structured-source calls, gap-search calls, token use, model, reasoning effort, and attempts.
- Completed stages are reusable when their input fingerprint and source version remain current.
- Retrying one failed item does not rerun successful rows.
- Queue workers are long-running infrastructure; the UI never instructs the reviewer to restart a worker for every batch.
- Failure codes identify the failing adapter or stage instead of exposing only the exception class.
- Unexpected failures are reported server-side while the UI shows safe actionable context.
- Batch or intake deletion removes database records transactionally and deletes private artifacts only after commit.

## MVP Scope

### Included

- Ingredient intake batches.
- Paste and CSV input with current name and/or INCI name.
- Optional family hint, initially including colourants.
- Lightweight duplicate assistance.
- Existing hybrid EU/US enrichment stages for intake subjects.
- Controlled approved-site gap research.
- AI-proposed, visibly unverified sodium and potassium saponified names.
- Field-level source tier, confidence, provenance, and separate reviewer decision.
- Proposal editing, individual approval, targeted retry, and batch apply.
- Explicit rejection with reviewer audit metadata.
- Promotion to a valid platform ingredient with generated catalogue key.
- Row-level audit, failure isolation, cost metrics, and private artifact cleanup.

### Deferred

- Automatic catalogue publication without human review.
- Automatic fuzzy duplicate merging.
- General-purpose spreadsheet mapping beyond the two intake identity columns.
- Markets other than EU and US.
- Complete legal authorization decisions for colourants or other restricted substances.
- Specialist fatty-acid, allergen, IFRA, and restriction enrichment passes.
- Production catalogue deployment and synchronization.

## Testing Strategy

Focused Pest coverage must prove:

- paste and CSV accept current name only, INCI only, or both;
- intake accepts a single row, ten rows, and at least seventy rows without changing workflow semantics;
- a row with neither identity value is rejected before queueing;
- original input is retained while normalized search values remain separate;
- an optional colourant hint routes FDA colour research without pre-approving or persisting classification;
- exact duplicates require a reviewer resolution before research dispatch and promotion;
- changing an exact-duplicate resolution invalidates stale research and forces a fresh subject snapshot;
- similar matches warn without blocking research or promotion;
- an intake row never appears in active ingredient queries before promotion;
- one failed row does not prevent unrelated rows from reaching review;
- an enrichment batch item targets exactly one existing ingredient or intake item;
- the database rejects batch items with both targets or neither target;
- intake-target batch items do not require a catalogue key before promotion;
- exact EU evidence cannot verify a different salt, derivative, or plant part;
- GSRS candidate selection evaluates all candidates and rejects material conflicts;
- identity normalization cannot concatenate names by removing parenthetical boundaries;
- field confidence cannot exceed the evidence supporting that exact value;
- official-mirror and legacy values remain visibly distinct from official corroboration;
- AI-proposed NaOH and KOH names remain visibly unverified and require human approval;
- reviewer edits are retained as `Edited and approved` without falsifying source tier or field confidence;
- `Edited and approved` is derived from the existing edit audit rather than stored as a duplicate item status;
- rejected items retain reviewer audit metadata and cannot be applied;
- approving an edited proposal revalidates all edited values and invalidates any earlier approval result;
- approval updates the item state without mutating the catalogue;
- promotion generates a catalogue key and requires valid classification;
- promotion records the approving reviewer as the taxonomy reviewer and does not require an automatic second Admin review;
- promotion applies approved relationships and evidence through domain services;
- malformed or absent identifier evidence cannot delete reviewed provenance;
- failed promotion is atomic, leaves the approved intake item retryable, and creates no partial ingredient;
- promoted items apply idempotently;
- deleting a batch removes private artifacts only after the database deletion commits;
- the existing direct enrichment workflow for platform ingredients continues to pass unchanged behavior tests.

## Completion Criteria

The design is implemented when an Admin can paste a batch of one or more ingredients from any supported family, optionally include known INCI values, enrich the rows independently, review and edit every proposal, resolve only genuine duplicate risks, and promote approved rows into valid platform ingredients without exposing incomplete intake data anywhere in the application. The same workflow must handle at least 70 rows in one batch as a capacity acceptance case. A colourant batch is one example, not a scope limitation.
