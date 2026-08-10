# Manufactured Ingredient Output and Release Design

**Date:** 2026-08-10

## Purpose

Model materials such as turmeric oil macerate, infused oils, soap bases, and other in-house preparations without confusing products, ingredients, suppliers, or stock receipts.

The design must let one formula manufacture a bulk material that can be consumed by later formulas, while allowing a separately packaged product to consume and sell that material. It must also retain the professional manual-release decision without making the estimated ready date a second hard gate.

## Domain language

### Product/formula

A product is the thing the workspace knows how to manufacture. It belongs to an existing formula family such as Soap or Cosmetic and owns the versioned formula and packaging definition used for production planning.

### Manufactured ingredient

A manufactured ingredient is the inventory identity of a bulk material produced by a product/formula and measured in mass. It can be selected as an ingredient in another product formula.

“Manufactured” describes one possible source of the ingredient. It does not mean the ingredient cannot also be purchased from external suppliers.

### Finished product

A finished product is production output tracked in countable units and linked to its product/recipe. Packaging belongs to this product's formula.

### Output lot

Every completed production creates exactly one output lot. The lot is linked either to the finished product or to the manufactured ingredient, never both. Its production run supplies internal provenance.

### Awaiting release

“Awaiting release” is the user-facing term for a completed output lot that is physically present but unavailable. The existing quarantined stock status may remain the storage representation, but the production workflow should not present it as a laboratory or regulatory quarantine process.

## Product classification

Soap and Cosmetic remain formula families. Manufactured ingredient must not become a third formula family because it describes output behavior rather than calculation rules.

Each product receives one production output type:

1. **Finished product — units**
2. **Bulk manufactured ingredient — mass**

A bulk manufactured product must reference exactly one workspace-owned ingredient as its output ingredient. A finished product must not reference an output ingredient.

The product workflow allows the user to:

- select an existing workspace ingredient marked as manufactured in-house; or
- create the ingredient inline, automatically marking it as manufactured in-house.

The existing Ingredient form must expose the same “Manufactured in-house” property clearly. The property is not exclusive: the ingredient may also have external supplier listings.

## Example: turmeric oil macerate

The workspace creates a bulk product named “Turmeric oil macerate” in the appropriate existing formula family. Its formula consumes turmeric powder and sunflower oil. Its production output type is Bulk manufactured ingredient, linked to the ingredient “Turmeric oil macerate.”

Completing a run creates a mass-based lot of that ingredient. Other formulas can reserve and consume the released lot.

If the macerate is sold in bottles, the workspace creates a second, finished product. That product's formula consumes the bulk macerate ingredient plus the bottle, label, closure, and other packaging. Its output is countable finished units.

The bulk ingredient may also be issued directly by mass when the business sells it in bulk. Retail packaging remains a separate finished-product production.

## Product-to-ingredient relationship

The product stores a nullable `output_ingredient_id` and an explicit output type. Multiple products may produce the same ingredient if the workspace genuinely has multiple formulas or processes for the same material. Each output lot still traces to the exact production run and recipe version that produced it.

An ingredient may therefore have both:

- one or more internal producing products/formulas; and
- zero or more external supplier listings.

This is a mixed-source material, not two ingredient records.

The production completion page must not offer an arbitrary ingredient selector. Output identity is determined by the production run snapshot.

## Production snapshots

When a production run is created, it snapshots:

- output type;
- output ingredient identifier when the output is bulk material;
- configured ready-delay days.

Changing the product later must not change an existing run's output identity or readiness calculation. Existing historical runs and lots remain readable even if the product or ingredient is later archived.

## Ready-date configuration

Production settings provide a workspace-wide default ready delay in calendar days. Zero means the output is estimated ready on its manufacture date.

Each product may provide a nullable override. A null product value uses the workspace default; zero explicitly means no delay.

At planning time, the interface may display an estimated ready date using the planned production date and the snapshotted delay. This estimate is informative and changes with an editable plan date before production starts.

At completion, the system calculates the output lot's estimated ready date from the actual manufacture date and the snapshotted delay. The completion form displays that date and allows the professional to change it before completing the run. The stored `estimated_ready_on` date is the operator-confirmed estimate; it is deliberately separate from stock availability.

## Completion and release lifecycle

The same lifecycle applies to finished and manufactured-ingredient output:

```text
In production
    → Completed / output physically present / Awaiting release
    → Manually released
    → Available stock
```

Completion atomically:

- posts actual material consumption;
- releases unused reservations;
- records actual costs;
- creates one output lot;
- posts one production-output stock movement;
- stores the operator-confirmed estimated ready date;
- marks the output Awaiting release;
- closes the production run as Completed.

The output is included in physical stock immediately but excluded from available stock while awaiting release.

Release is a professional decision for both output types. There is no automatic release job. Releasing the lot changes availability but does not post another quantity movement.

All production tasks must be completed before release. If there are no tasks, this requirement is already satisfied.

The estimated ready date is guidance, not a hard prohibition. Releasing on or after the date proceeds normally. Releasing before the date requires an explicit confirmation warning showing the estimated date. After confirmation, the professional may release the lot and it becomes available immediately. Early release does not rewrite `estimated_ready_on`, so the record preserves the estimate against which the professional made the decision.

The production remains Completed before and after release. Released describes the output lot, not the production run.

## Stock and provenance

Production completion—not release—is the stock-entry event for manufactured output.

