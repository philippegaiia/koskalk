# Production Run Formula Snapshot Independence Design

## Goal

Make each Production Bench production self-contained without severing its useful relationship to the current product. A production keeps an optional live link to its `Recipe`, owns the exact formula and planning facts used for that batch, and no longer forces a complete `RecipeVersion` to be retained indefinitely.

This design supersedes the permanent `RecipeVersion` pin described in the planning sections of [Production Planning and Execution Design](2026-08-04-production-planning-execution-design.md). It does not link or merge Production Bench `ProductionRun` records with the separate Basic `ProductionBatch` snapshot product.

## Problem

The current production aggregate stores both:

- copied, scaled `ProductionRequirement` rows; and
- mandatory `recipe_id` and `recipe_version_id` foreign keys using `restrictOnDelete`.

That creates two competing historical sources. Formula version pruning fails when a referenced version is deleted, formula editing can surface database foreign-key errors, and product deletion must be blocked even though much of the production data has already been copied.

The copied requirements are not yet a complete formula snapshot. For soap, they contain explicit oils, additions, fragrance, and packaging, but calculated NaOH, KOH, and water are absent. They are also operational stock-demand rows: reservations belong to them. Treating the same table as both the printable formula and inventory demand would overload its meaning and make calculated, non-stock-linked components interfere with reservation completeness.

## Chosen Model

### Recipe relationship

`ProductionRun.recipe_id` remains the normal product relationship. It supports product-level production history, filtering, reporting, and navigation back to the Workbench.

The relationship becomes nullable with `nullOnDelete` as a final historical safety mechanism. Normal removal of a product that has production history archives the product instead of hard-deleting it, so the relationship ordinarily remains intact.

### Formula source

The latest accessible published `RecipeVersion` is an input used only when a production is created. It is not part of the durable production identity.

The production stores the source version number for human audit, but the `RecipeVersion` foreign key becomes nullable and may disappear after snapshot completion. Production history must remain readable and executable without loading that version.

### Production header snapshot

`ProductionRun` stores:

- current optional `recipe_id`;
- `recipe_name_snapshot`;
- optional `source_formula_version_number`;
- `formula_context_snapshot` containing only manufacturing calculation facts required to understand the formula, such as calculation basis, lye type, superfat, and water mode/value;
- existing production basis, entered unit, expected units, dates, identifiers, notes, and lifecycle state;
- `formula_snapshot_completed_at` to distinguish fully independent records from transitional legacy records.

The SOP, featured media, description, regulatory presentation, costing workspace, and other Workbench content are not copied into a production.

### Formula lines

A new `ProductionFormulaLine` belongs to a production and stores the exact planned manufactured formula:

- optional `ingredient_id` and optional source recipe-item ID for navigation only;
- component code: `ingredient`, `naoh`, `koh`, or `water`;
- snapshotted component name;
- phase key and name;
- percentage relative to the production basis;
- planned mass in canonical grams;
- optional line note;
- stable sort order.

Formula-line foreign keys use `nullOnDelete`. Their snapshots remain valid when source formula rows or ingredients disappear.

For soap, calculated NaOH/KOH/water lines are generated from the same saved formula calculation used by the Workbench formula document. For cosmetics, every explicit phase ingredient is copied. Formula lines do not include packaging.

### Inventory requirements

`ProductionRequirement` keeps its current focused responsibility: stock-linked ingredient and packaging demand. Reservations continue to belong to requirements. Calculated formula lines without a mapped stock ingredient do not become fake inventory requirements and do not block stock preparation.

This deliberately allows formula and inventory coverage to evolve independently. Mapping calculated lye/water components to stock ingredients is a separate capability; it is not inferred by translated names.

## Lifecycle

### Creation

Planning reads the latest published formula once, in one transaction. It creates:

1. the production header and product/formula identity snapshots;
2. complete production formula lines;
3. stock-linked ingredient and packaging requirements;
4. the selected/default task-set reference;
5. generated tasks when a production date is available.

No stock is reserved during creation.

### Formula changes after planning

A production never follows later Workbench changes automatically. The snapshot records what was selected when that production was planned. A maker who wants the revised formula creates a new production rather than silently replacing the source of an existing batch.

### Quantity correction

