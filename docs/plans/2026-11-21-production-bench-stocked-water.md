# Production Bench: optional stocked water

Status: draft plan, no code changed. Date: 2026-11-21.
Follows PR #7 (Recipe Bench water costing via catalog key `CH2`, batch snapshots marked "cost incomplete" for unpriced rows).

## Goal

Soapmakers who buy distilled water, or companies that keep reverse-osmosis water in stock, can reserve, consume and cost the water in soap productions. Tap-water users see **no change at all**: no new requirement, no reservation, no start block, no new field.

Cosmetic formulas are out of scope. Their water is an ordinary recipe item, so it is already a stocked requirement (`ProductionRequirementBuilder` → recipe item → reservation → consumption → cost).

## Current behaviour (confirmed in code)

| Place | What happens to soap water today |
| --- | --- |
| `ProductionFormulaSnapshotBuilder::calculatedLines()` (~l.218–253) | Builds NaOH, KOH and Water candidates. `alkaliIngredientFor()` returns an ingredient only for NaOH/KOH, so the Water line gets `ingredient_id => null`, `component => Water`, phase `lye_water`. Mass is `liquid_composition.water_weight` (plain water only; dilution liquids such as milk are already recipe-item requirements). |
| `ProductionFormulaSnapshotBuilder::liquidWaterLines()` (~l.347–390) | Liquid soap paste: up to three Water lines (`first_dilution`, `neutralization` = neutralizer solution water, `final_dilution`), all `ingredient_id => null`. Dilution liquids planned as materials are already subtracted. |
| `ProductionCalculatedRequirementBuilder::build()` | Turns only `Naoh`/`Koh` lines into `kind = ingredient` requirements. Water never becomes a requirement. Called from `CreateProductionDraft` (~l.242) and `ProductionAvailabilityPreview` (~l.104). |
| `StartProduction` | `isFullyReserved()` loops over requirements only, so water never blocks start. After the checks it copies `planned_mass_grams` into `actual_mass_grams` on every Water line that has no actual yet. |
| `SaveProductionActuals` | `calculatedRows` are accepted **only** for `component = Water` lines and write `actual_mass_grams` directly on the formula line (no lot, no consumption). |
| `ProductionCompletionService` | `assertCompletable()` refuses completion when any Water line has a null or ≤ 0 actual. `defaultCalculatedLyeActuals()` turns active NaOH/KOH reservations into consumption rows when the user entered none. Costing (~l.100–176) sums consumption rows × lot unit cost; water has no consumption, so it is never costed. |
| `ProductionDetailPresenter::materials()` | Water lines get no requirement (`requirement = null`, reservation "not tracked") and use `waterActual()` (the calculated-row input). |
| `ProductionSnapshotRescaler` | Rescales every formula line from `basis_percentage_snapshot` and every ingredient requirement from `percentage_snapshot`, keeping row ids. |
| `WorkspaceMaterialCatalog` | A material "belongs" to a workspace through demand, a supplier listing (active or not), a stock lot, or a material setting. |
| `CanonicalSoapWaterResolver` | Resolves the active platform water by catalog key `CH2`, falling back to category/subcategory. Never a workspace duplicate. |

## Design

### 1. The rule: "this workspace stocks water"

A workspace stocks water when **all** hold:

1. `CanonicalSoapWaterResolver::resolve()` returns an ingredient (call it *W*), and
2. the workspace has at least one `SupplierListing` with `ingredient_id = W.id` (active **or** inactive, same reasoning as `WorkspaceMaterialCatalog::listedKeys()`), **or** at least one `StockLot` with `ingredient_id = W.id` (any status, including depleted).

Deliberately excluded:

- **Demand** (`ProductionRequirement`): would be circular — once one production tracks water, every later one would too, even after the workspace stopped buying it.
- **`WorkspaceMaterialSetting`** (safety buffer): left out of v1; see open question Q3.
- **Other water ingredients** (a private "RO water" or another platform water row): not detected. Only *W* counts, matching the Recipe Bench costing row, which also prices `CH2`. See Q2.

Implement as a small service, e.g. `App\Services\Production\WorkspaceStockedWaterResolver`:

```php
public function forWorkspace(Workspace $workspace): ?Ingredient // W when stocked, else null
```

Queries are explicit `workspace_id` filters (production/purchasing family rule), two `exists()` queries, no global scopes involved. Unit-test it directly.

### 2. When the rule is evaluated: once, at draft creation, then snapshotted

The decision is captured in the formula snapshot by setting `ingredient_id` on the Water lines. It is **not** re-evaluated later (start, rescale, actuals, completion all read the snapshot). This follows the services-production rule "build manufacturing records from captured facts" and means:

