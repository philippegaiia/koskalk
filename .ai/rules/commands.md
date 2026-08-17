---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## Synchronize platform taxonomy without reseeding
Use `ingredients:sync-catalog-taxonomy` to preview and `ingredients:sync-catalog-taxonomy --apply --no-interaction` to synchronize exact category, subcategory, and capability metadata on existing platform ingredients. It must not create, delete, merge, or modify workspace-owned ingredients; do not use the full ingredient seeder for deployment taxonomy updates.
