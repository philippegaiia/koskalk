> **Historical analysis — superseded for implementation.** Use [Contextual Print and Export Implementation Plan](2026-09-28-export-print-tier1-implementation.md), which incorporates the final UX review and current code findings. This document is retained as background; its task priorities and technical claims are not implementation instructions.

# Export & print actions — per-view design

Analysis only. No implementation. Goal: decide, view by view, what is genuinely worth
printing or exporting, and in what order to build it.

## Baseline: what exists today

| Capability | Where | Gap |
| --- | --- | --- |
| XLSX export | Saved recipe only (`recipes.export.xlsx`) | Not reachable from the workbench draft |
| CSV export | Saved recipe only (`recipes.export.csv`) | Same |
| Print working sheet | Saved recipe / version | Not reachable from the workbench draft |
| Print batch snapshot | Production **batches** (`production-batches.print`) | Production **runs** cannot print |
| Print PO / quotation | `purchasing.documents.print` | Fine as-is |
| Ingredient / fatty-acid export | Filament admin only | Not in the app UI |
| Anything in Production Bench | — | **No export of any kind** |

There is no PDF library. "PDF" means browser print-to-PDF, which is fine and should stay
that way — it keeps print artifacts as HTML/CSS and avoids a rendering dependency.

**Two structural findings that shape the plan:**

1. **All export/print lives on the saved product sheet, not where the work happens.** A
   formulator working in the soap bench has to publish first, navigate to the product
   sheet, then export. The workbench — the surface where users spend their time — has
   zero export and zero print.
2. **Five print routes render one identical page.** `print.recipe`, `print.production`,
   `print.details`, `print.technical` and `print.costing` all funnel into
   `RecipeController::printSheet`, which accepts `$printMode` and never passes it to the
   view (`app/Http/Controllers/RecipeController.php:813-836`). So "print costing" and
   "print technical" do not exist as distinct artifacts despite the routes implying they
   do. Fix or remove these before adding new print affordances on top.

## The test each candidate has to pass

- **Print** = an artifact someone uses *away from the screen*, usually with a pen: a
  worksheet at the bench, a picking list in the storeroom, a signed batch record, a
  label-ready INCI block.
- **Export** = data someone works with *somewhere else*: costing in Excel, price
  comparison, accounting, traceability.

If a view's best artifact is neither, it gets nothing. That is the main defence against
over-building.

---

## 1. Recipe workbench (soap / cosmetic bench)

Tabs: formula · packaging · costing · output · instructions.

### Formula tab

| Action | Artifact | Priority |
| --- | --- | --- |
| **Print batch worksheet** | Scaled ingredient list, lye/water & superfat, weigh-off checkboxes, date / made-by / checked-by, notes | **Tier 1** |
| **Export ingredient batch (XLSX)** | Scaled weights per ingredient, for costing or sending to a contract manufacturer | **Tier 1** |
| Print fatty-acid / soap analysis | Add-on page for the soap family | Tier 2 |
| Export CSV | Plain fallback of the above | Tier 3 |

The worksheet is the highest-value artifact in the whole app: it is the thing that
physically sits next to the scale. `resources/views/recipes/print.blade.php` already
renders it well — the work is making it reachable from the draft and correctly scaled,
not redesigning it.

### Costing tab

| Action | Artifact | Priority |
| --- | --- | --- |
| **Export cost breakdown (XLSX)** | Per ingredient: qty, unit cost, line cost, share of total; then unit cost, units produced, cost per unit | **Tier 1** |
| Print costing one-pager | For a pricing decision or filing | Tier 2 |

Costing is currently **not exported at all** — the existing exporter carries ingredient
rows only. This is the canonical "give me the formulas in Excel" request, and it is the
single biggest functional gap after the worksheet.

### Output (composition & labeling)

| Action | Artifact | Priority |
| --- | --- | --- |
| **Print INCI declaration** | Clean, label-ready typographic block; no UI chrome | **Tier 1** |
| Export composition / INCI (CSV) | For label software or the product information file | Tier 2 |

This is a compliance artifact that ends up on a physical label or in a PIF document. It
needs print typography, not a table dump.

### Packaging tab

| Action | Artifact | Priority |
| --- | --- | --- |
| Export packaging BOM | As an extra sheet in the combined workbook — **not its own button** | Tier 2 |
| Print | — | none |

### Instructions & media

| Action | Artifact | Priority |
| --- | --- | --- |
| Print method card | The procedure on its own, without the full formula table, for the workshop | Tier 2 |
| Export | Prose does not belong in a spreadsheet | none |

### Cross-tab recommendation

One contextual action in the workbench bar whose label and output follow the active tab
(formula → worksheet, costing → costing sheet, output → INCI sheet), plus one always
available "Export workbook (XLSX)". Two affordances for five tabs. This is how users
"naturally find the action they need" without a six-button toolbar.

---

## 2. Dashboard lists

| View | Action | Artifact | Priority |
| --- | --- | --- | --- |
| **Ingredients index** | Export | Ingredient catalog with prices — effectively the user's price book | **Tier 1** |
| Packaging items index | Export | Packaging catalog with prices | Tier 2 |
| Products / recipes index | — | A product list has little downstream use | none |

The ingredient export is high value because the data already exists in the admin panel
(`app/Filament/Exports/IngredientExporter.php`) but is missing from the UI users actually
see.