- A production drafted while the workspace stocks water stays tracked even if the listing is retired later.
- A production drafted before the workspace starts stocking water stays untracked (see §8, in-flight productions).

The tracked/untracked state of a line is therefore simply `component = Water AND ingredient_id IS NOT NULL`. Add a model helper, e.g. `ProductionFormulaLine::isTrackedWater()` / `isUntrackedWater()`, and use it everywhere below instead of repeating the condition.

### 3. Snapshot builder

`ProductionFormulaSnapshotBuilder::build()` gains a parameter `?Ingredient $stockedWater = null` (default keeps every current caller and test unchanged).

- `calculatedLines()`: for the Water candidate, `ingredient_id => $stockedWater?->id`. Keep `subject_name_snapshot` as today's "Water" label (`production_bench.production.formula.water`) so printouts and the tap-water case are identical; the material code comes from the requirement (see §4).
- `liquidWaterLines()`: same, `ingredient_id => $stockedWater?->id` on each of the three lines.

Callers:

- `CreateProductionDraft`: resolve `$stockedWater = $this->stockedWater->forWorkspace($lockedWorkspace)` inside the transaction, after the workspace lock, and pass it in.
- `ProductionAvailabilityPreview`: resolve for the same workspace and pass it in, so the preview shows the water shortage before the user creates the production.
- `BackfillProductionFormulaSnapshot`: **does not** pass it (legacy rows stay untracked; never create stock obligations retroactively).

### 4. Requirement builder

`ProductionCalculatedRequirementBuilder::build()`: include lines where `component ∈ {Naoh, Koh}` **or** (`component = Water` and `ingredient_id !== null`). The emitted row shape is unchanged: `kind = ingredient`, `required_mass_grams = planned_mass_grams`, `percentage_snapshot = basis_percentage_snapshot`, `recipe_item_id = null`, phase key/name copied from the line.

Consequences, all automatic:

- One requirement per tracked Water line: one for `lye_water` (bar soap and liquid paste), plus up to three for liquid soap (`first_dilution`, `neutralization`, `final_dilution`). Per-phase requirements keep the phase grouping on batch sheets and make line ↔ requirement matching unambiguous. (Alternative of one summed requirement rejected: it breaks the per-phase display and the rescale-by-percentage model.)
- `ProductionRequirementMaterialCodeSnapshotter` gives the rows a material code like any other ingredient.
- `ProductionSnapshotRescaler` already rescales ingredient requirements from `percentage_snapshot`; no change.
- Demand, `WorkspaceMaterialCatalog` demand keys, reservation proposals (`StockReservationProposalService`) and `PrepareProductionStock` treat it as an ordinary ingredient requirement; no change expected — verify with tests.

### 5. Line ↔ requirement matching

Today the presenter skips Water lines and matches others by `recipe_item_id`, then by `ingredient_id` among unused requirements. With tracked water, *W* can appear twice in one soap production: as a recipe item (e.g. water used to dissolve an additive) and as calculated water. Formula lines are ordered recipe items first, so the current first-unused matching happens to work, but it is order-dependent.

Make calculated-line matching explicit: for a line with `recipe_item_id = null`, match the unused requirement with the same `ingredient_id`, `recipe_item_id IS NULL` **and** the same `phase_key_snapshot`. Apply the same predicate in:

- `ProductionDetailPresenter::requirementForLine()` (and stop forcing `requirement = null` for tracked water lines; keep it for untracked ones).
- `ProductionCompletionService::defaultCalculatedLyeActuals()` — it currently does `->where('ingredient_id', $line->ingredient_id)->first()`, which would be ambiguous once water is included (see §7). Add `whereNull('recipe_item_id')` and the phase key.

Extract the predicate into one place (e.g. a `ProductionRequirement` query scope `forCalculatedLine(ProductionFormulaLine $line)`).

### 6. Start: coverage blocking

No code change in `StartProduction::isFullyReserved()`: tracked water now has a requirement, so it must be fully reserved before start, exactly like NaOH. Untracked water has no requirement, so tap-water users are not blocked.

Change the auto-fill at the end of `StartProduction`: copy planned → actual **only for untracked Water lines** (`whereNull('ingredient_id')`). Tracked water actuals come from consumption (§7).

The start-blocked message (`start_blocked_coverage`) is generic; the detail page already shows which material lacks reservation. No new copy needed unless Q4 says otherwise.

### 7. Actual quantities and consumption

Tracked water behaves like NaOH/KOH from here on:

