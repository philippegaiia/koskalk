---
paths:
  - 'resources/views/components/production-bench/**'
---

# Production Bench

## Keep active navigation state independent of Livewire update routes
Production Bench navigation rendered inside a Livewire component must receive its active section from durable component/view state. Do not rely only on request()->routeIs(), because a Livewire re-render runs on the update endpoint and will remove active classes and aria-current.
