# Catalog drift audit: identity lookups by classification

Date: 2026-11-21 · Branch: `fix/soap-water-costing` (at `c155ca16`) · Scope: `app/` · Read-only audit

## Question

The costing tab lost its water row when the platform Water row's subcategory drifted to `other_carriers`, because the code looked for water by category + subcategory. `CanonicalSoapWaterResolver` fixed that by looking up catalog key `CH2` first. This audit looks for other places that find **one specific platform ingredient**, or gate soap math, costing or compliance, by `category` / `subcategory` / `display_name` / label text instead of a stable `catalog_key`.

## Headline

- **No other single-ingredient identity lookup uses classification.** NaOH and KOH resolve through `CanonicalSoapAlkaliResolver` by `CH1` / `CH3` only (`app/Services/CanonicalSoapAlkaliResolver.php:69-77`). Water now resolves through `CanonicalSoapWaterResolver` by `CH2`, with a classification fallback. Every costing, output, share and production consumer goes through these two resolvers (`RecipeVersionCostPreviewBuilder:551,605`, `RecipeVersionCostingSynchronizer:696,727,749,786`, `RecipeWorkbenchOutputBuilder:282`, `RecipeCompositionIngredientIdentityResolver:35,41`, `Production/ProductionFormulaSnapshotBuilder:472`).
- **Whether an ingredient drives saponification depends on a flag, not on its category:** `Ingredient::canDriveSoapSaponification()` checks `is_soap_saponification_trusted` plus a KOH SAP value (`app/Models/Ingredient.php:362-366`). An admin who recategorises an oil does not silently change the SAP math.
- **The remaining risk is class gates that use `IngredientCategory::SoapmakingAlkalis`.** These decide whether an alkali may enter a formula outside the lye calculation. Drift here can let a real NaOH/KOH row into a formula as an ordinary additive or lye liquid, where its weight is not counted as alkali. The taxonomy holds two extra alkali rows beyond CH1/CH3 (`ADM-P8YDYPKK` NaOH, `ADM-VSBOZPVX` KOH), so more than one row depends on these gates.
- **Global scopes:** `Ingredient` has **no** global scope. Only `Recipe`, `RecipeVersion`, `RecipePhase`, `RecipeItem`, `Workspace` and `WorkspaceMember` add `OwnedByCurrentTenantScope`. A tenant scope therefore cannot hide a platform ingredient today. The real risk runs the other way: an `Ingredient::query()` lookup that picks *one* row has to filter to platform ownership explicitly, or it may pick a workspace duplicate. Both canonical resolvers do this (`whereNull owner_type/owner_id/workspace_id`, `is_active`). I found no single-row platform lookup that skips it.

## Findings, ranked by risk

### 1. Medium: the water fallback silently prices a different row when CH2 exists but is inactive

`app/Services/CanonicalSoapWaterResolver.php:25-32`

- **Resolves:** the platform water ingredient that soap costing prices. It is also used for the water identity in composition snapshots.
- **Weakness:** the fallback to category `water_solvents_carriers` + subcategory `water` (lowest `id`) also runs when CH2 **exists but is inactive**, not only on catalogs that predate catalog keys. The taxonomy has a second water row, `ADM-ILLF4IRL` (`water_solvents_carriers` / `water`).
- **Concrete failure:** an admin deactivates CH2 in Filament, maybe to replace it. Costing then prices water from `ADM-ILLF4IRL` (or whichever water-classified row has the lowest id), with that row's price and INCI, and gives no warning. If an admin also edits that row's subcategory, water disappears again: the original bug in a new form.
- **Catalog key:** yes, `CH2`.
- **Classification or identity:** identity lookup. Keep the fallback only for catalogs that genuinely lack a `CH2` row. Skip it when a CH2 row exists in any state, or log or flag when it is used.

### 2. Medium: a recategorised alkali stops being blocked as a lye liquid or additive

The four call sites below share one root cause: they recognise an alkali only by `category === IngredientCategory::SoapmakingAlkalis`.

| Site | What it gates | Failure if an admin moves a NaOH/KOH row out of `soapmaking_alkalis` |
|---|---|---|
| `app/Models/Ingredient.php:460` (`availableWorkbenchPhases()`) | Alkalis get **no** workbench phase, so the catalog builder (`RecipeWorkbenchIngredientCatalogBuilder.php:59`) drops them from soap pickers | The row shows up as a pickable **additive**. Its weight goes into the formula as a plain addition: no SAP accounting, no excess-lye warning, and the INCI shows it as an ordinary ingredient. That changes caustic content without the lye math knowing. |
| `app/Services/LyeLiquidIngredientValidator.php:68` | Rejects alkalis as lye-water or dilution liquids | Validation passes. Users can "dissolve the lye in" another NaOH row, which is double alkali that the lye calculation never counts. |
| `app/Services/FormulaSharePreview.php:99` | Rejects importing a shared formula whose lye-liquid row is an alkali | Reads the **snapshot's** `technical.category`, so the sender's drifted category decides. A drifted alkali goes through as a lye liquid. |
| `app/Services/UserIngredientAuthoringService.php:437,469` (+ `assertWorkspaceAuthorableCategory` :771) | Blocks duplicating platform alkalis into a workspace (alkalis are platform-only) | A workspace copy of NaOH becomes possible, outside the canonical resolver and editable by the user. |

- **Catalog key:** yes for CH1 / CH3. The extra `ADM-P8YDYPKK` / `ADM-VSBOZPVX` rows have stable keys but no canonical role.
- **Classification or identity:** these are legitimately **class** decisions ("is this an alkali?"), but they guard soap safety, and the class rests on one editable field. The canonical rows can be pinned by key (`CH1`/`CH3` count as alkalis whatever their category). For the extra rows, either merge them into CH1/CH3 through the existing consolidation dataset, or back the gate with a non-editable flag. Category alone should not decide it.

