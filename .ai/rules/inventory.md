---
paths:
  - 'app/Actions/Inventory/**'
---

# Inventory

## Storage assignment is one location per lot
A material lot has at most one optional storage location. Reassignment changes only that reference, never quantities, reservations, costs or stock movements. Missing new-lot input applies the active material default; explicit null means unassigned. Check the enabled switch and tenant ownership under the workspace lock.