Draft and Planned productions without active reservations may be corrected. Corrections rescale their own snapshotted formula percentages and requirement percentages/components-per-unit. They never reload the old or current `RecipeVersion`.

Existing requirement rows are updated in place so released/cancelled reservation audit rows are not destroyed. Formula lines are also updated in place. Packaging requirements are recalculated from expected units.

### Freeze point

The formula and requirements become immutable when stock is reserved. Assigning a permanent batch number does not freeze quantities because users may assign numbers before the production date and still need to correct a planning mistake.

### Tasks

The chosen/default task set is resolved and stored on the production during creation. Later task generation uses that stored task-set relationship and never needs the recipe to discover a default. Generated tasks remain independent snapshots as already designed.

## Product Archiving and Deletion

Products already have `archived_at`; the customer workflow should use it.

- A product without production history may be permanently deleted after the existing typed-name confirmation.
- A product with production history is archived by the normal removal action. It disappears from active product selection but its relationship to productions remains available for history and reporting.
- Archived products can be included through an explicit archived filter and restored.
- Exceptional permanent deletion is allowed only when every related production has a completed independent snapshot. The production recipe link becomes `NULL`; the product name and formula lines remain.

Formula versions are pruned according to normal plan/history retention. Only transitional productions whose `formula_snapshot_completed_at` is null may temporarily protect a source version during deployment backfill.

## Existing-Data Transition

The rollout is additive and recoverable:

1. add nullable snapshot header fields and the formula-line table;
2. make recipe/version foreign keys nullable with `nullOnDelete`;
3. create all new productions with complete snapshots;
4. run an idempotent backfill command for existing productions using their still-present pinned versions;
5. report incomplete records instead of deleting their source versions;
6. once backfill is complete, normal version pruning removes unneeded versions naturally.

Existing explicit ingredient requirements can seed formula lines, but the backfill must still use the saved formula calculation to add NaOH/KOH/water and context before marking a production complete.

## Read Model and User Experience

Production list, detail, calendar, task, and stock-preparation screens display `recipe_name_snapshot`. They use the live recipe only to offer a Workbench link.

The production detail shows:

- batch/planning number and product snapshot name;
- production date, batch basis, and expected output;
- complete formula grouped by phase, including lye and water;
- packaging and stock requirements;
- tasks and stock preparation state.

The old mandatory “Formula version” dependency is removed. An optional “Source formula version” value may remain as quiet audit metadata.

The product list shows the number of related productions and links to the Production list filtered by that recipe. Archived products retain this relationship.

## Integrity Rules

- Snapshot creation and production creation are atomic and idempotent.
- A completed snapshot is readable without `Recipe` or `RecipeVersion` records.
- Cross-workspace products, versions, task sets, ingredients, and packaging remain rejected.
- Formula-line mass and percentage values are positive canonical decimals.
- Requirement subjects remain real stock subjects; no translated-name matching creates inventory links.
- Active reservations prevent quantity changes.
- Reserved, In production, Completed, Cancelled, and Aborted productions never rebuild formula snapshots.
- Product/version deletion cannot remove production formula lines, requirements, reservations, tasks, identifiers, or movements.
- Cancelled/read-only Production Bench rules continue blocking mutations.

## Out of Scope

- copying or versioning SOP content per production;
- automatically refreshing a production from a later formula;
- merging Production Bench runs with Basic production snapshots;
- mapping NaOH/KOH/water calculations to workspace inventory ingredients;
- actual consumption, output lots, and final production costing beyond the already planned execution checkpoint;
- equipment, mould, line, capacity, or time-tracking models.

## Acceptance Scenarios

1. Plan a soap production and see oils, additions, NaOH/KOH as applicable, and water in its formula snapshot.
2. Publish or edit the product afterward; the production formula remains unchanged.
3. Correct a Planned production quantity before reservation; formula and requirements rescale without loading the source version and released reservation history remains.
4. Reserve stock; subsequent quantity changes are rejected.
5. Prune the source formula version; production detail, calendar, tasks, and stock preparation still work.
6. Archive a product; its production history remains linked and filterable.
7. Permanently delete a fully snapshotted archived product; the production survives with its snapshotted product name and formula.
8. Generate tasks after product archival for a production that already stored its task set; no product lookup is required.
