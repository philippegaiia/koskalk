---
paths:
  - app/Support/InciName.php
---

# Support

## INCI: normalize() is a matching key, display() is the rendered form
Two different jobs, never conflate them. `normalize()` folds to upper case and is the case-insensitive comparison key behind catalogue dedupe (IngredientCatalogAuditService, CosIngFunctionDataset) and CosIng/FDA GSRS lookups — it is never rendered, and changing it silently re-partitions duplicate detection. `display()` is the human-facing sentence-case form used by dense views via `Ingredient::displayInciName()`; presentation only, it never rewrites the stored value.

In `display()`, two parts keep their stored casing because the casing carries meaning: the parenthetical (per .ai/rules/ingredient-enrichment.md it holds the botanical or common proper name, so "(Kukui)" must not become "(kukui)"), and identifier tokens — a digit anywhere marks a token as an identifier ("CI 77007", "PEG-40"), and an all-caps word only counts as an acronym when it is on the known list, so "Tea" in "Black Tea Extract" folds while "TEA" stays triethanolamine.

Stored INCI is inconsistent on purpose: all-caps, title case and already-lower forms coexist, so never assume a shape when reading `inci_name`.
