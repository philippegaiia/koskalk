# Packaging Costing Design

## Summary

The current costing tab mixes two different concepts:

- reusable packaging item definitions
- packaging usage for a specific formula

That creates ambiguity around what the user is entering, especially for `quantity`, which currently does not explain whether it means per piece, per finished unit, or per batch.

This design separates those concerns clearly:

- `Packaging Items` becomes the reusable catalog management area
- `Costing` becomes the place where a formula defines packaging usage per finished unit
- users can create missing packaging items directly from the costing tab through a modal, without leaving their workflow

## Goals

- Make the packaging costing model obvious to a first-time user
- Remove ambiguity around the current `quantity` field
- Keep packaging outside the ingredient BOM/formula model
- Preserve reuse through a packaging catalog
- Let users create a missing packaging item without leaving the costing tab

## Non-Goals

- No purchasing-unit conversion in this iteration
- No automatic translation from `pack of 100` or `roll of 250` into effective unit cost
- No packaging percentages
- No packaging embedded into the ingredient formula structure

## User Model

The user should understand packaging through one rule:

`Costing packaging rows describe how many packaging components are used for one finished unit.`

Examples:

- 1 box per soap
- 1 bottom label per soap
- 1 front sticker per soap
- 2 stickers per soap

The packaging catalog stores reusable packaging items and their effective unit prices. Users are responsible for entering the effective unit price themselves for now.

## Information Architecture

### Packaging Items

Add a dedicated `Packaging Items` menu or page for reusable catalog management.

This area owns:

- create packaging item
- edit packaging item
- review existing packaging items
- archive or delete packaging item if the current product rules allow it

This area does not own formula usage.

### Costing Tab

The costing tab owns:

- ingredient costing
- packaging usage for the current formula
- summary calculations derived from ingredient cost, packaging cost, and units produced

The packaging section in costing should be framed as formula-specific usage, not as catalog administration.

## Costing Tab UX

Rename and reframe the packaging area as:

- section title: `Packaging usage per finished unit`
- helper text: `Define how many of each packaging component are used for one finished unit. Batch packaging cost is calculated from this and Units produced.`

### Packaging Row Fields

Each packaging row in costing should show:

- `Packaging item`
- `Components per finished unit`
- `Effective unit price`
- `Cost per finished unit`
- `Batch cost`

The current generic `quantity` label should be removed because it does not explain the unit of measure.

### Default Behavior

When a packaging item is added to the costing:

- it defaults to `components per finished unit = 1`
- it pulls `effective unit price` from the saved packaging item
- the user only edits the count if the usage is not one-to-one

Examples:

- `Box` defaults to `1`
- `Bottom label` defaults to `1`
- `Front sticker` defaults to `1`
- if a soap uses two stickers, the user changes the count to `2`

## Pricing Rules

Packaging item prices in the catalog represent the effective unit price entered by the user.

Examples:

- box = 0.22 each
- front sticker = 0.03 each
- bottom label = 0.02 each

The system should not attempt to derive effective unit prices from purchase pack sizes in this iteration. That remains a manual decision by the user.

## Calculation Rules

### Per-Unit Packaging Cost

For each packaging row:

`cost per finished unit = components per finished unit × effective unit price`

### Batch Packaging Cost

For each packaging row:

`batch cost = cost per finished unit × units produced`

### Total Packaging Cost

`packaging total = sum of batch cost for all packaging rows`

### Total Cost Per Unit

`cost per unit = ingredient cost per unit + packaging cost per unit`

The summary should expose both packaging total and cost per unit so the user can understand batch economics and finished-unit economics at the same time.

## Inline Creation Flow

If the needed packaging item does not exist, the user should be able to create it directly from the costing tab through a modal.

### Modal Trigger

Place a clear action near the packaging picker or add action:

- `New packaging item`

### Modal Fields

The modal should contain catalog fields only:

- `Name`
- `Effective unit price`
- optional `Notes`

### Modal Actions

- primary: `Save and add to this costing`
- secondary: `Save only`
- tertiary: `Cancel`

### Post-Save Behavior

If the user chooses `Save and add to this costing`:

- the catalog item is persisted
- the modal closes
- a new packaging row is added to the current formula costing
- `components per finished unit` defaults to `1`

If the user chooses `Save only`:

- the catalog item is persisted
- the modal closes
- the costing state is unchanged until the user selects the item

## Empty States And Validation

### No Packaging Rows

Use an explanatory empty state such as:

- `No packaging added yet`
- `Choose a packaging item from your catalog, or create one without leaving this tab.`

Avoid language like `Add custom row` as the main framing, because the primary model should be reusable packaging items.

### Missing Units Produced

If `units produced` is empty or zero:

- packaging rows remain editable
- `cost per finished unit` still displays
- batch-level totals that depend on `units produced` should show a muted placeholder such as `Set units produced`

This prevents the UI from implying that packaging cannot be entered yet while still signaling that batch totals are incomplete.

### Missing Packaging Price

If a packaging row has no valid price:

- row totals should resolve to `0`
- the row should show an incomplete-pricing state through helper text or subdued feedback

## Content And Labeling Changes

The current copy emphasizes “saved packaging items” and “packaging in this costing,” but does not teach the cost model.

Copy should instead reinforce three concepts:

- packaging items are reusable
- formula rows define usage per finished unit
- batch totals derive from `units produced`

Recommended wording patterns:

- `Packaging Items` for catalog management
- `Packaging usage per finished unit` in costing
- `Components per finished unit` instead of `Quantity`
- `Effective unit price` instead of generic `Unit cost` where clarity matters

## Data Flow

### Catalog Layer

Reusable packaging items remain user-owned records separate from formula BOM data.

Stored fields for this design:

- name
- effective unit price
- currency
- optional notes

### Formula Costing Layer

Formula costing stores usage rows referencing packaging items plus formula-specific usage values:

- packaging item id
- components per finished unit
- effective unit price snapshot used by the costing

The costing should keep its existing behavior of snapshotting pricing into the formula costing context so later catalog price changes do not rewrite historical formula economics unless explicitly refreshed.

## Error Handling

- If a catalog item save fails in the modal, keep the modal open and show a clear inline error
- If adding a catalog item to costing fails after save, preserve the saved item and show a recoverable message
- If costing autosave fails, keep the user’s visible row values and show a non-destructive save warning
- If a deleted packaging item is still referenced by historical costing, the costing snapshot should remain readable because the effective unit price and item name are preserved on the costing row

## Testing

Add feature coverage for:

- costing tab displays the updated packaging terminology
- a packaging row defaults to `1 component per finished unit`
- packaging batch totals multiply correctly by `units produced`
- creating a packaging item from costing persists the catalog item
- `Save and add to this costing` inserts the new item immediately into costing
- missing `units produced` shows the expected placeholder behavior
- missing price results in a non-crashing incomplete state
- existing formula cost snapshots remain stable even if packaging catalog prices later change

## Rollout Notes

This design intentionally avoids purchase-unit conversion logic. Once this simpler model is clear and easy to use, a future iteration can add optional purchasing metadata like:

- purchase pack size
- supplier unit
- auto-derived effective unit cost

That should be a later enhancement, not part of this UX clarification pass.
