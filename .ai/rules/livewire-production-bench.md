---
paths:
  - 'app/Livewire/ProductionBench/**'
---

# Livewire Production Bench

## Supplier listings own unit and currency
When a Production Bench flow requires a supplier listing, selecting it must set and lock both the listing unit and listing currency. Persist the listing currency server-side rather than trusting client form state; costs may remain editable for the actual batch.

## Manual stock dates allow today
Manual stock stocked_at is date-only: default to today, allow today and past dates, and reject only future dates. Pass date-only strings to Filament maxDate() so validation does not compare the selected date against a midnight timestamp.
