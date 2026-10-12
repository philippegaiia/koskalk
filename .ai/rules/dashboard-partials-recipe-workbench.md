---
paths:
  - '{resources/css/app.css,resources/views/livewire/dashboard/partials/recipe-workbench/**}'
  - '{resources/css/app.css,resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php}'
---

# Dashboard Partials Recipe Workbench

## Keep formula row spacing in shared CSS
Soap and cosmetic data rows share sk-formula-table-* vertical padding and name/INCI typography in app.css. Keep vertical padding utilities off data-row wrappers and cells, and font-size/leading utilities off name/INCI paragraphs: utilities override component rules even across breakpoints. Horizontal column padding stays in Tailwind; headers/totals/drop zones keep their separate spacing. Preserve 0px mobile handle/action padding and its desktop override.

## Keep catalogue and costing data rows compact
Shared sk-table body cells use 8px top/bottom padding, covering ingredients and packaging without per-table overrides. Both costing grids and their subtotal cells also use 8px vertical padding. Keep table headings and form-input padding independent.
