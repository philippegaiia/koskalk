# Liquid soap dilution liquids — design

**Goal:** on the liquid soap paste bench, let a soaper replace part of the water of the first and/or final dilution with other liquids (hydrosols, milk, aloe juice, glycerine, bought distilled water), and carry those liquids through the calculation, labels, costing, batch sheet, Production Bench and sharing.

**Decisions (agreed with Philippe, 2026-10-17)**

| # | Decision |
| --- | --- |
| L1 | Both the first and the final dilution get their own liquid rows. The neutralizer solution stays plain water. |
| L2 | A row's amount is a **% of that step's water**, like the alkali solution's liquid rows. Grams follow the dilution targets and the batch size. |
| L3 | A liquid counts by its **whole weight** in the dilution maths. Its solids stay in the batch but are never anhydrous soap. No water-content data is added to ingredients. |
| L4 | Storage uses two new formula phases, `first_dilution` and `final_dilution`, rather than JSON settings or a per-row step field. |

Out of scope: water content of liquids, liquids in the neutralizer solution, bar soap.

## 1. Formula and calculation

- `RecipeWorkbenchPhaseBlueprints::soapPhases(withNeutralization: true)` adds `first_dilution` and `final_dilution` (phase type `post_reaction`, system phases). Liquid soap order: saponified oils, alkali solution, first dilution, neutralization, additives, fragrance, final dilution. Bar soap never gets them. `find()` indexes them, so sharing validation by slug accepts them.
- A row: an ingredient and its percentage of the step's water. Each step holds at most 4 rows whose percentages total at most 100%. Alkalis are refused. Validation reuses `LyeLiquidIngredientValidator` and `LyeLiquidAllocationService`, parameterised by phase key so error messages name the right step (today they hard-code `phase_items.lye_water`).
- `LiquidSoapProcessCalculator` is unchanged: a step's `add_weight` is the whole dilution mass (L3). `LiquidSoapFormulaProcessor` gains a method that splits each step's `add_weight` into liquid rows and the remaining plain water with the allocation service. The preview (`RecipeWorkbenchPreviewService::previewLiquidSoapProcess`) returns, per step, `liquids: [{ingredient_id, percentage, weight}]` and `water_weight`.
- Stored rows keep `percentage` = % of step water and `weight` = grams from the save-time process, so a saved version reads back the same way the alkali solution rows do.
- A step that adds nothing (`already_below_target`, or a final dilution reduced to 0 by `target_exceeded`) gives its liquids 0 g, and the process returns a `dilution_liquid_unused` warning naming the step.
- `addedDilutionWaterWeight()` (costing and labels) returns only the plain water: dilution water minus the liquid rows, plus the neutralizer solution water.

## 2. Outputs

- **Labels (`InciGenerationService`):** each liquid becomes an ingredient context at its process weight in its step phase, listed under its own INCI in both list variants and in the label composition panel. `bottledWaterWeight()` and `addedWaterWeight()` subtract the liquids, so AQUA is plain water only and the totals stay at 100%.
- **Costing:** each liquid is a normal priced row in its step's group (workbench `costing-section.js` and saved `RecipeVersionCostPreviewBuilder`), weighed from the process. The implicit Water row drops to plain water. Bought distilled water is just a liquid at 100% of a step.
- **Batch sheet (`FormulaDocumentBuilder::liquidProcessDocument`):** each dilution step's instruction lists what to add, e.g. "Add 700 g water and 300 g lavender hydrosol to bring the paste to 35.0% anhydrous soap". Like the neutralizer, the liquids appear in the process steps rather than in the formula sections.
- **Production Bench:**
  - `ProductionRequirementBuilder` scales the rows of both phases from their saved weight (`scaledSavedMass`, as for lye liquids), with `percentage_snapshot` recomputed from mass.
  - `ProductionFormulaSnapshotBuilder::liquidWaterLines` subtracts each step's liquid requirements from its water line. The water lines already use the `first_dilution` and `final_dilution` phase keys, so the liquids print in the same sections, in process order.
  - Rescaling keeps working because every line stores a basis percentage.
- **Sharing:**
  - `FormulaShareSnapshotBuilder` captures both phases.
  - `FormulaSharePreview::resolve` validates their rows with the dilution liquid validator, as it does for `lye_water`.
  - `FormulaShareRecipeImporter::preserveQuantities` skips them like `lye_water`, because their percentage is not a % of oils; publication recomputes their weights from the process.

## 3. Bench screen

- In `liquid-soap-process.blade.php`, the first and final dilution rows each get a compact **Liquids** line: an ingredient picker (non-alkali ingredients, as for the alkali solution) and a % of that step's water, up to 4 per step, with a remove action.
- The stage table shows each liquid's grams under its step, next to the plain water. All amounts come from the server preview, never a browser recalculation.
- `component.js`, `payload.js` and `snapshot.js` serialise and hydrate the two phases for liquid formulas only, so bar soap payloads stay identical.
- Accessibility: every input gets an `aria-label` naming the step and the liquid, and the remove buttons are labelled.

## Error handling

- More than 4 rows, or a step total above 100%: validation error on that step's phase, at save and at preview (preview reports `status: invalid` while the paste stays visible).
- An alkali chosen as a dilution liquid: refused, as for the alkali solution.
- A neutralizer above the free KOH: unchanged.

## Testing

- **Calculation:** split of a step's water into liquids and plain water; 100% liquid; unused step warning; mass balance still equal to every input weighed in.
- **Persistence:** save and reload of both phases; bar soap gets neither phase (`SoapBenchBaselineTest` keeps passing).
- **Labels:** liquid INCI rows at process weight; AQUA reduced; panel and list totals at 100%.
- **Costing:** liquid rows priced; Water row reduced, in the workbench (node) and saved costing.
- **Batch sheet:** step instruction lists water and liquids.
- **Production:** requirements for liquids; water lines reduced; total planned mass still equals the final batch; rescale.
- **Sharing:** round trip of a formula with liquids in both steps.
- **Bench client (node):** serialisation and hydration of the two phases; bar soap payload unchanged.
