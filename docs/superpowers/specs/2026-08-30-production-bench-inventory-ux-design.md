# Production Bench Inventory UX

## Goal

Improve Inventory without replacing its stock engine. The module must answer three distinct questions:

1. What is the current and forecast position of each material?
2. Which physical lots make up that position?
3. What was received, consumed, or otherwise moved during a selected period?

The design keeps material-level decisions, lot-level traceability, and movement history distinct while linking them directly.

## Information Architecture

Inventory keeps two subnavigation entries:

- **Stock by material**: one row per ingredient or packaging item, and the default Inventory entry.
- **Lot register**: one row per physical stock lot.

The previous labels **Materials** and **Stock** are replaced because they do not describe the row grain. Reporting does not become a third submenu initially. A dedicated material detail page provides the useful history in context, while a future global movement report remains outside this scope.

Clicking a material opens its dedicated detail page. **View all lots** routes to the Lot register with that material preselected; it is not a modal. This preserves pagination, browser history, shareable filters, and access to exhausted lots.

## Tracked Materials

An ingredient or packaging item belongs to Stock by material when the workspace has at least one of:

- planned production demand;
- a supplier listing, including a deactivated historical listing;
- a stock lot;
- workspace material settings such as a configured buffer quantity.

This prevents a configured or historically stocked material from disappearing merely because it has no current production demand.

## Stock by Material

The existing simple comparison table remains the primary presentation. It is not replaced by cards.

The columns are:

1. Material
2. Physical
3. Available
4. Reserved
5. Quarantined
6. Incoming
7. Required
8. Forecast

**Available** is immediately to the right of **Physical**. The quantities retain their existing meanings:

- Physical is the signed sum of movements across every lot, including quarantined and reserved stock.
- Available is released physical stock minus active reservations. Across the material, this is physical minus quarantined minus reserved.
- Incoming is the outstanding quantity on ordered or partially received purchase-order lines.
- Required is remaining planned production demand.
- Forecast is available plus incoming minus required.

The default order remains operational: negative forecasts first, then other planned materials, then natural material-name order. Users can explicitly sort by material name ascending or descending and by the principal numeric columns. Sorting must be represented in durable URL-bound state.

### Search

One search field matches, as applicable:

- base and localized display names;
- INCI, sodium-soap INCI, and potassium-soap INCI names;
- ingredient aliases;
- workspace material code;
- packaging name and code;
- supplier SKU and supplier-facing item name.

Search, filtering, sorting, and pagination occur server-side. The richer catalogue must not require loading every workspace material into PHP before pagination.

### Filters

Search and Sort remain directly visible. Other controls live behind one **Filters** action so the page is not dominated by taxonomy controls.

The filter panel contains searchable comboboxes or compact state controls for:

- ingredient or packaging;
- negative forecast;
- below configured buffer;
- quarantined stock;
- incoming stock;
- with or without planned demand;
- category;
- subcategory.

Subcategory is unavailable until a category is chosen and then contains only that category's subcategories. Applied filters appear as removable chips. The negative-forecast summary can activate the same filter directly.

### Visual Treatment

Tables reuse the application's existing table classes, borders, typography, spacing, and semantic colour variables. No new table-specific palette is introduced.

Negative forecast and other danger states use the established light danger background and strong danger text so the value remains readable at accessible contrast. Buffer warnings use the established light warning treatment. Colour is never the only signal; each state also has text or an icon with an accessible label.

## Workspace Buffer Stock

There is currently no per-material stock alert. Add an optional workspace-specific **buffer stock** quantity for ingredients and packaging that a workspace uses routinely.

Buffer stock is intentionally separate from forecast:

- **Negative forecast** means planned demand exceeds available plus incoming stock.
- **Below buffer** means currently available stock is below the configured buffer quantity.

The comparison uses **Available**, not Physical. Reserved or quarantined quantities are in the building but cannot satisfy an unexpected uncommitted need. A material may therefore be below buffer while still having a positive forecast, or have a negative forecast while remaining above its buffer before planned demand is applied.

Store the optional quantity in canonical units in a new workspace-material settings model. Do not add it to `ingredients`, because platform ingredients are shared between workspaces. Do not add it to `workspace_ingredient_codes`, because that record is deleted when no material code is configured and it cannot represent packaging.

The settings table supports exactly one subject per row: either an ingredient or a packaging item. Database constraints and subject-specific unique indexes enforce one settings row per workspace and material. The first version stores only the nullable buffer quantity; reorder targets, lead times, notification delivery, and automatic purchase-order creation are excluded.

Users configure or clear the buffer from the material detail page. Clearing the buffer removes the setting when it contains no other values.

## Material Detail

Clicking a row opens a dedicated, workspace-authorized material page. A modal or expanded table row is insufficient for period reconciliation, lots, and source-record navigation.

### Current Position

The header shows the current values for Physical, Available, Reserved, Quarantined, Incoming, Required, and Forecast. These values never change when the history period changes.

The page also shows the optional buffer quantity and whether current Available stock is below it.

