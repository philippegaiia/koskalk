# Production Snapshots V1 Design

## Summary

Add first-class production snapshots so users can record what they actually made from a saved formula. A production snapshot freezes the formula basis, calculated ingredient quantities, packaging use, prices, totals, and cost per unit at the moment of production. Notes and ingredient lot numbers remain editable as practical recordkeeping annotations.

This turns the current print-only batch preparation flow into a durable production history without becoming an inventory or ERP system.

## Goals

- Preserve historical production cost truth even after catalog prices change.
- Keep the production recording flow small enough for real workshop use.
- Reuse existing formula, packaging, and costing data instead of asking users to re-enter it.
- Support soap and cosmetic formulas with the correct batch basis language:
  - Soap: oil quantity
  - Cosmetics: total batch quantity
- Capture optional ingredient lot numbers for traceability.
- Provide editable production notes for QC observations, process notes, curing notes, and other batch-specific comments.
- Keep the data model ready for a future raw material registry without building that registry in V1.

## Non-Goals

- No raw material purchase registry in V1.
- No inventory deduction, FIFO, stock adjustments, or quantity remaining.
- No supplier lot selection in V1.
- No packaging lot numbers in V1.
- No structured QC checklist in V1.
- No production status workflow in V1.
- No attachments or COA/SDS links in V1.
- No margin, selling price, tax, or profitability workflow in V1.

## Core Concepts

### Live Costing

Live costing is the current working estimate for a recipe. Until production snapshots exist, price changes should update linked editable costing rows everywhere they represent the user's current economics.

After production snapshots are added, live costing remains mutable. It should continue to reflect the user's latest ingredient and packaging prices.

### Production Snapshot

A production snapshot is a historical record. It freezes the values used for one real batch:

- formula/version identity
- batch basis and unit
- manufacture date
- units produced
- calculated ingredient quantities
- ingredient prices
- packaging quantities
- packaging prices
- currency
- ingredient total
- packaging total
- total production cost
- cost per finished unit

These calculated and economic fields do not change when catalog prices, formula rows, packaging rows, or costing settings change later.

### Editable Recordkeeping Annotations

Some production fields are recordkeeping annotations rather than calculation inputs. They may be edited after snapshot creation:

- production batch number
- ingredient lot numbers
- production notes

Editing these fields never recalculates the snapshot.

## User Interface

### Entry Point

On the saved formula page, evolve the current **Prepare batch** area into **Record production**.

The section should sit near the existing read-only formula and packaging/costing context. Users should not need to open the draft workbench to record production.

### Record Production Form

Fields:

- **Production batch number**: optional text field.
- **Manufacture date**: required date field, defaulting to today.
- **Oil quantity** for soap formulas, or **Total batch quantity** for cosmetic formulas: required decimal input using the recipe's unit.
- **Units produced**: required positive integer.
- **Ingredient lot numbers**: optional compact table, one row per ingredient used in the production.
- **Production notes**: optional textarea with enough room for real notes, around 6 to 8 lines.

The ingredient lot table should be compact:

| Ingredient | Quantity | Unit | Ingredient lot number |
|---|---:|---|---|
| Olive Oil | 500.00 | g | free text |

The table should not include supplier, expiry, COA, SDS, inventory quantity, or raw-material selection in V1.

### Cost Summary

The form should show a small read-only summary based on current costing values:

- Ingredient cost
- Packaging cost
- Total production cost
- Cost per finished unit

If some prices are missing, totals should still render with missing prices treated as zero. The interface should show a quiet warning such as "Some rows are unpriced." This warning should not block production recording.

### Primary Action

The primary action is **Record production**.

After save, the user lands on the read-only production snapshot page.

### Production History

The saved formula page should include a compact production history list:

- manufacture date
- production batch number, or "No batch number"
- batch basis
- units produced
- cost per unit
- link to view/print

This list can start simple and recipe-scoped. A global production history page can come later if needed.

### Production Snapshot Page

The read-only snapshot page shows:

- batch identity and recipe/version used
- manufacture date
- batch basis and units produced
- ingredient rows with quantities and editable lot numbers
- packaging rows and costs
- total production cost and cost per unit
- editable production notes
- print button

Only batch number, lot numbers, and production notes are editable. The page should make recalculation impossible from this view.

### Printout

The production printout is based on the saved snapshot, not live recipe/costing data.

It should include:

- recipe name and version
- production batch number
- manufacture date
- batch basis
- units produced
- ingredient quantities and lot numbers
- packaging rows
- cost summary
- production notes

The printout should show **Production notes** as a section title only. No helper text should appear on the printout. If saved notes exist, print them and leave additional blank space below. If no notes exist, print a decent blank writing area.

## Data Model

### `production_batches`

Stores one production snapshot header.

