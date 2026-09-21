---
paths:
  - '{resources/css/app.css,resources/views/livewire/dashboard/partials/recipe-workbench/**}'
---

# Dashboard Partials Recipe Workbench

## Keep formula row spacing in shared CSS
Soap and cosmetic data rows share sk-formula-table-* vertical padding and name/INCI typography in app.css. Keep vertical padding utilities off data-row wrappers and cells, and font-size/leading utilities off name/INCI paragraphs: utilities override component rules even across breakpoints. Horizontal column padding stays in Tailwind; headers/totals/drop zones keep their separate spacing. Preserve 0px mobile handle/action padding and its desktop override.