### Open Lots

The initial lot section shows lots with a non-zero physical balance, including released, quarantined, and negative exception balances. Each row shows:

- internal lot code;
- material;
- supplier name;
- supplier batch number;
- stocked date and expiry when present;
- physical, reserved, and available quantities;
- released or quarantined state.

Supplier name resolves through the receipt or supplier listing relationship. Missing historical supplier context displays a neutral fallback rather than breaking the page.

**View all lots** routes to Lot register with the material filter applied and scope set to All. Individual lot controls remain in Lot register.

### Period Activity

Period presets are:

- Last 30 days;
- Last 365 days;
- Custom period.

The selected period updates:

- purchased/received quantity;
- production-consumed quantity;
- other inbound and outbound movements;
- net physical stock change;
- opening and closing physical balances.

The page displays the reconciliation:

`Opening physical + received + other inbound - production consumed - other outbound +/- adjustments = closing physical`

Consumption is read from posted production-consumption stock movements, including consumption from aborted productions. It is never inferred from the stock delta. Receipt reversals, production corrections, stock-count adjustments, damage, samples, internal use, shipments, and production output remain visible in their correct signed movement groups. Period boundaries use movement occurrence timestamps consistently.

Activity rows link to their originating receipt, production run, or available detail. Inventory does not duplicate receipt posting, reversal, production completion, or correction workflows owned by Purchasing and Production.

## Lot Register

The former Stock page becomes **Lot register**.

Its default scope is **Open lots**. Open includes every non-zero physical balance so negative exceptions are not hidden. **Exhausted** contains zero-balance lots with no active reservation. **All** includes both. Exhausted lots are never deleted or archived out of the audit trail.

The register supports filters for:

- material;
- Open, Exhausted, or All;
- released or quarantined state;
- supplier;
- origin;
- stocked or received period;
- expiry state or date.

Material, internal lot code, supplier batch, supplier name, supplier SKU, and supplier-facing item name are searchable. The register remains database-paginated and defaults to the current recent-lot ordering unless the user selects another supported sort.

## Data and Service Boundaries

The current immutable ledger remains authoritative:

- `StockMovement` determines physical balances and period movement totals.
- active `StockReservation` records determine reserved quantities.
- purchase-order lines determine incoming quantities.
- production requirements determine planned demand.
- stock lots provide batch, status, supplier, expiry, and costing traceability.

Material-level catalogue resolution, filtering, and pagination belong in a focused query/service rather than continuing to grow the Livewire component. Material detail period aggregation belongs in a separate read service. Neither service posts movements or changes production or purchasing records.

Every query remains explicitly workspace-scoped. Source links are shown only when the current user can access the target record.

## Validation and Empty States

- Buffer quantities are optional, non-negative, and expressed in the subject's canonical stock unit.
- A settings row must reference exactly one accessible ingredient or packaging item in the workspace context.
- Custom periods require a valid start date not after the end date.
- No configured buffer means no buffer comparison or warning.
- No open lots presents a neutral message and still offers View all lots when history exists.
- No period activity distinguishes no movements from a failed query.
- Unsupported or tampered filter, sort, material, and period values fall back safely or return validation errors without leaking another workspace's records.
- Read-only Production Bench access may view inventory and history but cannot change lot state or buffer settings.

## Testing

Tests must prove:

- submenu labels and routes identify Stock by material and Lot register;
- the material table keeps its simple table structure and the exact Physical-then-Available column order;
- physical, available, reserved, quarantined, incoming, required, and forecast calculations retain current semantics;
- material-name sorting and principal numeric sorting are deterministic;
- search matches INCI variants, localized names, aliases, material codes, supplier references, and packaging fields;
- category and dependent subcategory filters work without exposing irrelevant subcategories;
- negative forecast and below-buffer filters remain independent;
- buffer settings are workspace-specific, support ingredients and packaging, use canonical units, and compare against Available;
- a settings-only, lot-only, listed-only, and demanded-only material each appears in the tracked catalogue;
- material detail current positions are unchanged by period selection;
- 30-day, 365-day, and custom-period totals reconcile opening and closing physical balances;
- receipt reversals, aborted-production consumption, corrections, adjustments, damage, samples, and other movement types land in the correct signed groups;
- open lots include supplier name and exclude exhausted lots, while View all lots routes to a material-filtered Lot register;
- exhausted lots remain queryable and are never deleted;
- database pagination and bounded query counts hold for large catalogues and lot histories;
- workspace authorization, read-only behavior, localization, and accessible non-colour state labels are preserved.

After PHP changes, the affected Pest tests, Pint, Filacheck for any Filament changes, and Graphify update must run according to repository rules.

## Acceptance Criteria

The redesign is complete when a user can quickly find a material by operational or INCI identity, compare physical and usable stock, isolate negative forecasts and buffer warnings, open a material to reconcile receipts and consumption for a selected period, inspect its supplier-aware open lots, and reach its complete lot history without losing traceability or duplicating Production and Purchasing workflows.
