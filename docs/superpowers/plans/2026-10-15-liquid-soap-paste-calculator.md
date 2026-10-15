# Liquid Soap Paste Calculator Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement the tasks sequentially. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a soaper design a liquid soap made by the paste method: cook a KOH paste (at zero, positive or negative superfat), dilute it a first time, optionally neutralize excess lye, then dilute to a final anhydrous-soap concentration chosen by the user.

**Architecture:** Liquid soap gets a **dedicated liquid-soap paste bench**, separate from the existing soap bench (see Bench boundary). `SoapCalculationService` keeps calculating the paste from the oil weight. A new stateless `LiquidSoapProcessCalculator` takes the paste composition and the process inputs and returns every stage on the anhydrous-soap basis, computed with bcmath on decimal strings. Phase 1 delivers only this calculation contract, its worked examples and tests. Routing, persistence, UI, costing and labelling follow once the calculation is settled.

## Bench boundary

| Area | Rule |
| --- | --- |
| Existing soap bench | Keeps its current workflow, controls, defaults and calculations. No liquid-process stage, setting or default appears there. |
| Liquid-soap paste bench | Its own process view: paste → first dilution → optional neutralization → final dilution. Its alkali solution defaults to 28% concentration. |
| Shared | Paste chemistry (`SoapCalculationService`), ingredient selection, and the alkali controls (water mode, purity, superfat), reused as components rather than copied. Recipe, version, costing and sharing models stay the same. |
| Routing | The **product family** selects the bench. Choosing KOH, or any lye split, never switches benches. |
| Regression | Before any routing or UI change ships, tests must lock the existing soap bench's behaviour: same phases, defaults, preview payload and saved output for NaOH, KOH and dual-lye formulas, including KOH-only formulas in the soap family. |

**Tech Stack:** Laravel 13 services, Pest. Later phases: Livewire + Alpine workbench, Tailwind 4, JS parity tests.

---

## Decisions

| # | Decision | Status |
| --- | --- | --- |
| D1 | Dilution basis is **anhydrous soap mass `A`** (definition below). Reported beside it, never merged: soap salts, glycerine, unreacted lipids, excess alkali, alkali impurities, neutralization products. | agreed |
| D2 | The paste uses the soap bench's alkali solution settings unchanged (all water modes, purity, the engine adjusts the alkali). Liquid formulas default to `lye_concentration` **28%** = pure alkali ÷ (pure alkali + added liquid). The non-active part of KOH flakes (water and carbonate) is **not** counted as water: it is reported as "alkali impurities", is part of the batch mass, and is not part of `A`. Phase 1 accepts KOH-only pastes. | agreed |
| D3 | First dilution target `c₁` defaults to **35%** (usual range 30–40%). | agreed |
| D4 | Final dilution target `c_f` is set by the user and completes the first dilution. | agreed |
| D5 | Neutralization is **optional**. The theoretical dose is a recommendation, shown with a warning. The calculation uses the amount the user **actually** enters, which may be zero. If there is no free alkali, the recommended dose is zero. | agreed |
| D6 | Neutralizers are **citric acid** (anhydrous or monohydrate) and **stearic acid**, with lactic acid as an option. They are accounted for differently (see Neutralization). | agreed |
| D7 | The paste weight is **theoretical (no evaporation)** unless the user enters the **measured cooked-paste weight**, which then replaces it in every later stage. | proposed |
| D8 | The liquid superfat floors (−20% at KOH 90%, −10% at KOH 100%) are **out of scope**. They were added in `05404360` with no documented reason, and the workbench default purity is 90%, so −20% is the floor most users meet. Changing them needs its own decision and a check of saved versions below −10%. | deferred |

## Calculation contract

### Paste composition (from the existing engine)

Built by a small adapter from `SoapCalculationService::calculate()` output. Neutralizers are **not** passed to the engine as post-reaction oils, so free alkali is never counted twice.

| Field | Meaning |
| --- | --- |
| `lipids_weight` | all saponifiable oils in the paste |
| `reacted_alkali_weight` | pure alkali that saponified (capped at full saponification) |
| `free_alkali_weight` | pure alkali left over (> 0 only at negative superfat) |
| `glycerine_weight` | glycerine released by the reaction |
| `soap_salt_weight` | `saponified lipids + reacted alkali − glycerine` |
| `unreacted_lipids_weight` | lipids left unsaponified (positive superfat) |
| `alkali_material_weight` | flakes weighed (`koh_to_weigh`, purity-corrected) |
| `alkali_impurity_weight` | `alkali_material_weight − pure alkali added` |
| `lye_water_weight` | added lye water |
| `theoretical_paste_weight` | `lipids + alkali material + lye water` |

