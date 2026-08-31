# Production Bench Inventory UX — Implementation Summary

> **Current status (2026-08-31): merged into local `main`.** The historical implementation
> narrative below predates the final corrective pass. The completed branch was merged by
> `643918a3`; final feature verification was **2,788 passed / 25 skipped / 0 failed**, and the
> merged-main inventory suite was **220 passed / 0 failed**. The four failures in the merged-main
> full suite are unrelated local-main baseline/configuration issues: three Luna-vs-Terra `.env`
> expectations and one pre-existing Composer queue-timeout mismatch. Nothing was pushed to
> `origin`. Manual viewer-role browser acceptance remains explicitly open; automated viewer
> authorization and no-write tests pass.

Branch: `codex/production-bench-inventory-ux` (worktree `.worktrees/production-bench-inventory-ux`)
Scope: review findings **#1–#6 + #11**, per your authorisation. **#7 deferred entirely** (Filament
filters/forms rebuild — "we will take care of #7 after that").
Companion document: `2026-08-30-production-bench-inventory-ux-review.md` (the findings).

**Gate: full suite 2709 passed / 25 skipped / 0 failed · Pint clean (8 files) · no `npm run build` run.**
**Nothing committed** — 9 modified files are left in the worktree for you to review and commit.

## What changed per finding

| # | Finding | Status | Where |
|---|---------|--------|-------|
| #1 | Buffer read/written in canonical grams instead of display unit | **Fixed** | `SaveMaterialBuffer`, `InventoryMaterialDetail` |
| #2 | Route-bound subject mutable via Livewire payload | **Fixed + pinned** | `InventoryMaterialDetail` |
| #3 | Open lots unbounded, ordered newest-first | **Fixed** | `MaterialActivityService::openLots()` |
| #4 | Period movement rows fully loaded | **Fixed** | `MaterialActivityService`, component, blade |
| #5 | N+1 subject hydration (102 queries/page) | **Fixed** | `WorkspaceMaterialInventoryQuery` |
| #6 | Missing plan-mandated query-count test | **Added** | `WorkspaceMaterialInventoryQueryTest` |
| #11 | `foreach` instead of collection pipeline | **Fixed** | `presentActivity()` |
| #7 | Filament filters/forms replaced by raw inputs | **Deferred by you** | — |

## This session's work (#2 pin, #3, #4, #11)

### #2 — immutability pinned with a non-vacuous test
`ProductionBenchInventoryMaterialDetailTest` now asserts that `set()` on `ingredientPublicId`,
`packagingPublicId`, or `subjectType` throws `CannotUpdateLockedPropertyException`. I verified the
test is not vacuous by removing one `#[Locked]` attribute and watching the test go RED (silent
mutation, no exception), then restored it. The earlier caveat stands: this asserts Livewire's own
locked-property mechanism rather than replaying a tampered HTTP request, which the test harness
cannot do faithfully.

### #3 — open lots bounded to 10, FEFO order
`MaterialActivityService::openLots()` now orders **expiry ASC → stocked date ASC → lot code ASC →
id ASC** and **limits to 10** (`OpenLotLimit`). NULL expiry placement is explicit via
`orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')` — necessary because SQLite puts
NULLs first on ASC while PostgreSQL puts them last; the guard makes the order identical on both
drivers (same pattern as `ProductionIndex`'s `planned_for is null`). Two new tests: the 10-lot
bound, and an ordering test whose fixtures deliberately disagree with the old `stocked_at DESC`
order in every position (a fixture that happened to already be FEFO-ordered would have passed
vacuously under the old code).

### #4 — period movement rows paginated, reconciliation stays exact
`MaterialActivityService` is split in two, as the plan's Task 7 Step 5 asked:

- **`forPeriod()`** now returns only the reconciliation totals. They are summed from a narrow
  `type, quantity_delta` projection over **every** movement in the period — no model hydration —
  so opening/closing/net/reconciliation-delta are exact regardless of which page is shown. The
  `groupFor()` classification remains the single source of truth (no SQL CASE duplication to drift).
- **`paginateMovements(perPage, pageName)`** is the new second method: newest-first, eager-loaded
  (`source` morph + lot relations), returning a `LengthAwarePaginator` of
  `{movement, group, quantity_delta}` rows.

`InventoryMaterialDetail` gained `WithPagination`, a `perPage` property clamped to [25, 50, 100]
(mirroring `InventoryIndex`), and resets the `activity` page whenever the period preset, custom
dates, or page size changes. The blade renders the shared `<x-table-pagination>` bar under the
activity table. Tests: service-level pagination (30 rows → page 2 has 5, newest-first ordering,
totals still sum all 30) and component-level (page navigation, per-page change, `received ===
'0.30'` kg on every page).

### #11 — collection pipeline
`presentActivity()`'s quantity formatting is now a `collect()->map()` pipeline with the quantity
keys inline; movement formatting moved into `presentMovementPage()` (which is also what pagination
required).

## Verification trail (TDD)

- #2: RED shown by removing `#[Locked]` (mutation silently succeeded) → GREEN after restore.
- #3: 2 new tests RED (old order + no limit) → GREEN.
- #4: existing test migrated to new API + 2 new tests RED (`paginateMovements` missing / `movements`
  view key missing) → GREEN.
- Final gate: **2709 passed / 25 skipped / 0 failed** (up from 2704), `vendor/bin/pint --dirty` PASS.

## #7 — steps 1 + 2 (implemented after your go-ahead; step 3 untouched)