A manufactured ingredient output lot contains:

- `ingredient_id` for the linked manufactured ingredient;
- `origin = production_output`;
- `production_run_id` for internal provenance;
- `supplier_listing_id = null`;
- mass unit kind;
- the permanent production batch number as its internal lot code;
- actual historical cost per gram;
- Awaiting release status;
- the advisory `estimated_ready_on` date.

The production-output movement makes the material physically present. Manual release only changes the lot's availability state and release metadata. Creating a goods receipt at release would duplicate the stock quantity and incorrectly represent internal production as procurement.

A purchased lot of the same ingredient instead contains:

- `origin = purchase_receipt`;
- a required supplier listing through the goods-receipt workflow;
- supplier and receipt provenance;
- no production run.

The workspace must not receive a synthetic “Internal supplier” or supplier listing. Internal provenance is represented by the production run, formula snapshot, consumed lots, batch number, and production cost.

## Availability and consumption

Only Released lots are available for reservation or actual production consumption. An Awaiting release lot remains visible in physical stock and production history but cannot be reserved or selected as an actual material. `estimated_ready_on` does not independently block a Released lot: manual release is the availability decision.

Actual-material validation must be enforced twice:

1. when actual consumption is saved; and
2. under lot locks immediately before completion posts movements.

The checks reject Awaiting release, expired, and otherwise ineligible lots. They prevent a manufactured intermediate from being consumed before the professional releases it, while allowing an explicitly early-released lot to be consumed.

## User experience

### Product setup

The product form shows an Output section independent of Soap/Cosmetic formula-family selection.

For Finished product, the section explains that output is tracked in units and packaging belongs to the formula.

For Bulk manufactured ingredient, it shows a required searchable ingredient selector and a “Create manufactured ingredient” action. Inline creation asks only for the fields required by the existing private-ingredient workflow and marks the ingredient as manufactured in-house. Full catalogue details remain editable from the Ingredient page.

### Production completion

The operator enters actual output quantity and manufacture date. Output quantity is an integer for finished products and a positive mass for bulk materials.

The page displays the snapshotted output identity and estimated ready date. It does not ask the operator to choose output type or an ingredient.

### Output card

After completion, the page shows:

- output identity and lot number;
- actual output quantity;
- “Awaiting release” or “Released”;
- estimated ready date;
- incomplete release tasks;
- the Release action when otherwise eligible.

Early release shows a confirmation containing the estimated ready date. The warning is not a validation failure and does not require changing the stored date.

## Validation and concurrency

Server-side validation must enforce:

- a supported output type;
- a required workspace-owned output ingredient for bulk products;
- no output ingredient for finished products;
- a non-negative integer ready delay;
- a valid estimated ready date at completion;
- output identity derived from the production snapshot;
- positive whole-unit output for finished products;
- positive mass output for manufactured ingredients;
- Completed production, Awaiting release output, and completed tasks for release;
- explicit confirmation for early release;
- released and unexpired input lots for reservations and actual consumption.

Completion and release retain the existing workspace-first lock ordering. Early-release confirmation must re-enter the server action with a confirmation flag; the server re-locks and revalidates the lot, production, tasks, and date rather than trusting browser state.

All new validation and confirmation text must be translated in English, German, Spanish, French, Italian, and Dutch.

## Migration and compatibility

Existing products default to Finished product output unless reliable current data proves that they were configured as intermediate output. Existing production runs keep their current behavior and output history.

Add `estimated_ready_on` to output lots for the advisory date. Existing production-output `available_from` values are copied into `estimated_ready_on`. New production output no longer uses `available_from` as an automatic availability gate; release status is authoritative. Procurement or legacy workflows that still need a hard availability date remain outside this change and must not be silently reinterpreted.

Existing ingredients with `is_manufactured = true` remain eligible for product linking. The migration must not create supplier listings or duplicate ingredients.

The current completion-time intermediate selector remains only long enough to complete pre-migration runs that have no output snapshot. Newly created runs always use the snapshotted product configuration. Once legacy compatibility is no longer needed, the selector can be removed completely.

## Acceptance scenarios

1. A workspace creates a bulk turmeric macerate product, quick-creates its manufactured ingredient, and publishes a formula using turmeric powder and sunflower oil.
2. A production run snapshots the bulk output identity and ready delay.
3. Completion creates one mass-based production-output lot linked to the ingredient, with no supplier listing and one output movement.
4. The Awaiting release lot is physical but cannot be reserved or consumed.
5. Release after completing all tasks makes the ingredient lot available without creating a receipt or another movement.
6. Releasing before the estimated date requires confirmation and succeeds only after confirmation.
7. A later product reserves and consumes the released macerate lot and inherits its historical cost per gram.
8. A packaged macerate product consumes the bulk ingredient plus packaging and produces finished units.
9. The same macerate ingredient may also have supplier listings and purchased lots without conflict.
10. Purchased and internally produced lots remain distinguishable by origin and provenance.
11. Changing the product's output configuration does not change already-created production runs.
12. German, Spanish, French, Italian, and Dutch users receive localized output, readiness, and release messages.

## Deferred work

- Automated quality-control templates and laboratory approval workflows.
- Automatically releasing output on its estimated ready date.
- Creating sales orders or customer fulfilment for bulk material.
- Packaging a bulk material and producing retail units in the same production run.
- Reworking historical migration rollback policy.
