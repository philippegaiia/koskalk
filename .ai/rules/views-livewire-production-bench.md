---
paths:
  - 'resources/views/livewire/production-bench/inventory*.blade.php'
---

# Views Livewire Production Bench

## Inventory tables scroll sideways without height caps
Inventory tables must grow with their paginator and keep horizontal scrolling at every width. Use the shared sticky-table-scroll wrapper and mark its thead with wire:ignore.self plus data-sticky-table-header; do not restore max-height or container-query overflow thresholds. Lot Register headings remain on one line.

## Reuse page access and loaded lots for row controls
Lot-register rendering must not invoke database-backed Filament action visibility per row. Use the page's canWriteInventory result and eligibility derived from loaded lot records for display; retain fresh action visibility and server-side authorization on mount/submission. Keep query regression coverage at 25 and 100 lots.
