---
paths:
  - 'app/Livewire/ProductionBench/Production/**'
---

# Production Bench Production

## Planning preferences keeps location controls optional
The planning preferences page persists production and storage location flags independently through SaveProductionBenchPreferences. Location manager sections should be nested below the form only when each persisted flag is enabled; the page remains usable before manager components exist.