```text
A = soap_salt + glycerine + unreacted_lipids      (= lipids + reacted alkali)
```

Excess alkali, impurities, water, acids and their salts are outside `A`.

### Stages

```text
paste            = measured_paste_weight ?? theoretical_paste_weight
c_paste          = A / paste

first dilution   target₁ = A / c₁
                 add₁ = max(0, target₁ − paste)        status: ok | already_below_target
                 mass₁ = paste + add₁

neutralization   recommended = free_alkali × factor (0 if free_alkali = 0)
                 actual = user input (0 allowed); solution = actual / solution_strength
                 mass₂ = mass₁ + whole solution mass (acid + carrier water)
                 A' = A + soap formed by a fatty-acid neutralizer (citric: A' = A)

additions        mass₃ = mass₂ + post-paste additions (additives, fragrance), in grams

final dilution   target_f = A' / c_f
                 add_f = target_f − mass₃              status: ok | target_exceeded
                 if add_f < 0 → add_f = 0, report achieved concentration A' / mass₃
```

Input errors (rejected): `c₁` or `c_f` outside (0, 100), `c_f > c₁`, negative weights, solution strength outside (0, 100].
Each stage returns `add`, `mass`, `anhydrous_percentage` and `status`. A stage never returns negative water.

### Neutralization

| Neutralizer | Stoichiometry | Recommended g per g free KOH | What it becomes | Effect on A |
| --- | --- | --- | --- | --- |
| Citric acid anhydrous (192.12 g/mol) | 1 acid : 3 KOH | 1.1414 | tripotassium citrate 1.8203 g + water 0.3211 g | none |
| Citric acid monohydrate (210.14) | 1 : 3 | 1.2485 | same + 0.1070 g hydrate water | none |
| Stearic acid | 1 : 1 | 1000 ÷ KOH SAP of the selected ingredient (≈ 5.07 pure, NIST molar masses) | potassium stearate + water 0.3211 g | A' = A + stearic + KOH reacted − water formed |
| Lactic acid (90.08), optional | 1 : 1 | 1.6055 pure | potassium lactate + water | none |

Actual amount different from the recommendation:
- **Under-dosed:** reports `residual_free_alkali_weight`.
- **Citric acid over-dosed:** reports `excess_acid_weight` and warns that excess acid splits soap back into fatty acids, which can cloud the batch.
- **Stearic acid over-dosed:** the extra acid is added to `unreacted_lipids_weight` (raising superfat and opacity).

Warning text attached to every recommendation: "Theoretical amount. Some excess lye is consumed during the cook and by air. Add part of it, mix, test (phenolphthalein or pH), then adjust."

### Result

Final mass, `A'`, final anhydrous %, and each component as grams and % of the final batch: soap salts, glycerine, unreacted lipids, residual free alkali, alkali impurities, neutralization salt, excess acid, total water.

**Mass balance invariant:** final mass equals the sum of every input weighed in (lipids, alkali material, lye water, dilution water, neutralizer solution, additions), with or without a measured paste weight. Evaporation shows up as `measured − theoretical`.

## Worked examples (test fixtures)

Common basis: 1000 g lipids, KOH needed at 0% = 190 g pure, KOH 90%, 28% lye solution, glycerine 0.54714 g per g KOH.

**E1 — 0% superfat, no neutralization**

| Stage | Add | Mass | Anhydrous % |
| --- | --- | --- | --- |
| Paste: 1000 + 211.11 flakes + 488.57 water | — | 1699.68 | 70.01 |
| First dilution, c₁ 35% | 1700.32 | 3400.00 | 35.00 |
| Final dilution, c_f 25% | 1360.00 | 4760.00 | 25.00 |

A = 1190.00 (soap salts 1086.04 + glycerine 103.96). Impurities 21.11 g. Recommended neutralizer: 0.

**E2 — −3% superfat, citric acid anhydrous at the recommended dose, 20% solution**

KOH added 195.70, reacted 190.00, free 5.70. Flakes 217.44, lye water 503.23, paste 1720.67, A = 1190.00.

| Stage | Add | Mass | Anhydrous % |
| --- | --- | --- | --- |
| Paste | — | 1720.67 | 69.16 |
| First dilution, 35% | 1679.33 | 3400.00 | 35.00 |
| Citric 6.51 g as 32.53 g solution | 32.53 | 3432.53 | 34.67 |
| Final dilution, 25% | 1327.47 | 4760.00 | 25.00 |

Potassium citrate 10.38 g. Residual free alkali 0.

**E3 — E2 with stearic acid instead (KOH SAP 197.2)**

Stearic 28.90 g reacts with 5.70 g KOH, forming 1.83 g water. A' = 1190 + 28.90 + 5.70 − 1.83 = 1222.77; final mass at 25% = 4891.10.

