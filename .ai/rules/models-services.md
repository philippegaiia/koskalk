---
paths:
  - 'app/{Models,Services}/**'
---

# Models Services

## One Product, many Product sizes
A Product owns one formula and any number of Product sizes (ProductSize). A size is a maker-declared content plus its own packaging lines; it never changes formula composition, INCI or compliance output. Do not duplicate a Product to offer another size; duplicate only for a different formula or brand. Packaging lines stay on the formula version (saved and carried forward with it) and point at a size. Declared content is the maker's own figure: never derive unit counts from it or validate it against batch mass. A basic batch record and a production run still record one size and one unit count; do not split a run across sizes without a deliberate multi-output design (one output lot per run is enforced in the schema).

## Separate finished-product type from calculation family
Product Type is a finished-product classification; Product Family selects the calculation engine. A Product Type may support multiple families. Product Family is immutable after Product creation, and Product Type becomes immutable after the first Saved Formula.

## Keep exact shared lineage separate from current chemistry and original trust
Formula sharing lineage is server-controlled and hidden from browser projections. Exact reuse requires known lineage plus the current recursive technical fingerprint; never overwrite an existing local Ingredient. Exact imports retain the first stored original KOH/fatty-acid trust baseline even when current chemistry was modified. A local substitution keeps its actual local ancestry and requires explicit confirmation on every offer. Only records with owner_type, owner_id and workspace_id all null are platform records at the transfer boundary.