---

## 3. Production Bench — Inventory

### Materials tab

| Action | Artifact | Priority |
| --- | --- | --- |
| **Export stock position** | Material, code, unit, physical, available, reserved, quarantined, incoming, required, forecast | **Tier 1** |
| **Print stock-count sheet** | Same rows plus an empty "counted" column and a signature line | **Tier 1** |

Two artifacts from one table, and both genuinely wanted: the export is for analysis, the
print is for walking the storeroom with a pen. This is the literal "stock report" ask.

### Stock register (lots)

| Action | Artifact | Priority |
| --- | --- | --- |
| **Export lot register** | Lot code, supplier batch, expiry, qty, status, location, supplier | **Tier 1** |
| Print | — | none |

Traceability and recall. The advanced filters (status, supplier, date basis, expiry,
location) make this one worth respecting carefully.

### Material detail

| Action | Artifact | Priority |
| --- | --- | --- |
| Export movement history | Opening, received, consumed, adjustments, closing for the selected period | Tier 2 |
| Print stock card | — | Tier 3 |

---

## 4. Production Bench — Production

| View | Action | Artifact | Priority |
| --- | --- | --- | --- |
| **Run detail** | Print | Batch production record: planned vs actual materials, lots used, variance, tasks, sign-off boxes | **Tier 1** |
| **Run detail / stock preparation** | Print | Weighing & picking list: material, planned qty, lot, location, expiry, empty "actual" column | **Tier 1** |
| Run detail | Export | Same as CSV, for costing the run afterwards | Tier 2 |
| Runs index | Export | Production register: date, product, identifier, qty, status, location | Tier 2 |
| Runs index | Print | Weekly production schedule for the workshop wall | Tier 2 |
| Task index | Print | Task list for today or per department — a work order | Tier 2 |
| Flash planner | Export | Requirements + cost estimate (desired units, whole batches, material, current price, estimated cost) | Tier 2 |
| Calendar | — | Calendar printing is fiddly and low-yield; the runs-index schedule print covers it | none |

The picking list is nearly free: `stock-preparation` already computes proposed/missing
lots with location and expiry on screen. Printing it is a thin view over existing data.

---

## 5. Production Bench — Purchasing

| View | Action | Artifact | Priority |
| --- | --- | --- | --- |
| Procurement detail | Print | PO / quotation — **already exists**, keep as-is | done |
| **Supplier listings** | Export | Supplier price list: material, code, supplier, purchase format, net qty, latest price, status | **Tier 2** |
| Procurement index | Export | Procurement register: reference, supplier, status, currency, total | Tier 2 |
| Receipt detail | Print | Goods received note, to file with the delivery note | Tier 2 |
| Receipt index | Export | Goods-received register — mostly overlaps the procurement register | Tier 3 |

---

## Priority summary

**Tier 1 — build first (10)**

1. Workbench · formula → print batch worksheet, scaled to the current batch size
2. Workbench · costing → export cost breakdown (XLSX)
3. Workbench · output → print INCI declaration
4. Workbench · formula → export ingredient batch (XLSX), from the draft not just saved recipes
5. Inventory · materials → export stock position
6. Inventory · materials → print stock-count sheet
7. Inventory · stock register → export lot register
8. Production · run detail → print batch production record
9. Production · run / stock prep → print picking list
10. Ingredients index → export ingredient catalog with prices

**Tier 2 — worth having**

Costing one-pager · INCI CSV · method card · packaging catalog · movement history ·
production register · weekly schedule · task list · flash-planner requirements ·
supplier price list · procurement register · goods received note

**Tier 3 / skip**

CSV variants where XLSX is the real need · stock card · receipt register ·
restrictions/IFRA export (until compliance lands)

## Cross-cutting rules

1. **Export mirrors the screen.** Respect current search, filters, sort, and for recipes
   the selected scale. State it in the UI: *"Exports current view · 42 rows"*. An export
   that silently ignores filters is the most common source of mistrust in these features.
2. **One primary action per view.** Secondaries go in a small overflow. No six-button
   toolbars.
3. **Prefer one XLSX with several sheets** over several CSVs when the data is one subject
   (recipe: ingredient batch + soap output + costing + INCI). Keep CSV as the plain
   fallback, not a parallel product.
4. **Reuse existing conventions.** Export: `App\Services\*Exporter` returning a string →
   `response()->streamDownload`, throttled `workspace-exports`. Print: standalone Blade
   view + `window.print()` (`data-print-document`), throttled `workspace-prints`. Both
   limiters already exist in `app/Providers/AppServiceProvider.php`.
5. **Fix the five print routes first**, either by making them distinct artifacts or by
   collapsing them to one.

## Explicitly out of scope

- Print/export on: dashboard home, settings, workspace members, media library, calendar.
- Exporting prose (instructions, descriptions, SOP documents).
- Per-row export buttons, or export of bulk-selected rows.
- A generic "export anything" report builder.

## Open questions

- Should the cost breakdown expose purchase prices to users without purchasing
  permissions, or is it owner-only?
- Does the batch production record need to be retained as a signed PDF, or is a printed
  and filed paper copy sufficient for the user's compliance obligations?
- Is the stock-count sheet expected to round-trip (print, count, type back in), or is
  paper-to-spreadsheet acceptable for now?
