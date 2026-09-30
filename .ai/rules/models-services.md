---
paths:
  - 'app/{Models,Services}/**'
---

# Models Services

## One production batch produces one Product
A finished production output is the current Product: one production batch produces one Product. Alternate sizes are separate Products created by duplication, and packaging remains Product-level. Do not introduce Product variants or split production outputs without revisiting this decision.

## Separate finished-product type from calculation family
Product Type is a finished-product classification; Product Family selects the calculation engine. A Product Type may support multiple families. Product Family is immutable after Product creation, and Product Type becomes immutable after the first Saved Formula.

## Keep exact shared lineage separate from current chemistry and original trust
Formula sharing lineage is server-controlled and hidden from browser projections. Exact reuse requires known lineage plus the current recursive technical fingerprint; never overwrite an existing local Ingredient. Exact imports retain the first stored original KOH/fatty-acid trust baseline even when current chemistry was modified. A local substitution keeps its actual local ancestry and requires explicit confirmation on every offer. Only records with owner_type, owner_id and workspace_id all null are platform records at the transfer boundary.
