---
paths:
  - '{app/Livewire/**,app/Services/Inventory/WorkspaceMaterialInventoryQuery.php,resources/views/components/table-pagination.blade.php}'
---

# Inventory Views Components

## Custom tables offer ten rows per page
Custom paginated tables offer 10 alongside their existing sizes; the standard selector and server allow-lists use 10, 25, 50, 100 with default 25. Changing size resets the corresponding paginator. This supersedes older 25/50/100-only rules for production and purchasing. Keep Filament table pagination unchanged; specialized selectors already offering 10 retain their existing sizes.
