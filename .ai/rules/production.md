---
paths:
  - 'resources/views/livewire/production-bench/production/*.blade.php'
---

# Production

## Production tables use shared sticky behavior
Page-scrolling production tables use the shared sticky-table-scroll wrapper and mark thead with wire:ignore.self plus data-sticky-table-header. Keep headings on one line and set only the table-specific min-width locally. Paginated production registers use the shared 25/50/100 table-pagination control; derived preview/detail tables remain unpaginated. Recipe chooser tables in batch-size-form and task-set-form retain their bounded internal scroller and native sticky thead.
