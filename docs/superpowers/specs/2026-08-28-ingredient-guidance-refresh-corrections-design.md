# Ingredient Guidance Refresh Corrections Design

**Date:** 2026-08-28

**Status:** Approved

**Corrects:**

- `2026-08-28-ingredient-guidance-refresh.md`
- commit `56726688` (`feat: separate ingredient guidance refresh workflow`)

## Goal

Correct the guidance-refresh workflow so translation freshness, reviewer provenance, evidence approval, retry resumption, and batch completion remain truthful, then align the implementation with the repository's Action, localization, collection, and service-boundary conventions.

## Scope and Order

The correction ships in two phases within one implementation plan:

1. correctness and data integrity;
2. structural cleanup.

Correctness changes land first in independently tested commits. Structural work follows only after the behavioral suite is green. No database migration or dependency change is required.

## Correctness Invariants

### English edits and localized guidance

English guidance remains the canonical localization source. A guidance proposal records whether the reviewer changed English and which localized rows the reviewer changed.

When English is unchanged during review:

- untouched generated localizations apply as `ai_generated` and receive the current canonical-source fingerprint;
- reviewer-edited localizations apply as `reviewer_edited`, clear the AI prompt version, and receive the current fingerprint.

When English is changed during review:

- unedited localized proposals are not applied, because they were generated from the superseded English proposal;
- existing localized database values and their fingerprints remain untouched and therefore become visibly outdated after English is saved;
- a locale explicitly edited by the reviewer may apply as `reviewer_edited` against the new English fingerprint;
- other locales remain untouched and outdated.

This preserves the original decision that editing English and French together makes French current while leaving other locales outdated.

### Revalidation without a text diff

A localization-only run is a reviewable metadata operation even when the provider returns text identical to the stored translation. The change plan distinguishes:

- `replace` when localized text changes;
- `revalidate` when stale localized text is regenerated unchanged but its source fingerprint or prompt provenance needs renewal.

Both decisions produce a reviewable item. Applying an approved `revalidate` decision updates only the locale's source fingerprint, origin, and prompt version. It does not alter identity, localized names, or guidance text.

### Guidance evidence

New or changed guidance evidence and prompt-version metadata are part of the review plan. If prose is unchanged but the approved evidence envelope differs from the stored envelope, the item is reviewable as an evidence change. Evidence is never persisted from an automatically `Unchanged` item and never bypasses human approval.

After approval, applying the item persists the evidence envelope even when no prose field changes.

### Mode-aware retries

Guidance batches resume only their own stages:

- `guidance_refresh`: authoring, localization, validation;
- `guidance_localization`: localization, validation.

Retry starts at the first absent, failed, or unresolved stage in that mode. Completed authoring is reused after a localization failure. Guidance retry never asks the full-enrichment stage list for `IdentityPreparation`, and the processor reads completed guidance-stage payloads before making provider calls.

The persisted batch mode is the only mode source of truth. The redundant serialized `localizationOnly` job argument is removed.

### Apply audit and aggregate state

After each approved guidance item is processed, the item records the applying Admin and application timestamp consistently with full enrichment. When no active, failed, stale, or approved items remain and all reviewable rows are applied, unchanged, rejected, or cancelled, the guidance batch transitions to `Applied`. It must not fall back to `ReadyForReview` after a successful apply.

## Architecture

### Guidance change planner

Introduce one focused planner responsible for comparing the current ingredient, the guidance result, stored translation freshness, persisted evidence, and review edits. It returns the standard `changed`, `decisions`, and `effective` structure.

The processor and proposal-edit workflow both call this planner. Decision paths remain guidance-specific and cannot carry identity fields. Translation decisions include their locale and whether the operation is `replace` or `revalidate`.

### Proposal review service

Keep `EditIngredientGuidanceProposal` and `ApproveIngredientGuidanceProposal` as thin command Actions:

1. authorize the actor;
2. delegate to a container-resolved guidance proposal review Service.

The Service owns row locking, stale checks, allowed-field validation, result validation, edit auditing, planning, approval persistence, and batch refresh. The Actions contain no transaction or proposal transformation logic.

### Translation synchronization

Extend translation synchronization with explicit per-locale write intent rather than one origin for the entire submitted array. Each changed locale carries:

