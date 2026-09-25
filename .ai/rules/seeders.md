---
paths:
  - 'database/seeders/**'
---

# Seeders

## Production platform catalog is updated directly
Platform-ingredient corrections are applied directly to the production database by the owner. Seeders are for fresh/test/reference data and are not the production synchronization mechanism. Do not plan production catalogue cleanup around rerunning ingredient seeders.

## Plan catalogue seeding is strictly create-only
Skip an existing plan by slug entirely, including missing limit/capability keys. Never sync development defaults or change production defaults, billing IDs, entitlements or edited zero/null limits through seed reruns. New offers start non-default and inactive. Existing-plan capability changes require a separately scoped deliberate operation.