- **Entry**: the detail page shows the ordinary material actual input (lot + quantity, `materialActual()`), not the calculated water input. `SaveProductionActuals` `rows` path already handles it (ingredient requirement → requires a consumable lot → `ProductionConsumption` kind Ingredient, unit `g`).
- `SaveProductionActuals` `calculatedRows` path: reject tracked Water lines with a new validation message (e.g. `actual_calculated_water_tracked` — "This water is taken from stock; record it against a lot."). Untracked lines keep today's behaviour.
- **Default at completion**: generalise `defaultCalculatedLyeActuals()` (rename to `defaultCalculatedActuals()`) to iterate `Naoh`, `Koh` and tracked `Water` lines, turning active reservations into consumption when the user recorded none for that requirement — same as alkalis today. This keeps "planned = actual" one-click completion for stockers, matching the untracked flow where start pre-fills the planned water.
- **Formula line actual**: leave `actual_mass_grams` null on tracked lines, as NaOH/KOH lines do. `ProductionRunPrintDataBuilder::build()` (~l.50) already prints `actual($production, $requirement) ?? $line->actual_mass_grams`, i.e. consumption first when the line has a requirement, so terminal records show the consumed lots without change once matching works (§5).
- `assertCompletable()`: the "water actual positive" check applies only to untracked Water lines. Tracked water is covered by the existing "every requirement has consumption" and "no lotless rows" checks.

### 7b. Livewire detail component (easy to miss)

`App\Livewire\ProductionBench\Production\ProductionDetail` selects water lines by `component?->value === 'water'` in three places, and must switch to the **untracked** helper in each:

- ~l.399–403: seeding `$calculatedActualRows` after load;
- ~l.1202–1206: the "missing actuals" list shown before completion (tracked water then appears through the normal missing-requirement list instead);
- ~l.1278–1287: `calculatedActualRowsFromProduction()`.

If any of these keeps sending tracked lines, `SaveProductionActuals` will reject the whole save (§7), so this is a correctness change, not cosmetics. Read `.ai/rules/livewire.md`, `livewire-production-bench.md`, `js-livewire-production-bench-production.md` and `livewire-production-bench-production.md` before editing; `resources/js/production-editing*.js` and the detail Blade view have no water-specific code today (grep confirmed), so the material-row UI should render tracked water through the existing ingredient path.

### 8. Costing

No change to the costing loop. Tracked water consumption rows are priced from the lot (`costing_unit_cost ?? historical_unit_cost`) like any ingredient, enter `ingredientTotal`, and an unpriced water lot makes `allConsumptionPriced` false, which already leaves the finished lot's unit cost null. Untracked water stays at zero cost, as today (free tap water).

### 9. Existing in-flight productions

- No migration, no backfill. Every existing production has `ingredient_id = null` on its Water lines and stays untracked through start, actuals, rescale and completion.
- Productions created after the workspace adds a water listing or lot are tracked.
- `UpdateProductionPlan` / rescale / reschedule keep the snapshotted decision; they never flip a production between modes.
- Open: whether a user can switch one production between tracked and untracked water (Q5). v1: no. Recreating the draft re-applies the workspace rule, so it converts an old untracked draft to tracked, but cannot make a stocking workspace's draft untracked.

### 10. Liquid soap dilution water

Covered by §3–§7: each plain-water line (`first_dilution`, neutralizer solution water, `final_dilution`) becomes its own tracked requirement. Dilution liquids other than water (glycerin, hydrosol…) are already recipe-item requirements and do not change. `withRecalculatedLiquidSoapRows()` runs before the water lines are built and is unaffected. The neutralizer **solution water** is tracked; the neutralizer itself (citric acid etc.) is already a recipe-item requirement.

If the workspace's dilution liquid list itself includes *W* as a recipe item (a recipe that lists "Water" explicitly as a dilution liquid), `liquidWaterLines()` already subtracts it, so there is no double count; the recipe-item requirement and the calculated requirement simply coexist, matched by §5.

### 11. Out of scope

