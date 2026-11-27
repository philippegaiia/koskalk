---
paths:
  - '{resources/css/app.css,resources/views/livewire/dashboard/partials/recipe-workbench/**}'
  - '{resources/css/app.css,resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php}'
---

# Dashboard Partials Recipe Workbench

## Keep formula row spacing in shared CSS
Soap and cosmetic data rows share sk-formula-table-* vertical padding and name/INCI typography in app.css. Keep vertical padding utilities off data-row wrappers and cells, and font-size/leading utilities off name/INCI paragraphs: utilities override component rules even across breakpoints. Horizontal column padding stays in Tailwind; headers/totals/drop zones keep their separate spacing. Preserve 0px mobile handle/action padding and its desktop override.

## Keep catalogue, costing and composition data rows compact
Shared sk-table body cells use 8px top/bottom padding, covering ingredients and packaging without per-table overrides. Both costing grids and their subtotal cells also use 8px vertical padding, and so do the Composition & labeling tables (output-tab, ingredient-list-preview, restrictions-preview) body and total cells (py-2). Keep table headings (py-3) and form-input padding independent.

## Keep composition rows single-line
The cured composition table shows the INCI label alone; the Sources column already names the formula ingredients, so do not repeat them as a second line under the label. Sources render as one comma-separated, truncated line with the full list in the title tooltip, keeping the per-source user-owned dot.
