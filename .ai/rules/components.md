---
paths:
  - 'app/{Models/Ingredient.php,Enums/IngredientIdentifierScheme.php,Services/IngredientIdentitySynchronizer.php,Forms/Components/IngredientIdentityFields.php}'
---

# Components

## Ingredient identifiers have one source of truth
Persist CAS, EC/EINECS, UNII, ECHA List Number, InChIKey, PubChem CID, and future schemes only in ingredient_identifiers. The prominent cas_number and ec_number form keys are projections of the primary rows, not Ingredient columns; do not reintroduce duplicate columns.