**E4 — unreachable final target:** E2 with 1400 g of additions after the paste; final mass would exceed A'/c_f → `target_exceeded`, add 0, achieved concentration reported.

**E5 — measured paste weight:** E1 with a measured paste weight of 1650.00 (49.68 g evaporated). The dilutions add 49.68 g more water, and the final mass is unchanged.

**E6 — positive superfat 3%:** unreacted lipids 30 g are inside A; no neutralizer recommended.

## Phase 1 tasks

- [x] **Write tests first:** `tests/Unit/LiquidSoapProcessCalculatorTest.php` covering E1–E6, the mass-balance invariant (parameterised over the examples), citric anhydrous vs monohydrate, stearic A' change, under-dosed and over-dosed neutralizer, zero neutralizer at negative superfat (residual alkali reported), `already_below_target`, `target_exceeded`, and input errors. Read `.ai/rules/tests.md` and the `testing-best-practices` skill.
- [x] **Paste adapter:** `LiquidSoapProcessCalculator::pasteComposition()` built from `SoapCalculationService::calculate()` output, with tests checking soap salts, glycerine, unsaponified lipids and impurities against hand-calculated values. Read `.ai/rules/services.md` and `actions-services.md`.
- [x] **Calculator:** `app/Services/LiquidSoapProcessCalculator.php`, stateless, molar-mass constants documented like the existing glycerine ratios. Stearic factor comes from the ingredient's KOH SAP passed in, not a constant.
- [x] **Docs:** add the contract to `docs/developer/formulation-engine.md`.
- [x] `vendor/bin/pint --dirty --format agent`, run the new tests, `graphify update .`.
- [x] **Review fixes:** bcmath decimal strings throughout; every numeric input must be a finite decimal (`1e309`, `INF`, `NAN` rejected); measured paste weight cannot be below oils + KOH weighed in; acid beyond the free KOH returns `acid_exceeds_free_koh` with the final dilution `unsupported` and no composition, because the soap-splitting reaction is not modelled; component shares of the final batch in `composition_percentages`. Mass balance holds within the 4-decimal rounding of the reported parts (≤ 0.00045 g).

## Later phases (planned once Phase 1 is settled)

1. **Regression baseline first (done: `tests/Feature/SoapBenchBaselineTest.php`; existing workbench suites cover outputs and save/restore lifecycles):** characterisation tests for the existing soap bench (phases, defaults, preview payload, save/load round-trip, outputs) across NaOH, KOH and dual lye, so later steps prove it is untouched.
2. **Routing (done) and persistence:** `soap_process` on product families (`bar` | `liquid_paste`, nullable for cosmetic, not a new `calculation_basis`) is platform-set in `ProductFamilySeeder` and shown read-only in the admin, because changing it would move every formula of the family to another bench. The `liquid-soap` family is seeded create-only and **inactive** until its bench ships. The soap/cosmetic workbench refuses liquid-paste families at the create page, edit page, Livewire mount and family-slug lookup (404); lye type never routes (`tests/Feature/LiquidSoapFamilyRoutingTest.php`). Persistence (done, `tests/Feature/LiquidSoapFormulaPersistenceTest.php`): liquid families save `calculation_context.liquid_process` (first and final dilution, neutralizer solution strength) as decimal strings, validated by `LiquidSoapProcessCalculator::normalizeSettings()`; they get a `neutralization` phase after the alkali solution holding at most one neutralizer row (% of oils); bar soap ignores both. Platform ingredients carry an admin-set `liquid_soap_neutralizer` type (chemistry stays in code; fatty acids also need trusted SAP); workspace copies do not inherit it yet. The neutralization phase never feeds the engine's post-reaction oils. Philippe adds citric acid and stearic acid to the platform catalog.
   Decided: the weighed cooked-paste weight is a batch measurement, not saved with the formula; the liquid bench offers it as an unsaved field for the live view and printed batch sheet, and the Production Bench can record it per batch later.
   Still open: additives and fragrance as % of the final batch (needs the final mass, so it lands with the preview step); formula sharing for the `neutralization` phase; neutralizer type on workspace duplicates.
