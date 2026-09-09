---
paths:
  - 'resources/views/livewire/production-bench/inventory*.blade.php'
---

# Views Livewire Production Bench

## Inventory tables scroll sideways without height caps
Inventory tables must grow with their paginator and keep horizontal scrolling at every width. Use the shared sticky-table-scroll wrapper and mark its thead with wire:ignore.self plus data-sticky-table-header; do not restore max-height or container-query overflow thresholds. Lot Register headings remain on one line.
