---
paths:
  - resources/views/livewire/dashboard/ingredients-index.blade.php
---

# Livewire Dashboard

## Ingredient catalogue uses shared sticky pagination
The ingredient catalogue keeps its responsive sk-table layout without a hard min-width, but uses the shared sticky-table-scroll wrapper and marks thead with wire:ignore.self plus data-sticky-table-header. Keep the existing shared 25/50/100 table-pagination control.
