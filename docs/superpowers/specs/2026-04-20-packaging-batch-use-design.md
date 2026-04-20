# Recipe Packaging & Batch Use Design

## Summary

Split the current combined Costing tab into a separate **Packaging** tab and a **Costing** tab. Packaging becomes a first-class part of the recipe draft alongside Formula. Costing focuses on price memory and batch economics without requiring draft editing. Add a **Batch Use** flow on the official saved recipe page so users can set batch number, manufacture date, quantity, and print without entering the draft editor.

## Core Concepts

### Official Recipe

The published, read-only state of a recipe. Contains:
- **Formula** — ingredient list with percentages and weights
- **Packaging plan** — reusable packaging items with components-per-unit
- **Instructions & Media** — manufacturing notes and images

### Costing (User-Private)

Prices and batch economics specific to the current user. Does not change the formula identity. Contains:
- Ingredient prices (from user's price memory or manual entry)
- Packaging prices (from user's packaging catalog)
- Units produced, oil weight override, currency

### Batch Use

A lightweight mode on the official saved recipe page for daily production. Contains:
- Batch number
- Manufacture date
- Quantity (units produced this batch)
- Print output (production sheet, costing sheet, technical sheet)

## Goals

- Eliminate the confusion where editing prices requires entering the draft editor
- Make packaging a first-class recipe component alongside formula
- Separate "developing a recipe" from "pricing a batch" from "running a production batch"
- Keep price changes from silently rewriting official formula state

## Non-Goals

- No batch record persistence in v1 (print-only)
- No margin targets, selling price, markup in v1
- No purchase-pack conversion or supplier procurement

## Tab Structure

### Draft Editor (RecipeWorkbench)

Tabs in order:

1. **Formula** — ingredient composition, phase-aware
2. **Packaging** — packaging items and components-per-unit for one finished unit
3. **Costing** — price ingredients and packaging, see batch economics
4. **Output** — soap calculation results, fatty acid profile, allergens
5. **Instructions & Media** — manufacturing notes, images

### Official Saved Recipe Page

Views in order:

1. **Recipe view** — read-only formula, read-only packaging plan, editable costing prices
2. **Batch Use** — batch number, manufacture date, quantity, print buttons

## Packaging Tab

### Purpose

Define what packaging a single finished unit requires. This is part of the official recipe, not just the costing.

### User Model

"Packaging" means what one finished unit uses:
- 1 box per soap
- 1 front sticker per soap
- 2 shrink bands per soap
- 1 bottom label per soap

### Behavior

- Users add packaging items from their catalog
- For each item, set `components per unit` (default 1)
- Optional notes field per row
- Packaging rows are saved as part of the draft and published into the official recipe
- Changes to packaging do NOT require re-costing the formula (quantities and unit prices are independent)

### Data Model

Packaging rows are stored in `recipe_version_costing_packaging_items` when saved in the Costing context. This reuse is intentional — the same table serves both the Packaging tab (draft definition) and the Costing tab (batch economics).

The distinction is conceptual: in the Packaging tab, the user is defining the packaging plan. In the Costing tab, the same rows are used to calculate batch cost.

### Layout

```
[Packaging plan header]
[Packaging rows table]
  - Packaging item (from catalog)
  - Components per unit
  - Unit price (editable)
  - Notes
  - Remove button
[Add from catalog dropdown]
[New packaging item button]
```

## Costing Tab

### Purpose

Price ingredients and packaging, see batch economics. Does NOT allow editing the formula or packaging plan — only the prices.

### UX Copy

The tab must make this clear:

> "Changing prices does not change the official formula. These prices are saved as your latest costing prices."

### Behavior

- Ingredient price columns are editable
- Packaging unit prices are editable
- `units_produced` is editable
- `oil_weight_for_costing` override is editable
- On save, ingredient prices are upserted into `user_ingredient_prices` with `last_used_at = now()`
- Packaging prices are NOT written back to the catalog — they are saved only in the costing context

### Layout

Same as current costing tab, minus the packaging plan definition (that moved to the Packaging tab):

1. **Costing settings** — oil weight override, unit, units produced, currency
2. **Ingredient costing** — read-only formula rows with editable price/kg
3. **Packaging** — read-only packaging rows with editable unit price
4. **Cost summary** — live-computed totals

## Official Saved Recipe Page — Batch Use

### Purpose

Give users a direct path from an official recipe to a production batch without opening the draft editor.

### UX Copy

> "Use recipe" / "Prepare batch"

### Batch Controls

- **Batch number** — user-entered string
- **Manufacture date** — date picker, defaults to today
- **Quantity** — number of units produced this batch
- **Units produced** — derived from quantity, used in costing calculations

### Print Output

Three print buttons:

1. **Production sheet** — formula + packaging + manufacture date + batch number + quantity
2. **Costing sheet** — ingredient prices + packaging costs + cost per unit + cost per kg
3. **Technical sheet** — full recipe details for regulatory/compliance use

### Pricing in Batch Use

- Uses the user's current costing prices as defaults
- User can adjust any price inline before printing
- Adjusted prices are NOT saved to the costing (print-only override)
- A note should appear: "These prices are from your latest costing and can be adjusted for this batch."

## Data Flow

### Publishing a Draft

When `RecipeVersionPublisher::publish()` runs:

1. Formula (RecipeItems via RecipePhases) is published as before
2. Packaging plan is published — `recipe_version_costing_packaging_items` rows are copied to the published version
3. Costing is copied forward — existing costing prices are preserved

### Opening the Official Recipe Page

1. Load the published recipe version
2. Load the user's costing (or create an empty one)
3. Display formula read-only
4. Display packaging plan read-only
5. Display costing with editable prices

### Price Edit on Official Recipe Page

1. User edits an ingredient price
2. Change is saved to `user_ingredient_prices` with `last_used_at = now()`
3. Change is saved to the current costing
4. Costing totals recompute live

### Batch Use Print

1. User fills in batch number, date, quantity
2. User optionally adjusts prices (not persisted)
3. User clicks a print button
4. PDF is generated with current batch context

## Implementation Order

### Phase 1: Packaging Tab

1. Add `Packaging` tab to the RecipeWorkbench navigation
2. Create `packaging-tab.blade.php` view
3. Build `loadPackaging()` and `savePackaging()` Livewire methods
4. Packaging rows use the existing `recipe_version_costing_packaging_items` table
5. On publish, packaging rows are copied forward with the version

### Phase 2: Costing Cleanup

1. Remove packaging plan definition from Costing tab
2. Costing tab now shows packaging rows as read-only (with editable unit price for batch costing)
3. Keep ingredient price editing as-is
4. Update UX copy to clarify price/editing distinction

### Phase 3: Official Recipe Page — Batch Use

1. Create the official recipe page view
2. Add batch controls: batch number, manufacture date, quantity
3. Show formula read-only
4. Show packaging plan read-only
5. Show costing with inline-editable prices
6. Add print buttons with PDF generation

### Phase 4: Print Sheets

1. Production sheet template
2. Costing sheet template
3. Technical sheet template
4. Print action that injects batch context

## Error Handling

- If draft is not saved before opening Costing tab: show "Save the first draft to start costing"
- If costing save fails: preserve user input, show non-destructive warning
- If packaging catalog item is deleted: existing costing snapshots remain readable (name/cost are snapshotted)
- If no published version exists: Batch Use is not available

## Testing

Coverage needed for:

- Packaging tab renders in workbench
- Packaging rows save as part of draft
- Packaging rows publish with version
- Costing tab shows packaging rows as read-only
- Ingredient prices save to user price memory
- Batch Use page loads published recipe
- Batch controls accept and validate input
- Print buttons generate correct output
- Price adjustments in Batch Use do not persist to costing