---
paths:
  - 'app/{Models,Services}/**'
---

# Models Services

## One production batch produces one Product
A finished production output is the current Product: one production batch produces one Product. Alternate sizes are separate Products created by duplication, and packaging remains Product-level. Do not introduce Product variants or split production outputs without revisiting this decision.

## Separate finished-product type from calculation family
Product Type is a finished-product classification; Product Family selects the calculation engine. A Product Type may support multiple families. Product Family is immutable after Product creation, and Product Type becomes immutable after the first Saved Formula.