3. **Dedicated bench — shape decided:** like the cosmetic bench, the liquid family gets its own formula view (its own partial, JS section and defaults) inside the shared workbench shell, which keeps saving, versions, editing locks, packaging, costing and output. The soap formula tab and its JS stay untouched. No browser copy of the calculator: per the workbench rule the preview stays a debounced server round trip.
   - Slice 1 (done, `tests/Feature/LiquidSoapProcessPreviewTest.php`): `RecipeWorkbenchService::previewSoapCalculation()` adds `liquid_process` for liquid families (stages, neutralizer, composition, target final weight, each addition's weight and % of the final batch); invalid settings report `status: invalid` while the paste preview stays. The Livewire preview resolves the family only for drafts that carry `liquid_process`, so the soap bench's preview path is unchanged. Additions stay stored as weight and % of oils; rows sent with `final_percentage` get their weight from the final batch (`LiquidSoapFormulaProcessor`), at preview and at save. Saving rejects a neutralizer above the free KOH. A weighed paste (`liquid_process.measured_paste_weight`) affects the preview only. Liquid families use a paste-only calculation everywhere (live preview, snapshots, save): saponifiable additions are added after neutralization and take no lye. Additions report `final_percentage` as the achieved share of the batch actually reached and `requested_final_percentage` as sent. Saved, published and loaded version snapshots carry `liquid_process`, keyed on the stored settings that only liquid families have.
   - Slice 2 (done, `LiquidSoapFamilyRoutingTest`, `LiquidSoapBenchClientTest`): liquid families open in the shell once the family is active (`ProductFamily::opensInWorkbench()`), so the seeded inactive family stays hidden. The view data carries `soap_process`; a new liquid formula starts on KOH 90%, a 28% alkali solution, 0% superfat, 35% / 25% dilutions and a 20% neutralizer solution, titled "New liquid soap". `liquid_process` is serialized only for liquid formulas, without the weighed paste, and hydrated from saved drafts while keeping a typed paste weight. The liquid section and its helpers live inside `component.js`, `payload.js` and `snapshot.js` rather than as imports, because existing workbench tests evaluate those files as source with their imports replaced.
   - Slice 3a (done, `LiquidSoapFormulaTabTest`, `LiquidSoapBenchClientTest`): the liquid formula view adds a Liquid soap process section between the oils core and the additions (`liquid-soap-process.blade.php`): dilution targets, neutralizer solution strength, the unsaved weighed paste (sent with previews only), a single neutralizer picker from platform neutralizers with an amount in mass units stored as % of oils and a "use recommended" action, the stage table (add, running total, anhydrous %) with stage status, warnings, and the final composition. The lye type control shows KOH only. All numbers come from the server preview.
   - Slice 3b (done): on the liquid bench the additions table edits additives and fragrance as % of the final batch (Blade branches; the bar soap table renders as before). A typed share is sent as `final_percentage` and sets an interim % of oils; weights and shares otherwise follow the server preview; typing a weight clears the share. Rows loaded from a save carry no share, so loading is not an edit.
   Former plan text:  its own Livewire component, page and Alpine module; reuses the shared ingredient picker and alkali controls; 28% alkali concentration default. Preview payload with the process; additions entered as % of final mass; Alpine mirror with a PHP/JS parity test.
4. **Bench UI:** one process table (stage, setting, add, running mass, anhydrous %), with recommended vs actual neutralizer side by side, a measured paste weight field, and inline status for `already_below_target`, `target_exceeded` and `unsupported`.
5. **Outputs** — part 1 done (`LiquidSoapIngredientListTest`): for a calculated liquid process the label basis is the finished batch with no cure (allergen thresholds use it); AQUA is the water in the bottle in the saponified list and the water weighed in for the incorporated list; free KOH is what remains after neutralization. The neutralizer follows the list variant: saponified list = its potassium salt (POTASSIUM CITRATE / POTASSIUM LACTATE) or, for a fatty acid, its potassium soap plus any unreacted acid; incorporated list = the acid as weighed. Plain-language lists name the neutralizer as added. Part 2 done (`LiquidSoapCostingTest`): the plain-water costing row includes both dilutions and the neutralizer solution water (workbench and saved costing, shared `LiquidSoapFormulaProcessor::addedDilutionWaterWeight()`); the neutralizer is priced as a normal formula row. Four checks that keyed on the `soap` slug now use `ProductFamily::usesSoapCalculation()` (soap family or liquid paste), so liquid soap also gets alkali and water costing rows, the save-time soap calculation check and the soap help topics. Part 3 done (`LiquidSoapBatchSheetTest`): the formula document carries `liquid_process` with the steps in process order (paste, first dilution, neutralization, final dilution: instruction, amount to add, running total, % anhydrous soap), the neutralizer with its theoretical amount, the additions with their % of the final batch, and the process warnings; results show the final batch and anhydrous soap instead of a wet and cured bar, and no cured-soap output is built. The current-formula print and the saved version sheet render it; a weighed paste captured on the bench prints with its batch. Not yet: the Production Bench run sheet. Original item: costing, batch sheet in process order, INCI for neutralization salts (wording confirmed with Philippe), production bench.
6. **D8 floor review.**

Out of scope: dilution liquids other than water, sizing oils from a target final mass, viscosity and clarity modelling.