### 3. Low-medium: a drifted water subcategory turns plain water into a dilution liquid

`app/Models/Ingredient.php:398-413` (`isLiquidSoapDilutionLiquid()`), used at `RecipeWorkbenchIngredientCatalogBuilder.php:106`

- **Decides:** which liquids a liquid-soap paste may be diluted with. Plain water is excluded because it is the step remainder.
- **Concrete failure:** the production drift (Water's subcategory changed to `other_carriers`) makes `subcategory !== Water` true, so CH2 is offered as a dilution liquid row. A user can add water as an explicit row *and* get remainder water. The water total stays arithmetically consistent, but the water is split across two rows, costing counts it in two places, and a duplicate AQUA source feeds the INCI. Moving a water row into `HumectantsPolyols` or `Other` has the same effect.
- **Catalog key:** yes, `CH2` (and `ADM-ILLF4IRL`).
- **Classification or identity:** class decision, legitimately. But "plain water" here means the canonical water identity, so exclude CH2 by key (or by `CanonicalSoapWaterResolver`) as well as by subcategory.

### 4. Low: the cured-soap output finds water by its INCI label "AQUA"

`app/Services/SoapCuredOutputBuilder.php:38,127`, together with the label-keyed rows built in `app/Services/InciGenerationService.php:2224-2290`

- **Decides:** which output row is water when the builder computes residual water and the cured-weight role.
- **Weakness:** the match is on the text label `'AQUA'`. `InciGenerationService` merges rows by label, so any additive whose INCI reads `AQUA` (a user's "distilled water", for example) joins the water row. That row's whole weight is then treated as evaporating lye water.
- **Concrete failure:** it does not depend on catalog drift, but an admin who edits CH2's INCI (say to `WATER`) splits the platform water row from the synthetic `AQUA` row. Cured output then loses its residual-water attribution for the edited part.
- **Catalog key:** n/a. These are synthetic output rows; `kind === 'water'` is already set at the source.
- **Classification or identity:** identity by display string. Match on the row's `kind` (`'water'`), which `appendStandaloneIngredientRow` already sets, not on the label.

### 5. Low: SAP fields hidden in Filament when the category drifts; class-only gates

- `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php:440`: SAP / fatty-acid fields are only *visible* when `categoryMayCarrySoapChemistry()` holds. If an admin moves an oil out of Lipids/Waxes, its stored SAP profile disappears from view, but `canDriveSoapSaponification()` still uses it, because trust is a separate flag. The math is unchanged, but the profile can no longer be audited from the admin form. Admin only.
- `app/Services/RecipeWorkbenchPreviewService.php:425`: the "uncounted post-reaction oils" warning uses `mayCarrySoapChemistry()`. A drifted oil drops the warning only. Math is unaffected.
- `app/Services/UserIngredientAuthoringService.php:1082`: the duplication chemistry preview gives up on a drifted oil. No math impact.
- `app/Services/IngredientFormulaMutationService.php:148-155,578-583`: replacement candidates must share the source's category. Class rule, and the user confirms the result. Drift only narrows or widens the choice list.

### Checked and fine (legitimately classification-based or not an identity lookup)

- `RecipeWorkbenchIngredientCatalogBuilder.php:52`: a `whereIn('category', all cases)` that only excludes rows with an unknown category. It is not a lookup.
- `IngredientShareResolver.php:126`: `display_name` / `inci_name` matches only produce **suggestions** the user must pick from. Platform nodes resolve by lineage key and fingerprint (lines 55-100).
- `IngredientCatalogAuditService.php:51-64`, `IngredientCatalogConsolidationService.php:145`: these *detect* drift against the taxonomy dataset by catalog key. They are the right tool for keeping it in check.
- Console commands (`ReportMissingCarrierOilChemistry`, `ImportCarrierOilChemistryFromMendrulandia`, `DiffCarrierOilsFromCsv`) filter `category = lipids` for batch maintenance, not for runtime math.
- `ProductionDetail.php:400,1203,1280`: `component === 'water'` is the `ProductionFormulaComponent` enum on snapshot lines, not ingredient identity.
- `InciGenerationService.php:650-755`: the label text for derived and lye rows is fixed output wording, not a lookup.
- `liquidSoapNeutralizer()` uses a dedicated `liquid_soap_neutralizer` column plus platform ownership plus trust, which is drift-safe.

## Recommendations

1. **Tighten the water fallback** (finding 1): fall back to classification only when no `CH2` row exists at all, and surface a costing warning or log line whenever the fallback is used.
2. **Pin alkali gates to identity** (finding 2): add an `Ingredient::isSoapAlkali()` that returns true for `CH1`/`CH3` regardless of category, else the category check. Use it in `availableWorkbenchPhases`, `LyeLiquidIngredientValidator`, `FormulaSharePreview` (resolve the local row, not the sender's snapshot category) and the duplication blockers. Separately, decide whether `ADM-P8YDYPKK` / `ADM-VSBOZPVX` should be merged into CH1/CH3 through the consolidation dataset.
3. **Exclude canonical water by key** in `isLiquidSoapDilutionLiquid()` (finding 3).
4. **Match water output rows by `kind`, not the `'AQUA'` label**, in `SoapCuredOutputBuilder` (finding 4).
5. **Schedule or run the catalog audit** (`IngredientCatalogAuditService`) against production and alert on category/subcategory mismatches for keyed rows, especially CH1–CH3. The production drift that caused the water bug would have been reported there first.
6. Add regression tests that drift CH1/CH2/CH3 category/subcategory in a factory and assert that costing, the lye-liquid validator and phase availability are unchanged.
