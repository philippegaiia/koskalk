---
paths:
  - '{resources/views/livewire/production-bench/purchasing/procurement-index.blade.php,resources/views/livewire/production-bench/purchasing/receipt-detail.blade.php}'
---

# Production Bench Purchasing

## Purchasing status and recovery actions use fulfilment language
Purchase-order indexes label partially_received as Incomplete and received as Complete, using the same badge tones as receipt fulfilment. When an order still has outstanding packs, its receipt detail links directly to a new purchase-order receipt preselected for that order; this includes orders reopened by receipt reversal.
