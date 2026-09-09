---
paths:
  - '{app/Livewire/ProductionBench/Purchasing/{ReceiptIndex,ProcurementIndex}.php,resources/views/livewire/production-bench/purchasing/{receipt-index,procurement-index}.blade.php}'
  - '{app/Livewire/ProductionBench/Purchasing/ReceiptDetail.php,resources/views/livewire/production-bench/purchasing/receipt-detail.blade.php}'
---

# Purchasing

## Receipt list status reflects order fulfilment
In the receipt index, purchase-order receipts show Complete or Incomplete from the current purchase-order status. Direct receipts show Posted. Reversed always takes precedence, because the receipt no longer contributes to stock or order fulfilment.

## Incomplete receipt detail keeps outstanding order lines visible
For purchase-order receipts, keep the immutable receipt lines under Received items and separately show every order line whose posted received packs remain below ordered packs. Label the second group Still to receive so users can see that unreceived items remain on the purchase order.

## Purchasing registers use shared sticky pagination
Receipt and purchase-order registers use the shared sticky-table scroll wrapper and marked table header at every width, without a table height cap. Keep their page-size controls aligned with inventory: length-aware pagination with an allow-list of 25, 50, and 100 rows, resetting to page one when the size changes.
