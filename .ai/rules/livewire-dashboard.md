---
paths:
  - resources/views/livewire/dashboard/ingredients-index.blade.php
  - resources/views/livewire/dashboard/packaging-items-index.blade.php
  - resources/views/livewire/dashboard/packaging-item-editor.blade.php
---

# Livewire Dashboard

## Ingredient catalogue uses shared sticky pagination
The ingredient catalogue keeps its responsive sk-table layout without a hard min-width, but uses the shared sticky-table-scroll wrapper and marks thead with wire:ignore.self plus data-sticky-table-header. Keep the existing shared 25/50/100 table-pagination control.

## Packaging catalogue uses shared sticky pagination
The packaging catalogue keeps its responsive sk-table layout, uses the shared sticky-table-scroll wrapper, and marks thead with wire:ignore.self plus data-sticky-table-header. Keep the existing shared 25/50/100 table-pagination control.

## Packaging edit exposes catalogue return
Show an explicit Back to packaging link above the heading when editing an existing packaging item. Keep it out of create mode because supplier-listing creation can carry its own return flow; the bottom Cancel action remains available.