- locale;
- intended origin;
- optional prompt version;
- whether identical content is being authoritatively revalidated.

Unmentioned and unchanged rows preserve their existing metadata. Reviewer-edited rows receive reviewer provenance. AI-generated or revalidated rows receive AI provenance and the localization prompt version. When English was edited in review, unedited generated locale rows are omitted from synchronization so their existing fingerprints remain stale.

### Resumable guidance stages

Move guidance stage execution behind a small stage-aware workflow capability that mirrors the existing full-pipeline `runStage` behavior. It loads completed stage data, executes only an incomplete stage, stores success, and records a safe failure on the failing stage. Retry-stage selection receives the batch mode and examines only the stages valid for that mode.

### Shared OpenAI structured transport

Extract the common no-web Responses API transport used by metadata editorial, English guidance authoring, and guidance localization. The transport owns authentication, timeouts, retries, `store => false`, strict structured-output request construction, response-status validation, output-text extraction, usage accounting, request identifiers, and safe provider errors.

Domain clients continue to own their prompt, schema name, schema shape, and decoded domain response. Gap research remains separate because it has web-tool behavior.

### Localization and collection conventions

Move every new user-visible validation, warning, and failure message into the appropriate `lang/en/ingredient_enrichment.php` or `lang/en/ingredient_enrichment_admin.php` group.

Use collection pipelines for proposal merging, changed-path derivation, and plan construction. Retain `foreach` only where validation accumulates errors or persistence causes side effects.

## Data Flow

### Guidance generation

1. Build the no-web guidance context and reusable evidence snapshot.
2. Resume or execute English authoring when the mode requires it.
3. Resume or execute localization for the required locales.
4. Validate the strict result.
5. Build prose, revalidation, and evidence decisions through the shared planner.
6. Mark the item `Ready`, `Warning`, or `Unchanged` based on all reviewable decisions, not text differences alone.

### Review and apply

1. The edit Action authorizes and delegates.
2. The review Service locks the item and ingredient, verifies freshness, validates allowed guidance fields, records English and locale edits, rebuilds the plan, and clears prior approval.
3. The approve Action authorizes and delegates validation and approval.
4. Apply locks the approved item and ingredient and verifies the source fingerprint.
5. Save English only for a full guidance refresh.
6. Synchronize only locales whose generated or reviewer-authored proposal is valid for the final English source.
7. Persist approved evidence and prompt metadata.
8. Record item audit fields and transition the aggregate batch when complete.

## Error Handling

- A source-fingerprint mismatch marks the item stale before provider, edit, approval, or apply work.
- A failed provider stage records that exact guidance stage so retry resumes correctly.
- Unknown proposal keys remain rejected rather than ignored.
- Reviewer edits cannot silently inherit AI provenance.
- Evidence-only and revalidation-only changes remain human-review gated.
- Applying one failed item does not prevent independent approved items in the batch from completing.

## Testing Strategy

Add focused Pest coverage before each behavior change:

- editing only English leaves existing locale rows untouched and outdated;
- editing English and French makes French reviewer-authored/current and leaves German outdated;
- editing only French records reviewer provenance without touching other locales;
- an identical localization result produces a reviewable `revalidate` decision and clears staleness only after approval;
- localization failure followed by retry reuses completed English authoring and calls localization once more;
- evidence-only differences produce reviewable work and persist only after approval;
- guidance apply records the actor and leaves a completed batch in `Applied`;
- Actions delegate transaction and domain behavior to the proposal review Service;
- user-visible guidance validation paths resolve translated strings;
- all three no-web OpenAI clients retain strict schema, retry, accounting, and no-tools behavior through the shared transport;
- the guidance job obtains mode solely from its batch item.

Run the narrow behavior tests after each task, then the complete enrichment, translation, retry, and Filament review suites. Finish with Pint, FilaCheck, `git diff --check`, and a graph refresh.

## Explicitly Deferred

- New structured fields for cosmetic or soapmaking usage percentages.
- New guidance evidence research or source-domain policy.
- Automatic localization without review.
- Changes to identity, taxonomy, identifiers, declarations, aliases, or soap INCI naming.
- Broad refactoring of gap-research transport or unrelated enrichment stages.