**Gate after this round: full suite 2714 passed / 25 skipped / 0 failed · Pint clean (11 files).**

### Step 1 — tamper-fallback tests, plus one real gap they exposed
Three new tests in `ProductionBenchPagesTest`:
- **Mount-time**: all 15 filter/sort query-string parameters set to bogus values normalize to safe
  defaults (passed immediately — `mount()` clamping was already thorough).
- **Mid-session**: injected `stockState`/`category`/`sort`/`direction` values behave as the neutral
  default (query-layer allow-lists hold); `perPage=1000` clamps to 25.
- **Lot dates**: `lotStockedFrom`/`lotStockedUntil` were the **only** filter values reaching SQL
  (`whereDate`) without an allow-list, and the `updated*` hooks did not re-validate them — mount-only
  normalization. A crafted update with `not-a-date` would hit PostgreSQL as
  `invalid input syntax for type date` (a 500); SQLite silently returned empty rows, which is why
  the suite never saw it. **Fixed**: both `updated` hooks now re-run `normalizeLotDate()`.

### Step 2 — buffer editing moved onto the Filament substrate (plan Task 6 Step 6)
`InventoryMaterialDetail` now implements `HasActions`/`HasForms` (mirroring `InventoryIndex`):
- **`editBufferAction`** — modal with `LocalizedDecimalInput::make('buffer_quantity')`, prefilled
  from the stored canonical value converted to the display unit; saving an empty field clears.
- **`clearBufferAction`** — separate danger action, visible only when a buffer is set; no
  confirmation step, matching the old UI's clear-by-emptying behaviour.
- Both hidden for read-only benches; the server-side guard (`assertWritable` in
  `SaveMaterialBuffer`) is unchanged. Both actions funnel into one `saveBufferFromModal()` that
  calls nothing but `SaveMaterialBuffer::handle()`.
- Deleted: the `bufferQuantity` property, `saveBuffer()`, the mount-time seeding, and the raw
  `<form>` in the blade (replaced by `{{ $this->editBufferAction }}` / `{{ $this->clearBufferAction }}`
  + `<x-filament-actions::modals />`).
- New copy keys `edit_buffer`, `clear_buffer`, `buffer_empty_clears` mirrored into
  `interface-translations.json` (English in all six locales, matching the branch's recent keys).
- The four buffer tests from #1 were migrated to the action API (`callAction`,
  `mountAction()->assertSchemaStateSet()`, `assertHasNoFormErrors()`), plus two new tests:
  clear-by-empty and read-only action hiding.
- **Gotcha worth knowing**: `LocalizedDecimalInput` dehydrates to a `float`; the value is cast back
  to string before `SaveMaterialBuffer`, which re-validates format and precision — so nothing
  malformed can slip through, and #1's conversion tests (metric kg, US lb, packaging units) all
  pass unchanged through the modal.

### Step 3 — re-assessed against the Filament 5 / Livewire 4 docs (NOT yet implemented)

I originally held this at "P2 convention deviation, pause for review". Re-checking it against the
docs **reversed that on cost** and surfaced one hard constraint. Verified in this worktree:
**livewire/livewire v4.4.2, filament/forms v5.7.6, filament/schemas v5.7.6**.

**The plan's `filters.*` shape is not needed and should not be used.** Plan Task 4 Step 3 sketches
`queryString()` with dotted keys (`'filters.category'`). Probed: dotted keys *do* bind into a nested
array property on 4.4.2, but **neither form is documented** — the Livewire 4 `url` and
`attribute-url` pages document `#[Url]` only on flat properties and `queryString()` only with flat
keys. Building on it means relying on an implementation detail.

The documented route is Filament's **"Saving form data to individual properties"**
(`filamentphp.com/docs/5.x/components/form`): omit `statePath()` and give every field its own public
property. The two Selects then bind to the **existing flat `#[Url]` properties**, so the aliases
`?category=` / `?subcategory=` survive untouched and no property moves.

Throwaway probe (written, run, deleted) confirmed all four behaviours:
1. `#[Url]` initialises the flat properties (`category=lipids&subcategory=vegetable_oils`).
2. Dependent options recompute from the parent — `optionsFor('lipids')` → 4, `optionsFor('waxes')` → 5.
3. `->live()->afterStateUpdated(fn (Set $set) => $set('subcategoryFilter', null))` clears the child.
4. `->disabled(fn (Get $get): bool => blank($get('categoryFilter')))` returns `true` when blank.

**Hard constraint the probe exposed:** a Filament `Select` writes **`null`** for "no selection", so
the backing properties must be nullable. With today's `public string $categoryFilter = ''` the first
`TypeError` fires inside `data_set()` — "Cannot assign null to property ... of type string". Merely
clearing the category control would 500. Step 3 therefore requires
`public ?string $categoryFilter = null` / `?string $subcategoryFilter = null`. The query layer
already tolerates it: `WorkspaceMaterialInventoryQuery:347-348` does
`IngredientCategory::tryFrom((string) ($filters['category'] ?? ''))`, so null → `''` → `tryFrom`
returns null → filter skipped, tamper-fallback preserved.

**"No URL change" holds, but only with one deliberate edit.** The nullability move shifts the empty
sentinel from `''` to `null`, and Livewire decides whether to show a param by comparing
`JSON.stringify(newValue) === JSON.stringify(except)` (`livewire.js:14321`) — strict JSON
comparison, not loose. A probe of the emitted effect confirmed `except` is forwarded verbatim
(`""` or `null`). Consequence:

- keep `except: ''` → clearing a filter writes `null`; `"null" !== '""'`; every cleared filter starts
  emitting `?category=` into shared URLs. **That is a URL change.**
- use `except: null` → `null` matches, param omitted. Links stay byte-identical:
  `?category=lipids&subcategory=vegetable_oils` still round-trips, empty filters stay absent,
  tampered values still clamp and drop out.

Second wrinkle the probe surfaced: `?category=` (present but empty) sets a `?string` property to
`''`, **not** `null` — contrary to the documented nullable behaviour. `BaseUrl` only substitutes
null when the param is *absent* (`$initialValue === null`), and `data_get(request()->query(), ...)`
returns `''` for a present-but-empty param. So `normalizeFilterState()` must fold `''` → `null` for
these two, giving a single empty sentinel; `filled()` in the blade and `materialFiltersActive()`
keeps the rest robust against either value.

**Corrected shape** (documented on both sides; follows Filament's "dependant select options" recipe):

```php
public function form(Schema $schema): Schema
{
    return $schema->components([
        Select::make('categoryFilter')
            ->options(IngredientCategory::options())
            ->searchable()
            ->live()
            ->afterStateUpdated(fn (Set $set) => $set('subcategoryFilter', null)),
        Select::make('subcategoryFilter')
            ->options(fn (Get $get): array => IngredientSubcategory::optionsFor($get('categoryFilter')))
            ->searchable()
            ->disabled(fn (Get $get): bool => blank($get('categoryFilter'))),
    ]);
    // no ->statePath(): state lives on the individual #[Url] properties
}
```

**Residual cost** (~60 lines across 3 files, all bounded):
- `InventoryIndex.php` — 2 declarations → `?string` with `except: null`; `:182`, `:261-262`
  (`''` → `null`); `:594-595` and `:648-651` (`!== ''` / `=== ''` → `filled()` / `=== null`);
  new `form()` (~20 lines); drop the now-dead `...ForCombobox` view data (`:430-431`) and possibly
  `comboboxOptions()`.
- `inventory-index.blade.php` — swap the two `<x-search-combobox>` blocks for
  `{{ $this->form->getComponent('categoryFilter') }}` / `...'subcategoryFilter'`
  (`getComponent()` is already used at `RecipeWorkbench.php:796`); chip conditions at `:142-143`
  move to `filled()`.
- `ProductionBenchPagesTest.php:451-452,480-481` — `assertSet(..., '')` → `assertSet(..., null)`.

**One open decision — styling.** `Select.php:1838` computes
`$isNative = ! ($isSearchable || $isMultiple || $isHtmlAllowed) && $this->isNative()`, so
`->searchable()` forces the non-native JS select. `resources/css/shared/filament-soapkraft.css`
bridges only `select.fi-select-input` (native), `.fi-input-wrp` and `input.fi-input` — not the
non-native `.fi-select-input-ctn` / `-btn` / `-value-label` chain. Filament's own CSS *is* imported
(`app.css:3-7`) so nothing breaks, but those two controls would wear Filament's Tailwind gray
palette beside five `sk-select-control` natives in the same panel. Either extend the bridge
(~20 lines) or accept the mismatch. `IngredientEditor.php:611-620` already ships this mismatch for
the same two fields, so it is a tolerated precedent — but on a form page, not inside a compact
`sk-*` filter panel.

### Step 3 reconsidered — Filament is not actually required

Asked directly whether a plain Livewire dependent dropdown would do, I re-examined and **reversed my
earlier recommendation**. I had over-weighted the plan's wording against the spec, the house pattern,
and the measured cost.

The existing `<x-search-combobox>` blocks already satisfy every functional requirement of plan Task 4
Step 4, except for the word "Filament":

| Requirement (plan Task 4 Step 4) | Status today |
| --- | --- |
| searchable | yes — the combobox has a text-search input |
| non-native | yes — it is a custom Alpine combobox, not a native select |
| subcategory options depend on category | yes — `optionsFor($this->categoryFilter)` at `:429-431` |
| subcategory disabled until category filled | yes — `:disabled="$categoryFilter === ''"` at blade `:130` |
| removable chips | yes — blade `:142-143` |

No truncation defect either: `comboboxOptions()` (`:629`) applies no limit, and the sets are small —
**22 categories**, largest subcategory set **5** (`waxes`). The `OPTION_LIMIT = 30` constant belongs
to the supplier-listing query at `:784`, not to these two controls.

Arguments against the Filament swap:
- **The spec sanctions the combobox.** Spec line 77 asks for "searchable comboboxes **or** compact
  state controls" — the current implementation is literally option one. Per this project's own
  convention the spec is the authority on *intent*.
- **The forms rule may not reach this surface.** `.ai/rules/forms.md:8` scopes Filament to "public
  form UI" / "public Livewire editors". This is a filter panel on an index page: no submit, no
  validation, no persistence. `IngredientEditor.php:611-620` is an *editor* — exactly the case the
  rule covers — so it does not bind here.
- **The combobox is the house pattern for index/filter surfaces**, used in 6 views including the
  sibling index page `ingredients-index.blade.php:404`.
- **The measured cost is real**: nullability ripple, a `TypeError` landmine if a property is left
  non-nullable, the `except: ''` → `except: null` change, new CSS, and a visual mismatch with the
  five `sk-select-control` natives in the same panel.

Options, cheapest first:
- **A — keep the comboboxes, amend the plan** (0 code). Record the deviation: spec line 77 sanctions
  the combobox; the plan narrowed it to Filament without stating why.
- **B — plain Livewire native selects** (~15 lines). Two `<select class="sk-select-control">` with
  `wire:model.live`; visually identical to the five other natives in the panel; deletes the combobox
  plumbing from this view. Loses search over the 22 categories.
- **C — Filament Selects** (as above, ~60 lines + CSS).

One caveat on process: my earlier note in `MEMORY.md` says that when the plan is *explicit and
deliberate* about a detail, the plan wins for implementation and the divergence gets flagged rather
than silently skipped. Plan Task 4 Step 4 is explicit. Whether it is *deliberate* is the open
question — the spec it derives from sanctions the alternative, which hints the author may simply not
have known `x-search-combobox` existed. That is the owner's call, not mine.

### Decision — option A, amendments written

Owner chose **A**. Two deviations are now recorded in
`docs/superpowers/plans/2026-08-30-production-bench-inventory-ux.md` (Task 4, Steps 3 and 4):

1. **Step 4** — category/subcategory stay on `<x-search-combobox>`. The superseded Filament line is
   preserved in the amendment text along with the four reasons, and the *retained* requirement is
   spelled out (subcategory options depend on the chosen category; disabled until a category is
   filled), so the amendment cannot be read as dropping behaviour.
2. **Step 3** — the `filters.*` + dotted `queryString()` sketch was not used; durable state binds to
   flat `#[Url]` properties. Recorded with the reason (undocumented nested-key support) and an
   explicit statement that the eight aliases are unchanged.

**No production code changed as a result of this decision**, and no tests needed migrating. Working
tree is 14 files: the 13 from #1–#6/#11 and #7 steps 1–2, plus this plan document. Baseline remains
2714 passed / 25 skipped / 0 failed. Step 3 is therefore **closed**, not deferred.

## Evaluation of the supplied second review (2026-08-30)

You asked me to evaluate a review claiming "five plan mismatches and three hard standards
violations". I re-derived every claim against the code, `.ai/rules`, the spec, and the plan rather
than adopting it. **Six findings accepted, three reclassified, one rejected.**

Baseline re-confirmed before writing this: full suite `php artisan test --compact` →
**2714 passed / 25 skipped / 0 failed** (170s). The supplied review explicitly did *not* run the
complete suite; that matters for finding #2 below, which a green suite cannot detect.

### Accepted — real defects

| # | Finding | Verdict | Evidence |
|---|---|---|---|
| 1 | Canonical buffer overflows `decimal(20,9)` | **Accept — most serious** | `SaveMaterialBuffer::normalize()` validates the *entered display value* (`strlen($whole) > 11`) **before** unit conversion. Column is `decimal(20,9)` → 11 integer digits. Proven: `999999999` kg → `999999999000.000000000` (**12** integer digits); same input in lb → `453592369546.407630000` (**12**). `99999999` (8 nines) is safe at 11. SQLite ignores declared precision so the suite stays green; PostgreSQL raises `numeric field overflow` → 500. The DB check constraint and the two BEFORE INSERT/UPDATE triggers (`2026_08_30_130000_create_workspace_material_settings_table.php:27,48,55`) guard **sign only**, not magnitude. **Fix: validate the canonical value after conversion, not the display value before it.** |
| 2 | Open scope hides zero-balance lots that still carry active reservations | **Accept** | Spec line 167: Open = "every non-zero physical balance"; Exhausted = "zero-balance lots with no active reservation". Plan Step 1 test bullet is stricter and differently worded: Open "excludes zero-balance lots **without** active reservations" — implying zero-balance lots *with* reservations belong in **Open**. Code implements the spec's literal reading: `InventoryIndex.php:474` open = `physical <> 0`, exhausted = `physical = 0 AND activeReserved = 0`; `MaterialActivityService.php:140` open = `physical <> 0`. A zero-balance, still-reserved lot is therefore in **neither** Open nor Exhausted — reachable only via All, and the default scope is Open. **Untested**: `grep` for `StockReservation::factory` in both `ProductionBenchPagesTest.php` and `ProductionBenchInventoryMaterialDetailTest.php` returns nothing. Impact: an over-reserved lot is invisible by default. I accept the **plan's** reading as the one to implement — a reserved lot is not "exhausted" — and flag the spec/plan divergence for the spec owner. |
| 3 | Shortage summary tile does not activate its filter | **Accept** | Spec line 88: "The negative-forecast summary can activate the same filter directly." Plan line 500: "verify the negative-forecast summary activates the same state". Code: `inventory-index.blade.php:27` is a bare `<dd>` inside a `<dl>` — no anchor, no `wire:click`. The target state already exists (`stockState`, alias `state`, accepts `negative_forecast`; allow-list at `InventoryIndex.php:643-646`), so only the activation is missing. No test asserts it — plan line 500 asked for one and it was never written. |
| 4 | Browser titles still use retired labels | **Accept for `inventory-stock.blade.php`; partial for `inventory.blade.php`** | Spec line 20: "The previous labels **Materials** and **Stock** are replaced because they do not describe the row grain." Code: `@section('title', 'Stock · Production Bench · …')` (`inventory-stock.blade.php:3`) and `'Inventory · …'` (`inventory.blade.php:3`). Plan lines 40–41 name these files as "Lot register page title" / "Stock by material page title". The on-page `<h1>` is already correct (`inventory-index.blade.php:13` uses the `lot_register` / `stock_by_material` keys), so this is tab-only. Tests miss it: `ProductionBenchPagesTest.php:53` asserts `assertSee('Inventory')`, which passes off nav markup; nothing asserts `<title>`. `Stock` is a retired label → clear mismatch. `Inventory` survives as the section name (spec lines 14–16) so it is defensible, but two tabs then read "Inventory"/"Stock" — indistinguishable. I'd change both. |
| 5 | Access not re-asserted inside the transaction | **Accept the rule violation; dispute the severity** | `.ai/rules/app.md:22` requires "…`withoutGlobalScopes()->lockForUpdate()` … and **re-assert access inside transactions**". `WorkspaceMaterialSettings::synchronize()` locks only the settings row and asserts nothing; `SaveMaterialBuffer::handle()` calls `assertWritable()` **before** entering it. House precedent proving the rule is live: `ReceivePurchaseOrder.php:81-82` locks the workspace row and calls `assertWritable()` **inside** the closure. Downgrade: `SaveMaterialBuffer` is the *only* caller of `synchronize()` (verified — one reference in `app/`), the window is between the pre-check and a single-row upsert, and the missing **workspace** lock is not needed here because `ReceivePurchaseOrder` locks the workspace only because its transaction spans several tables. **Recommendation: move/duplicate the assertion inside the closure; do not add a workspace row lock — it would add contention for no correctness gain.** |
| 6 | Activity source links lack accessibility checks | **Accept as a plan mismatch; reclassify P1 → P3** | Plan line 833: "Return no URL and a neutral source label for every other source type **or inaccessible record**." `InventoryMaterialDetail::sourceUrl():256` is a `match (true)` that returns routes for `ProductionRun`, `GoodsReceiptLine`, and `GoodsReceipt` unconditionally; `sourceLabel()` likewise returns a real identifier rather than a neutral one. Downgrade: movements are already constrained by `where('workspace_id', $workspace->id)` (`movementQuery():211`), so a cross-workspace source requires corrupt data, and the target routes are themselves authorized — worst case is a link that 403s, not a data leak. Defensive requirement, explicitly stated, currently omitted. |

### Reclassified / rejected

| # | Finding | Verdict | Reasoning |
|---|---|---|---|
| 7 | `MaterialActivityService.php:192` unbounded 365-day aggregation | **Reject as a defect — and the obvious fix is forbidden by the plan** | `groupTotals()` does `->get(['type','quantity_delta'])` with no chunk or limit. Plan Task 7 Step 4 specifies the grouping and imposes no bound. It selects two scalar columns and hydrates `stdClass` — not models — and is bounded by one subject's movements within at most 365 days. The payload that genuinely scales with period length is the **paginated** movement list (`movementQuery():209`), and that one *is* paginated. **The decisive point:** the natural remedy — push the grouping into `SUM(CASE …)` — is *ruled out by plan line 829*, "Use `bcadd`, `bcsub`, and `bccomp`; never cast quantities to float." Measured: SQLite `SUM()` on the `decimal(20,9)` `quantity_delta` column returns `typeof` = `real`, and summing `123456789.123456789 + 0.000000001 + 0.000000001` gives `123456789.12345679` against an exact `123456789.123456791` — a **1e-9 drift**, exactly the scale the column stores. (PostgreSQL `SUM(numeric)` is exact, but the suite and the dev server run SQLite, and the plan demands driver-independent exactness.) The row-by-row `bcadd` accumulator is therefore a **deliberate consequence of the plan's no-float rule**, not an oversight. Chunking would not change that; it would only add complexity to an accumulator that must stay in PHP. |
| 8 | Duplicated setting lookup (`currentBufferGrams():467`) | **Partially accept — but the layering point is the real one** | Called twice per render (`:147` in `fillForm`, `:163` in `clearBufferAction()->visible()`). Two identical indexed lookups on a unique row is negligible; worth memoising at most. The more interesting inconsistency: the component reads `WorkspaceMaterialSetting` **directly** while every write goes through the `WorkspaceMaterialSettings` service. One owner of the row would be cleaner. Low priority either way. |
| 9 | Livewire uses `app()` instead of injection | **Reject as a violation — the rule does not say what the review says it says** | Read the rule verbatim (`.ai/rules/services.md:15`): "Resolve them from the container (method injection into Livewire/controllers, constructor injection into Actions) — **never instantiate with `new`**." The operative prohibition is on `new`. `app(SomeService::class)` *is* container resolution, which the sentence affirmatively requires. The parenthetical names method injection as the **preferred** mechanism, not the exclusive one. Three further facts: (a) `InventoryMaterialDetail` **does** use method injection — its `render()` injects four services (`MaterialActivityService`, `MassConverter`, `ProductionBenchAccess`, `StockPositionService`, `:168-173`); the 6 `app()` calls sit in Filament `->visible()` closures and private helpers that the container does not invoke; (b) `app()` is the dominant house pattern — **15 of 39** Livewire files use it, **52 calls** total, including the owner's own `RecipeWorkbench` (12) and `IngredientEditor` (9); only **6 of 39** files use `boot()` at all, and the more common injection site is `render()`/`mount()` (as in `ProductionCreate`, `SupplierDetail`, `SupplierListingIndex`, `StockPreparation`); (c) the sibling `InventoryIndex` held up as the counterexample has an `app()` call of its own at `:850` — `app()->getLocale()`, a locale read rather than service location. Filing this as a *standards violation* would fail ~15 files and contradict the rule's own text. Cosmetic inconsistency at most. |
| 10 | Validation message assertions (`ProductionBenchInventoryMaterialDetailTest.php:156`) | **Reject as a defect; cheap hardening** | The test asserts `assertHasErrors(['customFrom','customTo'])` for empty, malformed, and inverted input, plus `assertHasNoErrors()` for the valid case. Plan Step 6 requires "validate ISO dates, require from <= until, and attach errors to the date fields" — satisfied and asserted. The component attaches **four** distinct messages (`period_date_required`, `period_date_invalid`, `period_date_order` ×2 — `InventoryMaterialDetail.php:383-400`), none of which the test distinguishes. Adding a keyed `assertHasErrors` would stop a future regression from swapping one rule for another, but no plan or spec line requires it. |

### Reconciling the count

The supplied review claims ten findings. My table has nine rows because I treat the two browser-title
files as one finding; counted per-file it is ten. If the tenth item is something I have not listed
here, paste it and I will evaluate it on the same basis.

### The combobox staleness caveat — checked against vendor source, and it does not hold

Codex's one substantive new claim: the Alpine `options` are initialised once, `replaceOptions()` is
never called, and no category-based `wire:key` forces reinitialization, so subcategory options may go
stale after a Livewire category update.

The factual premises are all correct. **The conclusion does not follow**, because Alpine has its own
`x-data` reconcile path that does not need any of those three mechanisms. Traced end to end in
`vendor/livewire/livewire/dist/livewire.js` (Alpine is bundled there — 95 references, and there is no
separate alpinejs package):

1. **`x-data` carries the options.** `search-combobox.blade.php:21-28` inlines `options: @js($options)`
   into the expression, and `createSearchCombobox` copies it once at `search-combobox.js:8`. So the
   `x-data` attribute *string* differs whenever the server sends different options — and it does:
   `InventoryIndex.php:431` computes `subcategoryOptionsForCombobox` from
   `IngredientSubcategory::optionsFor($this->categoryFilter)`.
2. **Livewire rewrites the attribute.** `patchAttributes` (`:14562-14590`) does
   `from.setAttribute(name, value)` whenever `from.getAttribute(name) !== value`. There is **no skip
   list for `x-data`** — the only special case is `open` on `<dialog>`. (`:disabled` and `:placeholder`
   on that combobox also change with `$categoryFilter`, so the element is definitely patched.)
3. **Livewire does not suppress Alpine.** `_x_ignoreMutationObserver` (`:1216`) is *read* exactly once
   in the whole bundle and **never set** by Livewire, so the observer fires on the morph.
4. **Alpine re-runs the directive and reconciles.** `onAttributesAdded` (`:1813-1815`) →
   `directives(el, attrs)`. The `x-data` directive (`:4658`) then: skips only if
   `dataToReconcile?.expression === expression` (`:4662`); otherwise re-evaluates
   `createSearchCombobox(newConfig)` (`:4669`) and, because the previous directive's `cleanup`
   (`:4690-4694`) left `{ reactiveData }` on the element, takes the reconcile branch
   (`:4674-4676`). `reconcileData` (`:4702-4714`) does `target[key] = source[key]` over every key —
   **including `options`**. Then `:4688-4689` re-adds the scope and calls `init()`, which runs
   `syncSelection()` (`search-combobox.js:12-14`).

So the options **do** refresh, the selection is re-derived from server state, and none of
`replaceOptions()`, `x-effect`, or `wire:key` is required.

Two corollaries worth stating:

- **The `packaging-tab.blade.php:31` `x-effect` is not counter-evidence.** It reads
  `replaceOptions(packagingCatalog.map(...))` — `packagingCatalog` is an **Alpine-side** reactive
  array, not a server-rendered prop. That is a different problem (client-side source of truth) and it
  is why *that* call site needs `x-effect`. It is not evidence that server-rendered options go stale.
- **`replaceOptions()` being uncalled here is fine, not a defect.** It exists for the
  `optionAddedEvent` path and for client-driven replacement. Its absence from the inventory filters is
  correct given the reconcile path above.

Real but minor consequence: `reconcileData` also resets `query`, `open`, and `activeIndex` to their
initial values, so transient combobox UI state clears on a re-render that changes its `x-data`. In
this flow that is desirable — the dropdown is already closed by the time the category selection
lands, and selection is restored from `:selected-id` / `:selected-label`.

**Caveat on my own conclusion:** this is a reading of bundled vendor source, not a browser
observation, so I hold it as *strong static evidence*, not proof. It is falsifiable in about two
clicks on the preview URL: open Inventory → Stock by material, pick a category, open the subcategory
combobox. If the subcategory list matches the chosen category (e.g. `lipids` → 4 entries, `waxes` → 5),
the caveat is disproven; if it shows the previous or the full list, Codex is right and the fix is a
`wire:key="'inventory-subcategory-filter-'.$categoryFilter"` on the wrapper.

### Four points to put to Codex

These are where I part company with the review, stated so they can be argued with directly.

1. **"`app()` in Livewire is a standards violation" — incorrect as stated.** The rule
   (`.ai/rules/services.md:15`) bans `new`, and requires container resolution. `app()` *is* container
   resolution. The parenthetical "(method injection into Livewire/controllers …)" states a preference,
   not an exclusive mechanism. Second, the component already injects four services into `render()`.
   Third, the standard the review is implicitly proposing (`boot()` injection) is used by 6 of 39
   Livewire files; `app()` is used by 15 of 39. **A rule that fails 15 files including the owner's
   own `RecipeWorkbench` is not the house rule.** If Codex wants to argue the rule *should* be
   tightened, that is a rules change for the whole codebase, not a defect in this branch.

2. **"Unbounded 365-day aggregation" — the fix it implies is forbidden by the plan.** Pushing the
   grouping into `SUM(CASE …)` is the obvious remedy, and it is wrong here: SQLite `SUM()` on
   `decimal(20,9)` returns `real` and drifts 1e-9 (measured, above), while plan line 829 says "never
   cast quantities to float". The row-by-row `bcadd` accumulator is the *only* implementation that
   satisfies that line on both drivers. Chunking does not address the concern either — it changes
   neither the row count fetched nor the arithmetic. What would be a fair critique is a *bounded*
   alternative that preserves exactness; I do not think one exists short of a driver-aware SQL branch,
   which is worse.

3. **"Access not re-asserted inside the transaction" — valid rule breach, wrong remedy and wrong
   weight.** The review pairs this with a missing workspace-row lock. That lock is not appropriate
   here: `ReceivePurchaseOrder` locks the workspace because its transaction spans several tables,
   whereas `synchronize()` is a single-row upsert already serialised by `lockForUpdate()` on the row it
   writes. Adding a workspace lock would serialise *all* buffer edits per workspace for no correctness
   gain. Move the assertion inside the closure and stop there.

4. **"Source links lack accessibility checks" is P3, not P1.** Movements are already constrained by
   `where('workspace_id', $workspace->id)`, so an inaccessible source requires corrupt data, and the
   target routes are independently authorised — the worst outcome is a link that 403s. It is a
   defensive requirement the plan stated and the code omits, so it should be fixed; it is not in the
   same class as the `decimal(20,9)` overflow, which is a reachable 500 with no corrupt-data
   precondition.

### What I would fix, in order

1. **#1** — validate the canonical buffer value after conversion. Cheap, and it is a reachable 500 on
   PostgreSQL that the suite structurally cannot catch.
2. **#2** — make Open include zero-balance lots with active reservations (per plan Step 1), and add the
   missing reservation fixture, since the plan asked for this test and it was never written.
3. **#5** — move the access assertion inside the transaction closure.
4. **#3** — make the shortage tile activate the `state=negative_forecast` filter, plus the test plan
   line 500 asked for.
5. **#6** — return no URL and a neutral label for inaccessible source records.
6. **#4** — retitle the two page views.
7. **#10** — add keyed `assertHasErrors` so the four period messages cannot be swapped for one
   another. Cosmetic; no plan or spec line requires it.
8. **#9 / #7 / #8** — leave. #9 is not a violation (see above), #7's fix is forbidden by plan line
   829 unless a driver-aware branch is accepted, and #8 is two identical indexed lookups.

For the avoidance of doubt, items 7 and 8 are **not** merge blockers and I would not fix them in this
branch. Items 1–3 are.

## Implemented (2026-08-30) — items 1–6 above

Six fixes went into the worktree on `codex/production-bench-inventory-ux`. Every one was written
test-first: the test was run and observed failing before the code changed, then re-run green.
Nothing is committed.

### 1. Canonical buffer overflow (#1) — merge blocker

`app/Actions/Inventory/SaveMaterialBuffer.php` validated the *entered* magnitude before converting to
canonical grams. Nine nines passes an 11-integer-digit check as entered, but `×1000` (kg) or
`×453.59237` (lb) pushes it to 12+ integer digits and PostgreSQL raises a numeric-overflow 500.

- Added `CanonicalIntegerDigits = 11` and moved the magnitude check to *after*
  `$this->massConverter->toGrams(...)`.
- New message `production_bench.inventory.validation.buffer_overflow`, added to
  `lang/en/production_bench.php` and to all six locales in
  `database/seeders/data/interface-translations.json`.

Tests in `tests/Feature/WorkspaceMaterialSettingTest.php`:

- `rejects a buffer that overflows the canonical column only after conversion` (dataset: metric kg, US
  lb) — RED before, both reject after conversion.
- `accepts the largest metric buffer that still fits after conversion` — `99999999` kg →
  `99999999000.000000000` g. Pins the boundary on the correct side.

> Catalogue trap worth remembering: `interface-translations.json` must stay sorted by group **and**
> key (`strcmp`); the first insert went after `buffer_precision` and broke six tests with
> `InvalidInterfaceTranslationCatalogue`.

### 2. Open scope hides reserved zero-balance lots (#2) — merge blocker

Open was `physical <> 0`, which hid a lot that is net-zero but still carries an active reservation
(i.e. over-reserved). Spec line 88/plan Step 1 want Open to surface it.

- `app/Services/Inventory/WorkspaceMaterialInventoryQuery.php` — Open is now
  `physical <> 0 OR active_reserved <> 0`; Exhausted is `physical = 0 AND active_reserved = 0`.
- `app/Services/Inventory/MaterialActivityService::openLots()` — same OR, expressed as correlated
  subqueries rather than the `withSum` aliases, because PostgreSQL and SQLite disagree on alias
  visibility in `WHERE`.

Tests: `keeps reserved zero-balance lots in the default open scope`
(`tests/Feature/ProductionBenchPagesTest.php`) and `keeps zero-balance lots that still carry an active
reservation open` (`tests/Feature/MaterialActivityServiceTest.php`, covers the released-reservation
case too). Both were RED against the pre-fix predicate — I reverted the `whereRaw` to check, then
restored.

### 3. Access assertion outside the transaction (#5) — merge blocker

`app/Services/Inventory/WorkspaceMaterialSettings::synchronize()` checked write access *before*
opening the transaction. `.ai/rules/app.md:22` requires it to be re-asserted inside. The service now
constructor-injects `ProductionBenchAccess`, takes the `User $actor`, and calls
`$this->access->assertWritable(...)` as the first statement inside the `DB::transaction(..., attempts: 5)`
closure. No workspace row lock is taken: this is a single-row upsert already serialised by the
`lockForUpdate()` on the setting, and locking the workspace would serialise every buffer edit in the
workspace.

Test: `re-asserts write access inside the buffer transaction`
(`tests/Feature/WorkspaceMaterialSettingTest.php`) cancels the entitlement from a
`TransactionBeginning` listener — i.e. after anything that ran before the transaction — and asserts
the buffer is not persisted. RED before (`ValidationException not thrown`).

### 4. Shortage tile (#3)

`resources/views/livewire/production-bench/inventory-index.blade.php:27` was a bare `<dd>`. It is now
a real toggle button wired to a new `InventoryIndex::toggleShortageFilter()` that flips
`stockState` between `all` and `negative_forecast` (and resets the page). Toggle rather than apply-once
because the tile stays visible while the filter narrows the table. It carries `aria-pressed` and an
`aria-label` of `filter_negative_forecast` (the number alone is not an accessible name), and reuses
the established `focus-visible:outline-2 … focus-visible:outline-[var(--color-accent)]` treatment.

Test: `activates and clears the negative forecast filter from the shortage summary tile`.

### 5. Source-link accessibility (#6)

`InventoryMaterialDetail::sourceUrl()` / `sourceLabel()` matched on the morphTo `source` type and
returned a route plus identifier unconditionally. The source is a morphTo, so nothing in the schema
ties it to the movement's workspace; a foreign record would print its identifier here and link to a
route that 404s at the destination (both detail components scope by `workspace_id`).

Both public methods now delegate to one private `sourceLink()` that returns `['url', 'label']` only
when the record's `workspace_id` matches the current workspace, else `null`. The view already renders
`source_not_available` when either half is null, so the neutral-label half of the plan requirement was
already satisfied.

To be precise about severity: this is a **defensive** guard, not a live disclosure. Both destination
components (`ProductionDetail`, `ReceiptDetail`) query with `where('workspace_id', …)` and 404 on
mismatch, so no foreign data is reachable through the link. What leaks today is only the identifier
string on this page.

Test: `links only the movement sources that belong to the workspace`
(`tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`) — renders one in-workspace and one
cross-workspace `GoodsReceipt` source and asserts `DEL-OURS` is seen, `DEL-THEIRS` is not, and
"No source link" appears.

### 6. Browser titles (#4)

`production-bench/inventory.blade.php` and `inventory-stock.blade.php` hardcoded
`'Inventory · Production Bench · '` / `'Stock · Production Bench · '` and a literal
`'Production Bench'` page heading, against the convention every sibling production-bench view uses
(`__('production_bench.…').' · '.config('app.name')` and `__('production_bench.title')`). Both now
use `__('production_bench.inventory.stock_by_material')` / `lot_register` — the same keys the
component's `<h1>` renders — so the tab, the heading, and history agree.

Test: `gives each inventory page a translated browser title of its own`.

### Verification

- Pint: **1376 files, PASS** (one `ordered_imports` fix applied).
- Focused runs after each change: `WorkspaceMaterialSettingTest` 19, `ProductionBenchPagesTest` 23,
  `MaterialActivityServiceTest` 6, `ProductionBenchInventoryMaterialDetailTest` 15 — all green.
- **Full suite: 2723 passed / 25 skipped / 0 failed, 175.8 s.** Baseline was 2714 / 25 / 0, and this
  branch adds exactly nine tests (4 buffer, 3 pages+scope, 1 `openLots`, 1 source link), so 2714 + 9
  = 2723 reconciles exactly. Skipped count unchanged.

### Still open

- **#7 (unbounded aggregation)**, **#9 (service location)**, **#8 (duplicated setting lookup)** and
  **#10 (keyed `assertHasErrors`)** are deliberately **not** fixed. The reasoning is in the
  "Reclassified / rejected" section above; #7 in particular cannot be fixed as suggested without
  violating plan line 829, because SQLite `SUM()` over a `decimal` column drifts by 1e-9 where
  PostgreSQL `SUM(numeric)` is exact.
- **Combobox staleness caveat** — Codex accepted the retraction but asked that the claim be softened
  pending browser verification. The vendor-source trace stands; the two-click falsification
  (pick a category, open the subcategory combobox; `lipids` → 4 options, `waxes` → 5) is still the
  cheapest way to settle it.

## Carried caveats

- **PostgreSQL vs SQLite**: the suite runs SQLite. The NULL-ordering guard in #3 is written to be
  driver-independent, but a green suite cannot *prove* PG behaviour — worth a smoke check against a
  real PG database before merge. (The step-1 lot-date gap is a second instance of the same theme:
  PG would have 500'd where SQLite shrugged.)
- **Latent pre-existing gap** (recorded in the review, not part of this scope): there is still no UI
  path to put a first buffer on a genuinely untracked material, because the buffer editor lives on
  the detail page and the detail page 404s untracked subjects.
