# Canonical Soap Alkalis Design

**Date:** 2026-08-25

## Goal

Simplify soap alkali handling so formulas, costing, and Production Bench all refer to the same two platform materials while keeping KOH purity visible wherever it affects a maker's work.

## Decision

Workspace users do not author, duplicate, or replace soapmaking alkali identities.

Koskalk owns two protected canonical ingredients:

- Sodium hydroxide (NaOH), catalog key `CH1`.
- Potassium hydroxide (KOH), catalog key `CH3`.

These records are material identities. Formula settings describe how those materials are used.

KOH 90% and KOH 100% are therefore not separate `Ingredient` records. The selected purity remains the formula calculation setting `koh_purity_percentage`, and the established calculation continues converting pure-KOH demand into the actual mass to weigh.

## Why This Model

Allowing a workspace to create another sodium hydroxide or potassium hydroxide record makes a calculated formula fact depend on catalogue ordering. The current costing resolver can select the last accessible record in a subcategory, so an unrelated duplicate may silently become the priced material.

Canonical keys remove that ambiguity. They also align Workbench costing with Production Bench, which already maps calculated NaOH and KOH to `CH1` and `CH3`.

Purity is a usage property, not a new chemical identity in the current product. Keeping it in formula context avoids duplicate ingredients, duplicate prices, and a grade-selection workflow that the application does not otherwise support.

## Workspace Authoring Boundary

The Soapmaking alkalis category is absent from every workspace ingredient-authoring category selector, including quick creation inside blend composition.

The service layer enforces the same boundary so a crafted request cannot:

- create a workspace ingredient in the Soapmaking alkalis category;
- change an existing workspace ingredient into that category; or
- duplicate a platform soapmaking alkali into a workspace.

The Admin ingredient catalogue keeps its current authority to curate the canonical platform records.

Existing workspace alkali duplicates are not automatically deleted. Development duplicates may be removed manually after the change is verified. Production currently contains no user-authored alkalis, so this rollout does not need a destructive cleanup migration or command.

## Canonical Resolution

A shared application service owns the mapping:

| Formula lye type | Canonical catalog key | Ingredient |
| --- | --- | --- |
| `naoh` | `CH1` | Sodium hydroxide |
| `koh` | `CH3` | Potassium hydroxide |

Workbench costing and Production Bench use this resolver. Neither feature may select an alkali by category order, subcategory order, owner priority, record ID, or the last accessible row.

The resolver accepts only platform-owned, active records with the expected catalog key. A missing canonical material is a validation failure; the application must not silently fall back to a workspace duplicate.

## Costing Contract

Costing keeps the existing synthetic `lye_alkali` rows and stable NaOH-then-KOH position order.

The ingredient identity used for the price is always `CH1` or `CH3`. A workspace therefore has one current material price for NaOH and one for KOH. Changing KOH purity does not create another ingredient, current-price record, or costing-row identity.

The calculated KOH row uses the already purity-adjusted `koh_to_weigh` mass. The visible name reacts to the current formula setting:

- `Potassium hydroxide (KOH 90%)`
- `Potassium hydroxide (KOH 100%)`

NaOH remains `Sodium hydroxide` because the current formula model has no separate NaOH purity setting.

This change deliberately does not introduce grade-specific default prices. A user can still enter a formula-specific price. If real purchasing or inventory use later requires multiple KOH grades with distinct costs or lots, grade must be modeled explicitly rather than reintroducing ambiguous ingredient duplicates.

## Production Bench Contract

When a production snapshot is created, its KOH formula line:

- links to canonical ingredient `CH3`;
- stores the purity-adjusted planned mass already returned by the soap calculation;
- freezes the selected `koh_purity_percentage` in `formula_context_snapshot`; and
- freezes a `subject_name_snapshot` such as `Potassium hydroxide (KOH 90%)`.

The purity context key is present for KOH and dual-lye soaps. It is omitted for NaOH-only soap and cosmetics because it has no effect there.

The formula-line name flows into calculated stock requirements, stock preparation, production detail, availability previews, actual-use entry, and completion records through the existing snapshot pipeline. Those consumers continue reading the snapshot name; none should query the current formula or current ingredient name to reconstruct purity later.

KOH 100% receives the same explicit treatment. Operators should never have to infer whether a bare `KOH` label means 90% or 100%.

## Persistence and Historical Behavior

No database schema migration is required:

- recipe versions already store `koh_purity_percentage` in `calculation_context`;
- production runs already store JSON `formula_context_snapshot`;
- production formula lines and requirements already store `subject_name_snapshot`; and
- costing rows already reference an ingredient and synthetic phase position.

New production snapshots and any explicitly run legacy snapshot backfill use the updated builder. Existing completed production snapshots are immutable and are not rewritten. The stated production environment has no alkali production history requiring a one-off backfill.

## Localization

English source strings remain in `lang/en`. The database-backed interface translation catalogue contains reviewed values for every supported locale.

The KOH label retains the invariant chemical token and placeholders:

`:name (KOH :purity%)`

The localized ingredient name supplies `:name`; the selected formula value supplies `:purity`.

## Integrity Rules

- `CH1` and `CH3` are the only soap-calculation alkali ingredient identities.
- Workspace authoring cannot create, reclassify, or duplicate a soapmaking alkali.
- Costing never depends on accessible-record ordering.
- KOH purity remains a formula setting and is not represented by another ingredient row.
- The KOH mass used by costing and production remains the calculation's purity-adjusted mass.
- A production freezes both KOH purity and the operator-facing purity label.
- Production displays never rebuild purity from a live recipe after snapshot creation.
- Missing canonical alkalis fail explicitly instead of falling back to a duplicate.
- No rollout step deletes production or workspace data automatically.

## Out of Scope

- production data cleanup or destructive migrations;
- separate KOH 90% and KOH 100% ingredient records;
- supplier-grade, certificate-of-analysis, or lot-level purity modeling;
- NaOH purity selection;
- a new alkali production-bench setup screen;
- changing the soap calculation formula;
- retroactively renaming completed production snapshots; and
- automatically deleting existing development duplicates.

## Acceptance Scenarios

1. A workspace user cannot select Soapmaking alkalis when creating an ingredient or a quick blend component.
2. A crafted create/update request for that category and an attempt to duplicate platform NaOH/KOH are rejected.
3. A workspace duplicate with the same alkali category/subcategory cannot replace `CH1` or `CH3` in costing.
4. NaOH costing uses `CH1`; KOH costing uses `CH3`; dual lye uses both in stable order.
5. A KOH 90% formula shows `Potassium hydroxide (KOH 90%)` in costing and costs the purity-adjusted mass.
6. Changing the formula to KOH 100% updates the costing label to `Potassium hydroxide (KOH 100%)` without creating another ingredient.
7. A KOH or dual-lye production freezes `koh_purity_percentage` and the explicit KOH label in its formula snapshot.
8. Production formula, stock requirement, preparation, detail, and actual-use surfaces all retain the frozen KOH purity label.
9. Deleting or renaming a source formula after planning does not change the production's KOH purity label.
10. Missing `CH1` or `CH3` prevents costing/production from silently choosing another alkali.