- Recipe Bench batch snapshots (done in PR #7).
- Cosmetic water (already stocked).
- Choosing a non-canonical water ingredient per workspace (Q2).
- UI to toggle tracking per production (Q5).

## Implementation order

1. `WorkspaceStockedWaterResolver` + unit tests.
2. `ProductionFormulaLine` tracked/untracked helpers; `ProductionRequirement::forCalculatedLine` scope.
3. Snapshot builder parameter; wire `CreateProductionDraft` and `ProductionAvailabilityPreview`.
4. `ProductionCalculatedRequirementBuilder` includes tracked water.
5. `StartProduction` auto-fill limited to untracked water.
6. `SaveProductionActuals` rejects calculated rows for tracked water.
7. `ProductionCompletionService`: generalised default actuals with the stricter matching, untracked-only water check.
8. `ProductionDetailPresenter`: tracked water uses requirement, reservation summary and material actual. `ProductionDetail` Livewire: untracked-only calculated rows (§7b). `ProductionRunPrintDataBuilder::requirementForLine()` and `ProductionPickingPrintDataBuilder`: use the §5 matching so batch sheets show water reservations and terminal records show water lots; note the print builder already groups any non-Ingredient line in `lye_water` into the lye section, so tracked lye water keeps its place.
9. Lang keys (en + any seeded interface translations per `.ai/rules/data.md` / `lang.md`).
10. Pint, `graphify update .`.

All existing-production commands keep going through `ProductionMutationGuard` with their editing context (actions-services rule); this plan adds no new command, only changes behaviour inside existing ones. Mass maths stays bcmath on decimal strings.

## Tests to add

Extend existing files where they exist (`ProductionFormulaSnapshotTest`, `ProductionCalculatedMaterialsTest`, `LiquidSoapProductionTest`, `ProductionDetailPresenterTest`, `ProductionFormulaSnapshotBackfillTest`, start/actuals/completion feature tests).

Rule:
- Workspace with no listing and no lot for *W* → not stocked (returns null).
- Listing (active) → stocked; listing inactive only → stocked; stock lot only (no listing) → stocked.
- Listing for *W* in **another** workspace → not stocked.
- Canonical water missing/inactive → not stocked even with a listing on some other water row.

Tap-water regression (the most important):
- Bar soap production in a workspace without water stock: no water requirement, Water line `ingredient_id` null, starts with only alkalis/oils reserved, start fills planned water actual, completion cost unchanged.

Stocked water, bar soap:
- Draft creates a `lye_water` requirement for *W* with mass = planned water and `percentage_snapshot` = line percentage.
- Start is blocked until water is fully reserved; succeeds once reserved; Water line actual not auto-filled.
- Completion with no water actual entered creates consumption from the reservation and costs it at the lot unit cost; total cost includes water.
- Unpriced water lot → finished lot unit cost null (cost incomplete), completion still succeeds.
- `SaveProductionActuals` with a `calculatedRows` entry for the tracked water line → validation error.
- Livewire `ProductionDetail`: saving actuals on a tracked-water production succeeds (no calculated row sent for the tracked line); the pre-completion missing list names water when it has no consumption.
- Batch sheet shows the water reservation lot; terminal record shows the consumed water lot and cost.
- Rescale (`UpdateProductionPlan`) rescales the water requirement and line together.
- Soap recipe that also lists *W* as a recipe item: two requirements, presenter and default consumption match each line to the right one.

Stocked water, liquid soap:
- Draft creates requirements for lye water, first dilution, neutralizer solution water and final dilution (only those with positive mass), each in its phase; masses equal the water lines.
- Completion consumes and costs all of them.

In-flight / snapshot:
- Production drafted before a listing exists stays untracked after the listing is added (start, actuals, completion all follow the untracked path).
- Production drafted while stocked stays tracked after the listing is deactivated and the lot depleted.
- `BackfillProductionFormulaSnapshot` never creates water requirements.

Availability preview:
- In a stocking workspace with no water stock, the preview reports water as short; in a tap-water workspace it does not mention water.

## Open product questions

- **Q1. Is "has a listing or a lot" the right switch, or do you want an explicit workspace setting** ("Track soap water in stock")? The implicit rule means zero setup for tap users and zero setup for buyers, but a workspace that bought distilled water once (listing kept for history) will keep tracking it. An explicit toggle is clearer but is one more setting. Recommendation: implicit rule for v1; revisit if users complain.
- **Q2. Only the platform water (`CH2`)?** A company may model its own "RO water" as a private ingredient with its own listing. v1 tracks only the canonical water; supporting a workspace-chosen water ingredient would need a setting. Is that needed now?
- **Q3. Should a safety-buffer setting on water count as "stocks water"?** The inventory catalog treats it as tracking evidence. Recommendation: no for v1 (a listing or lot is required to actually reserve anyway).
- **Q4. Should missing water reservation block start**, like NaOH, or only warn? Blocking is consistent and is what "stocked" means; warning would need a soft-requirement concept that does not exist today. Recommendation: block.
- **Q5. Per-production override?** e.g. a stocking workspace making one batch with tap water. v1 has none (delete and recreate isn't possible either — the rule would track it again). Options: a "Use untracked water" toggle on the draft, or accept it. Recommendation: wait for demand, but confirm.
- **Q6. Cost of untracked water**: stays 0 in production cost (consistent with "free tap water"). Confirm that is acceptable given the Recipe Bench marks unpriced water rows as "cost incomplete" — in Production, untracked water is not a costed row at all, so the batch will not be marked incomplete for it.
