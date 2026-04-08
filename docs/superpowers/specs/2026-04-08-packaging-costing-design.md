# Packaging Costing Design

## Summary

The current packaging experience is visually fragmented and functionally incomplete.

In the costing tab, packaging is pushed into a narrow side column that competes with the ingredient table instead of following it. On the catalog side, the `Packaging Items` page does not let the user create packaging items directly, which makes the page feel broken and forces the user back into costing for basic catalog maintenance.

This design replaces that split experience with one clear model:

- the costing tab shows packaging directly below ingredient costing
- packaging is presented as a simple second costing table, not as a sidebar card stack
- the `Packaging Items` page becomes a real catalog manager with visible creation at the top
- the costing tab can still create a missing packaging item in a modal without forcing the user to leave the recipe

## Goals

- Make the costing flow read in one natural order: settings, ingredients, packaging, totals
- Remove the cramped right-column packaging layout
- Make packaging usage understandable on first read
- Make the `Packaging Items` page immediately useful by allowing direct creation there
- Preserve reusable packaging records without scattering the workflow across unrelated surfaces

## Non-Goals

- No packaging inside the ingredient BOM itself
- No purchase-pack conversion or supplier pack math in this iteration
- No packaging percentages
- No attempt to turn the packaging page into a full procurement system

## User Model

The user should understand two simple rules:

1. `Packaging Items` is a reusable personal catalog.
2. `Packaging` in costing means what one finished unit uses.

Examples:

- 1 box per soap
- 1 front sticker per soap
- 1 bottom label per soap
- 2 shrink bands per soap

The user enters effective unit price manually for now. The application does not derive it from purchase packs.

## Information Architecture

### Costing Tab

The costing tab becomes the main working surface for recipe economics.

The visual order must be:

1. Costing settings
2. Ingredient costing
3. Packaging
4. Cost summary

Packaging must sit directly below ingredient costing in the main content column. It must not live in a narrow right sidebar.

### Packaging Items Page

The `Packaging Items` page becomes a straightforward catalog manager.

The visual order must be:

1. Page intro
2. Create packaging item form
3. Existing packaging items list or table

The page must support creating packaging items directly. It cannot be a read-only index.

## Costing Tab Layout

### Ingredient Costing

Keep ingredient costing as the first large table in the costing flow.

### Packaging Section

Immediately below ingredient costing, render a packaging section that feels like a second costing table.

Recommended section content:

- title: `Packaging`
- helper copy: `Add reusable packaging items used for one finished unit.`
- primary actions:
  - `Add packaging item`
  - `New packaging item`

The packaging section should visually align with the ingredient costing section so the user reads it as the next step in the same process.

### Packaging Row Shape

Each packaging row should be shown in a row-oriented layout, not stacked mini-cards.

Each row must expose:

- `Packaging item`
- `Components per unit`
- `Unit price`
- `Cost per unit`
- `Batch cost`
- `Remove`

The label `Quantity` should not appear in packaging costing.

## Packaging Behavior In Costing

### Adding Existing Items

`Add packaging item` should let the user choose from saved packaging items in the catalog.

When a saved item is added:

- the row defaults to `components per unit = 1`
- the row pulls the current saved unit price
- the user can override the price inside the recipe costing snapshot

### Creating Missing Items

`New packaging item` in costing should open a modal.

The modal contains only catalog fields:

- `Name`
- `Effective unit price`
- optional `Notes`

Actions:

- primary: `Save and add`
- secondary: `Save only`
- tertiary: `Cancel`

Post-save behavior:

- `Save and add` creates the catalog item and inserts it into the current costing with `components per unit = 1`
- `Save only` creates the catalog item and keeps the user in the costing flow without adding a row automatically

## Packaging Items Page Behavior

The catalog page must provide an obvious creation form near the top of the page.

Required fields:

- `Name`
- `Effective unit price`
- optional `Notes`

Required action:

- `Save packaging item`

Below the form, the page should show the saved reusable packaging items in a simple readable list or table.

The page should help the user understand that:

- items saved here become available in recipe costing
- this page defines reusable packaging records
- formula-specific usage still happens inside recipe costing

## Calculation Rules

### Per-Unit Packaging Cost

For each packaging row:

`cost per unit = components per unit × unit price`

### Batch Packaging Cost

For each packaging row:

`batch cost = cost per unit × units produced`

### Total Packaging Cost

`packaging total = sum of all packaging row batch costs`

### Overall Totals

The summary should continue to show:

- ingredients total
- packaging total
- total batch cost
- cost per unit
- cost per kg

## Empty States And Feedback

### No Packaging In Costing

Show an empty state such as:

- `No packaging added yet.`
- `Add a reusable packaging item to include boxes, labels, stickers, and other unit-level packaging in this costing.`

### No Catalog Items

If the user has no saved packaging items yet:

- the costing tab should still allow `New packaging item`
- the packaging page should show the create form first, then an empty list message below it

### Missing Units Produced

If `units produced` is missing or zero:

- packaging rows remain editable
- `cost per unit` still displays
- batch-level values display `Set units produced`

## Content And Labeling

Use simpler wording throughout.

Preferred terms:

- `Packaging` instead of `Packaging usage per finished unit` as the large section title
- `Components per unit`
- `Unit price`
- `Cost per unit`
- `Batch cost`
- `Packaging Items` for the reusable catalog page

Avoid long explanatory headings that repeat the same phrase twice or make the section harder to scan.

## Data Flow

### Catalog Layer

Reusable packaging items remain user-owned records with:

- name
- effective unit price
- currency
- optional notes

### Costing Layer

Recipe costing stores packaging usage rows with:

- packaging item id when available
- packaging item name snapshot
- unit price snapshot
- components per unit

Historical costing must stay readable even if the catalog changes later.

## Error Handling

- If catalog item creation fails in the modal, keep the modal open and show the error inline
- If costing autosave fails, preserve visible user input and show a non-destructive warning
- If a catalog item is later removed or edited, existing costing snapshots must remain readable

## Testing

Add or update coverage for:

- packaging renders below ingredient costing instead of in a side layout
- costing tab copy uses the simpler packaging wording
- packaging rows default to `1` component per unit
- creating a packaging item from the packaging page persists a reusable record
- creating a packaging item from costing works without leaving the recipe
- `Save and add` inserts the new item into costing immediately
- batch packaging totals multiply correctly by `units produced`
- missing `units produced` shows the expected placeholder state

## Rollout Notes

This pass is about clarity and flow, not about adding more packaging business logic.

If later we want purchase-pack conversion, supplier metadata, or richer catalog management, that should be designed after this simpler structure is stable and understandable.