| Column | Notes |
|---|---|
| `id` | Primary key |
| `user_id` | Owner |
| `recipe_id` | Source recipe |
| `recipe_version_id` | Source version |
| `recipe_name` | Snapshot display name |
| `recipe_version_number` | Snapshot version number |
| `product_family_slug` | `soap` or `cosmetic` |
| `production_batch_number` | Nullable, editable |
| `manufacture_date` | Required |
| `batch_basis_label` | `Oil quantity` or `Total batch quantity` |
| `batch_basis_value` | Required decimal |
| `batch_basis_unit` | Required unit string |
| `units_produced` | Required positive integer |
| `currency` | Snapshot currency |
| `ingredient_total` | Frozen decimal |
| `packaging_total` | Frozen decimal |
| `total_cost` | Frozen decimal |
| `cost_per_unit` | Frozen decimal |
| `production_notes` | Nullable, editable |
| `created_at`, `updated_at` | Timestamps |

### `production_batch_ingredients`

Stores one frozen ingredient row for a production snapshot.

| Column | Notes |
|---|---|
| `id` | Primary key |
| `production_batch_id` | Parent |
| `ingredient_id` | Nullable link to source ingredient |
| `raw_material_lot_id` | Nullable future hook, unused in V1 |
| `phase_key` | Snapshot phase key |
| `phase_name` | Snapshot phase display name |
| `position` | Row order |
| `ingredient_name` | Snapshot name |
| `percentage` | Snapshot percentage |
| `quantity` | Snapshot production quantity |
| `unit` | Quantity unit |
| `price_per_kg` | Frozen decimal, nullable |
| `line_cost` | Frozen decimal |
| `ingredient_lot_number` | Nullable, editable free text |
| `created_at`, `updated_at` | Timestamps |

The `raw_material_lot_id` column is reserved for a later raw material registry. V1 never requires it and never shows raw material inventory selection.

### `production_batch_packaging_items`

Stores one frozen packaging row for a production snapshot.

| Column | Notes |
|---|---|
| `id` | Primary key |
| `production_batch_id` | Parent |
| `user_packaging_item_id` | Nullable link to source catalog item |
| `position` | Row order |
| `name` | Snapshot packaging item name |
| `components_per_unit` | Snapshot components per finished unit |
| `unit_cost` | Frozen decimal |
| `cost_per_finished_unit` | Frozen decimal |
| `line_cost` | Frozen decimal |
| `created_at`, `updated_at` | Timestamps |

## Data Flow

### Opening Record Production

1. Load the saved recipe and selected saved version.
2. Load or create the user's live costing for that version.
3. Use current live costing prices as production defaults.
4. Build scaled ingredient quantities from the requested batch basis.
5. Build packaging quantities from the packaging plan and units produced.
6. Compute ingredient total, packaging total, total cost, and cost per unit.

### Saving Production

1. Validate manufacture date, positive batch basis, and positive units produced.
2. Accept empty production batch number.
3. Accept empty ingredient lot numbers.
4. Accept empty production notes.
5. Create the production batch and child rows in one transaction.
6. Store computed values as frozen decimals.
7. Redirect to the production snapshot page.

Saving production does not update the recipe, recipe version, live costing, ingredient price memory, or packaging catalog prices.

### Editing After Creation

Allowed edits:

- production batch number
- ingredient lot numbers
- production notes

Blocked edits:

- formula rows
- ingredient quantities
- packaging quantities
- prices
- totals
- manufacture date
- batch basis
- units produced

If users need a different basis, units produced, or price set, they create another production snapshot.

## Price Update Policy

Before production snapshots are created, linked live costing rows should follow the user's current price updates. This keeps today's costing behavior intuitive.

Once a production snapshot is created, its frozen price rows do not change. Later price edits affect only live costing and future production snapshots.

## Future Raw Material Registry Compatibility

V1 captures ingredient lot numbers as free text. This gives immediate traceability value without introducing inventory.

A later raw material registry can add:

- raw material purchases
- supplier lots
- expiry dates
- remaining quantities
- raw material lot selection during production
- inventory deduction

The future registry can connect to `production_batch_ingredients.raw_material_lot_id` while preserving existing free-text lot numbers.

## Error Handling

- If the user is not signed in, production recording is unavailable.
- If the formula is not saved, production recording is unavailable.
- If batch basis is missing or not positive, show an inline validation message.
- If units produced is missing or not positive, show an inline validation message.
- If prices are missing, allow save and show a quiet warning that some rows are unpriced.
- If the source recipe or version is no longer accessible, return a not-found response.

## Testing Strategy

Feature tests should cover:

- creating a production snapshot from a saved soap formula using oil quantity.
- creating a production snapshot from a saved cosmetic formula using total batch quantity.
- snapshot rows preserve prices even after ingredient or packaging catalog prices change.
- production notes can be edited after creation without recalculating totals.
- ingredient lot numbers can be edited after creation without recalculating totals.
- immutable fields cannot be edited from the snapshot page.
- printout shows production notes without helper text and leaves blank writing space.
- production history list shows saved snapshots for the recipe owner only.

## Open Decisions

No open decisions remain for V1. The design deliberately excludes inventory and raw material purchasing while leaving a schema hook for it.
