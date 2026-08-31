# Code review — Production Bench Inventory UX

> **Superseding status (2026-08-31): remediated and merged into local `main`.** This report is a
> historical review of HEAD `4466ca87`; its "not ready to merge" verdict was correct for that
> reviewed revision, not for the final branch. The blocker and corrective findings were resolved,
> final feature verification passed **2,788 tests / 25 skipped / 0 failed**, and merge commit
> `643918a3` integrated the branch. The merged-main inventory suite passes **220 / 0 failed**.
> The four unrelated full-suite failures on local `main` are documented in the companion
> implementation report and WorkBuddy memory. Nothing was pushed to `origin`.

**Branch:** `codex/production-bench-inventory-ux`
**Worktree:** `.worktrees/production-bench-inventory-ux` (HEAD `4466ca87`)
**Range:** `2526eaa4..HEAD` — `ad44d3b4`, `29a5b2a0`, `cfe58c09`, `4466ca87`
**Footprint:** 27 files, +4674 / −349

**Compared against:**
- `docs/superpowers/plans/2026-08-30-production-bench-inventory-ux.md`
- `docs/superpowers/specs/2026-08-30-production-bench-inventory-ux-design.md`
- All 13 applicable `.ai/rules` files

**Verification:** 218 tests pass (118 targeted + 100 integration). Pint clean. `truss:doctor` — no findings.

> **Revision 2** — after a counter-review of this report. Three of my calls were wrong and are
> corrected in place: I wrongly defended `InventoryIndex` (#7), proposed a wrong remedy for
> `SaveMaterialBuffer` (#2), and over-retracted the transaction finding (#13). Three minor
> findings are withdrawn (#9, #10, #12). The verdict and the blocker are unchanged. See
> [Response to counter-review](#response-to-counter-review) at the end.

---

## Verdict

**Not ready to merge. One P1 data-integrity defect must be fixed first.**

The buffer quantity is displayed and persisted in the wrong unit, producing a 1000× error
in a kilogram workspace. Everything else is Important-or-below and none of it blocks the
architecture, which is sound.

---

## Critical

### 1. Buffer quantity is displayed and saved in the wrong unit (1000× error)

- `app/Livewire/ProductionBench/InventoryMaterialDetail.php:52-59` (read)
- `app/Livewire/ProductionBench/InventoryMaterialDetail.php:86-98` (write)
- `resources/views/livewire/production-bench/inventory-material-detail.blade.php:48` (label)

Settings store canonical **grams**. `mount()` loads that raw canonical value straight into
`$this->bufferQuantity`, but the Blade label renders the field as
`{{ __('production_bench.inventory.buffer_stock') }} ({{ $displayUnit }})` — the workspace
**display** unit. `saveBuffer()` then persists the typed value with no `MassConverter`
call at all.

Measured in a metric (kg) workspace with a 1200 g buffer:

```
displayUnit      : 'kg'
status line value: '1.20'              <- correctly converted, via formatQuantity()
input field value: '1200.000000000'    <- raw canonical grams, labelled "kg"
```

Both render in the same section of the same page. The status line is right; the input
below it is wrong by a factor of 1000.

The write direction is broken too:

```
user typed '1.2' into a (kg) field
stored canonical : '1.200000000'
expected         : '1200.000000000'
```

So entering "1.2" — meaning 1.2 kg — persists 1.2 **grams**.

This violates plan Task 6 Step 6, which requires "canonical display-unit conversion through
`MassConverter`". Note the rest of the page already converts correctly: `formatQuantity()`
(line 339-344) runs every other quantity through `MassConverter::fromGramsSigned()`. The
buffer field is the single exception.

**Spec conflict flagged, resolved as instructed.** Spec line 197 says buffer quantities are
"expressed in the subject's canonical stock unit", which reads against the plan. Per your
decision I treat the plan as governing for the UI. My reasoning for the record: line 197
sits next to line 107 ("Store the optional quantity in canonical units"), so it most
plausibly describes *storage*, not presentation. The wording should still be reconciled in
the spec so the next reader does not have to re-derive this.

**Fix:** convert on the way in (`MassConverter` display→grams before `SaveMaterialBuffer`)
and on the way out (grams→display when seeding `bufferQuantity` in `mount()` and after a
save at line 96). Packaging is unit-less and must stay unconverted.

---

## Important

### 2. Route-bound subject is not locked and is never re-validated — but it is *not* remotely exploitable

- `app/Livewire/ProductionBench/InventoryMaterialDetail.php:41`, `:43`

`public string|Ingredient|PackagingItem $subject` and `public string $subjectType` are
public and writable, with no `#[Locked]`. Plan Task 6 Step 4 explicitly requires "separate
nullable locked identifiers rather than a writable union-model property". `resolveSubject()`
— which applies the 404 and `tracks()` guards — runs only in `mount()`, never on subsequent
requests.

I tested this rather than assuming. The supplied finding rated it P1 on the basis that "a
tampered Livewire request could alter the material context". **That mechanism is wrong**,
and the severity does not hold:

| Scenario | Result |
| --- | --- |
| Untouched snapshot | Verifies OK *(positive control — harness works)* |
| Tamper `data.subject.key` | **REJECTED** — `CorruptComponentPayloadException` |
| Tamper + recomputed HMAC | Subject **is** redirected — write landed on an untracked ingredient |
| Tamper + recomputed HMAC, cross-workspace | **Refused** — no write; `assertSubjectAccessible()` holds |

Livewire HMACs the entire snapshot with the app key
(`Checksum::generate()` = `hash_hmac('sha256', json_encode($snapshot), $app_key)`, covering
`data`), so an attacker cannot tamper without the key. There is no remote exploit here.

What *is* real is the absence of defence in depth. The property round-trips at all when it
has no reason to, and if the checksum is ever defeated — a leaked `APP_KEY`, or a future
Livewire vulnerability of the kind that has occurred before — the subject becomes fully
redirectable. Worth fixing precisely because it costs nothing.

**Withdrawn sub-finding (revision 2).** Revision 1 proposed adding a `tracks()` check to
`SaveMaterialBuffer::assertSubjectAccessible()`. **That remedy is wrong and is retracted.**
`tracks()` is *derived from* settings — `WorkspaceMaterialCatalog::settingKeys()`
(`WorkspaceMaterialCatalog.php:174-187`) exists specifically so that "a safety-buffer setting
is itself evidence that the workspace tracks a material, even before a listing, lot, or
planned run exists", and plan Task 3 Step 4 (line 409) directs that settings keys be added to
tracked membership. Requiring `tracks()` before a write would foreclose the very case the
plan intends to enable: the buffer that first makes a material tracked.

It would also be redundant. `resolveSubject()` already aborts with 404 for untracked subjects
(`InventoryMaterialDetail.php:223`, `:233`), and the fix below re-applies that guard on every
mutation — which is where the defence belongs.

**Fix:** replace the union property with `#[Locked] public ?string $ingredientPublicId` /
`?string $packagingPublicId` per plan Step 4, and re-resolve the subject through
`resolveSubject()` inside every mutation. Do **not** add `tracks()` to the Action.

**Latent gap, noted not filed.** Because `resolveSubject()` 404s untracked subjects and the
detail page is the only place a buffer can be edited (no buffer control exists in
`inventory-index.blade.php`), there is currently no UI path that puts a *first* buffer on a
genuinely untracked material. The settings-only branch of `tracks()` is therefore unreachable
through the UI. That is a product gap, not a defect this branch introduced — flagging it so
it is not discovered accidentally later.

### 3. Open lots: unbounded and mis-ordered

- `app/Services/Inventory/MaterialActivityService.php:124` — no `->limit()`
- `app/Services/Inventory/MaterialActivityService.php:122-123` — `stocked_at` / `id` only
- `resources/views/livewire/production-bench/inventory-material-detail.blade.php:67-75`

Three separate deviations:

1. **No limit.** Plan Task 6 Step 5 asks for "a small fixed number such as 10". `->get()`
   loads every open lot.
2. **Wrong ordering.** Plan requires expiry → stocked date → lot code. Implemented as
   `orderByDesc('stocked_at')->orderByDesc('id')`. When fixing, handle NULL expiry
   explicitly — PostgreSQL defaults NULLs **last** on `ASC` but **first** on `DESC`, so a
   naive `orderBy('expires_at')` will sort differently than intended across engines.
3. ~~**No material column.** Spec line 128 lists material in every open-lot row.~~
   **Demoted (revision 2)** — spec line 128 does list material per open-lot row, so this is a
   genuine spec deviation, but the column is redundant on a single-material page. Moved to
   [Spec/UX decisions](#specux-decisions-not-defects).

### 4. Period movements are loaded unbounded

- `app/Services/Inventory/MaterialActivityService.php:161` — `->get()`

A 365-day custom period loads every movement into memory, plus a `loadMorph`. Plan Task 7
Step 5 says "Return paginated source rows"; spec line 224 requires "bounded query counts …
for large catalogues and lot histories".

### 5. N+1 hydration in the material inventory query

- `app/Services/Inventory/WorkspaceMaterialInventoryQuery.php:488-489`

`hydrateRow()` issues a fresh `find()` per row plus three eager loads. Measured:

```
queries @25/page  : 102      (4.1 per row)
queries @100/page : ~400
```

Two of the four per-row queries are pure waste: `hydrateRow()` eagerly loads `aliases` and
`identifiers`, but the only consumer, `localizedDisplayName()`, reads the `translations`
relation alone (confirmed via `localizedPlatformValue()`, `Ingredient.php:306-322`).

It is bounded by page size, so it honours the plan's "do not reload all matching materials".
But batching the page's subjects into a `whereIn` would bring this to roughly 5 queries
regardless of page size. Spec line 224 again.

### 6. The plan-mandated query-count test does not exist

Plan Task 3 Step 6 requires a test that asserts "a bounded query count using
`DB::enableQueryLog()` … the observed implementation count plus one". There is no
`enableQueryLog` or `assertQueryCount` anywhere in `WorkspaceMaterialInventoryQueryTest.php`,
`ProductionBenchPagesTest.php`, or `WorkspaceMaterialCatalogTest.php`.

This is the reason finding #5 shipped undetected. Add the test *before* fixing #5, so it
pins the improvement.

### 7. Both public components bypass the Filament form substrate — and this branch removed one that existed

- `app/Livewire/ProductionBench/InventoryMaterialDetail.php:28`
- `resources/views/livewire/production-bench/inventory-index.blade.php:48-134`, `:217-274`

**Correction (revision 2).** Revision 1 defended `InventoryIndex` against the supplied
finding, on the grounds that it implements `HasActions, HasForms` (`:54`) with
`InteractsWithForms` (`:58`). **That defence was wrong and is retracted.** I checked whether
the component had a Filament form; I did not check whether the *view* used it. It does not —
and the branch is what removed it:

| | Before (`2526eaa4`) | After (branch) |
| --- | --- | --- |
| `InventoryIndex::filtersForm(Schema)` | **present** (`InventoryIndex.php:117`) | **deleted** |
| Blade filter rendering | `{{ $this->filtersForm }}` (`:52`, `:104`) | raw `<input>`/`<select>` + `wire:model.live` |
| Remaining Filament components | filters + add-stock action | add-stock action only (`:287-376`) |

Every public control in the view is now hand-written: search (`:50`, `:217`), sort
(`:54`, `:274`), direction (`:65`), `materialType` (`:84`), `stockState` (`:92`),
`demandFilter` (`:102`), `lotScope` (`:221`), `lotStatus` (`:229`), `lotSupplier` (`:237`),
`lotOrigin` (`:246`), `lotStockedFrom`/`To` (`:257`, `:261`), `lotExpiry` (`:265`). The only
Filament markup left in the file is `<x-filament-actions::modals />` (`:351`). The surviving
`Select`/`DatePicker`/`TextInput`/`Textarea` imports serve the add-stock action alone.

So this is not merely a missing substrate — it is a **regression**. The supplied finding was
right about both components, and severity should be raised accordingly: the branch deleted a
working Filament `filtersForm` and replaced it with unvalidated raw inputs bound straight to
public properties.

The detail component additionally violates plan Task 6 Step 6, which requires a Filament
Action modal with `LocalizedDecimalInput::make('buffer_quantity')`. It implements neither
interface and uses a raw `<input type="text" inputmode="decimal">`.

Note this is also what makes fix #1 more involved: doing it properly means moving the field
onto `LocalizedDecimalInput`, which handles decimal normalization.

---

## Minor

### 8. Custom-period messages sit outside the validation namespace

`lang/en/production_bench.php:1039-1041` — `period_date_required` / `_invalid` / `_order`
are siblings of `period`, not under a `validation` key, contrary to `.ai/rules/lang.md`.

The correct target is the **existing** `validation` sub-array inside `inventory` at
`lang/en/production_bench.php:1006`, which already holds `buffer_required`, `buffer_invalid`,
`buffer_precision`, and `buffer_forbidden`. Note this is **not** `production_bench.validation.*`
as the supplied findings proposed — no such top-level key exists (`production_bench.php:548`
is `production.validation`, a different domain).

### 9. ~~Name sorting uses the canonical name while displaying the localized one~~ — withdrawn

**Withdrawn (revision 2)** as a defect. `WorkspaceMaterialInventoryQuery.php:166` builds
`sort_name` from canonical `display_name` while `:516` displays `localizedDisplayName()`, so
in a non-English locale the sort order will not match the visible order. But the plan itself
mandates this architecture: `sort_name` is a SQL-level alias (plan line 431/440/444) and
localization happens in PHP after hydration. The plan never specifies how `sort_name` is
derived, so the implementation is not in conflict with it — the mismatch is inherent to
sorting in SQL while localizing in PHP. Moved to
[Spec/UX decisions](#specux-decisions-not-defects).

### 10. ~~`buffer_quantity` is NOT NULL; the spec says nullable~~ — withdrawn

**Withdrawn (revision 2).** The migration's non-null column is **not** an oversight — it
matches the plan verbatim. Plan Task 1 (line 162) specifies
`$table->decimal('buffer_quantity', 20, 9);` with no `->nullable()`, while making the two
subject columns explicitly `->nullable()`. That contrast is deliberate.

The design spec (lines 109/111) says "nullable" and anticipates a row carrying other values
without a buffer, but v1 has no such other values, and the plan chose the stronger invariant:
no buffer means no row (`synchronize()` deletes on null). Making it nullable now would weaken
that invariant to buy a capability nothing uses. This is a spec-vs-plan divergence worth
reconciling in the spec, not a code defect.

### 11. `foreach` for array transformation

`InventoryMaterialDetail.php:351` — `.ai/rules/app.md` reserves `foreach` for side-effectful
iteration and prefers collection pipelines for transformation.

### 12. ~~Full unique indexes instead of the plan's partial indexes~~ — non-actionable

**Demoted out of the findings (revision 2).** The migration uses
`$table->unique(['workspace_id','ingredient_id'])` where plan Task 1 (lines 177-178)
specified partial indexes (`WHERE ingredient_id IS NOT NULL`). Behaviourally identical:
NULLs are distinct in unique indexes on both PostgreSQL and SQLite, and the exact-subject
CHECK guarantees exactly one non-null subject per row. Recorded here only as a plan
deviation so it is not re-litigated; no code change warranted.

### 13. `WorkspaceMaterialSettings` locks the row but never re-asserts access inside the transaction

- `app/Services/Inventory/WorkspaceMaterialSettings.php:24-40`

**Partially restored (revision 2).** Revision 1 retracted the supplied transaction finding
outright. That was **too broad** — I conflated two separable requirements and threw out both.
`.ai/rules/app.md:22` asks for `withoutGlobalScopes()->lockForUpdate()` *and* to "re-assert
access inside transactions":

| Requirement | Status | Verdict |
| --- | --- | --- |
| `lockForUpdate()` | **Present** (`:27`) | Compliant — the counter-review's "does neither" is imprecise |
| `withoutGlobalScopes()` | Absent | **Retraction stands.** `WorkspaceMaterialSetting` registers no global scope (only `HasFactory`, lines 19-42). Adding it would be cargo-cult. |
| Re-assert access **inside** the transaction | **Absent** | **Real gap — revision 1 wrongly cleared it** |

`DB::transaction()` closes over only `$bufferQuantity` and `$keys`. Authorization runs
earlier, in `SaveMaterialBuffer`, outside the transaction.

This is not a paper rule. Sibling services consistently lock and then re-assert against the
locked model:

- `ProductionBenchAccess.php:88` — `assertCanManage($actor, $lockedWorkspace)` *(same family)*
- `MediaAssetLibraryService.php:36`, `:53` — `Gate::forUser($user)->authorize(...)`
- `MediaAssetUploadService.php:140` — `assertCanEditWorkspace($user, $lockedWorkspace)`
- `IngredientMarketLabelService.php:58`, `:97`, `:132` — `assertPlatformIngredient($lockedIngredient)`

`WorkspaceMaterialSettings` is the outlier. Severity stays **P3 / defence in depth**:
`where($keys)` already includes `workspace_id`, so the locked row cannot belong to another
tenant, and no cross-tenant exploit is demonstrated — the counter-review concedes this.

**Fix:** pass the actor into `synchronize()` and re-assert
`ProductionBenchAccess::assertCanManage($actor, $workspace)` inside the closure, matching
`ProductionBenchAccess.php:88`. Cheap, and it makes the service safe to call from any future
caller rather than only from an already-authorized one.

---

## Strengths

- **`InventoryIndex`'s server-side filtering is genuinely well built** *(qualified in revision 2)*.
  Every filter is `#[Url]`-bound, every one has an allow-list, `normalizeLotDate()`
  (`:726-741`) returns `''` for anything unparseable so bad dates degrade to "no filter"
  instead of a 500, and the lot scope uses the portable correlated-subquery form the plan
  explicitly demanded (`:470-473`) rather than filtering a select alias. This satisfies spec
  line 203 (tampered values fall back safely).
  **But the presentation layer regressed** — those filters used to be a Filament
  `filtersForm` and are now raw inputs (see #7). The state handling is the strongest part of
  the branch; the view is not.
- **The tenant boundary holds under active attack.** I tried to break it with a recomputed
  checksum and could not: `SaveMaterialBuffer::assertSubjectAccessible()` refuses
  cross-workspace writes.
- **The query service is disciplined.** Workspace-scoped at every level, bound parameters
  throughout the search, allow-lists for sort and per-page, `bcadd` rather than float math.
- **`SaveMaterialBuffer` is a clean Action.** Asserts writability, asserts subject
  accessibility, normalizes through `NumberLocale`, keeps precision checks, and delegates
  persistence to the service.
- **Migration is reversible** with real `down()` logic per `.ai/rules/migrations.md`.
- **Verification is green:** 218 tests, Pint clean, truss doctor no findings.

---

## Reconciliation with the supplied findings

| # | Supplied finding | Verdict |
| --- | --- | --- |
| S1 | Route-bound state mutable, `#[Locked]` missing | **Partly right, severity wrong.** Real violation; not remotely exploitable — HMAC blocks tampering. See #2. |
| S2 | Tenant access not re-asserted in transaction; missing `withoutGlobalScopes()` | **Split (revision 2): half retracted, half restored.** `withoutGlobalScopes()` really is moot — no global scope on `WorkspaceMaterialSetting`. But "re-assert access inside transactions" is a genuine, unmet requirement, and revision 1 wrongly cleared it. Restored as #13 (P3). |
| S3 | Inventory + detail bypass Filament forms | **Fully confirmed (revision 2 — my pushback was wrong).** Both are affected. The branch deleted `InventoryIndex::filtersForm()` and its `{{ $this->filtersForm }}` rendering; the surviving Filament components serve only the add-stock action. See #7. |
| S4 | Custom-period messages outside `production_bench.validation.*` | **Right diagnosis, wrong target.** Should be `production_bench.inventory.validation.*`. See #8. |
| S5 | `foreach` for array transformation | **Confirmed.** See #11. |
| S6 | Buffer shown and submitted in wrong unit | **Confirmed, and worse than described.** Broken in both directions; proven at 1000×. See #1. |
| S7 | Open lots unbounded | **Confirmed.** See #3. |
| S8 | Period activity unbounded | **Confirmed.** See #4. |
| S9 | Open-lot rows omit material; ordering wrong | **Confirmed with a demotion.** Ordering is wrong (Important). The missing material column is a real spec deviation but redundant on a single-material page — demoted to Spec/UX. See #3. |

New findings not in the supplied list: **#5** (N+1 hydration), **#6** (missing plan-mandated
query-count test), **#13** (access not re-asserted inside the transaction — the surviving half
of S2).

Withdrawn in revision 2: former #9 (localized sorting), #10 (nullable `buffer_quantity`), #12
(partial indexes) — see [Response to counter-review](#response-to-counter-review).

---

## Environment note

The worktree had no `.env`, so every test emitted a `file_get_contents(.env)` warning from
`tests/TestCase.php:13`. I added a gitignored `.env` (sqlite `:memory:`, matching what
`phpunit.xml` already forces). It is untracked and will not be committed — but any other
worktree will hit the same warning until it has one.

## Suggested fix order

Revised in revision 2 to fold in the counter-review's ordering.

1. **#1** — buffer display-unit conversion, on read *and* write. Add kilogram **and**
   imperial regression tests; the imperial path is the one likeliest to break silently.
   Data integrity, blocks merge.
2. **#6** then **#5** — add the plan-mandated query-count test *first*, so it pins the
   improvement, then batch material hydration.
3. **#2** — replace mutable route state with `#[Locked]` public identifiers and re-resolve
   the subject through `resolveSubject()` on every mutation. **Do not** add `tracks()` to
   `SaveMaterialBuffer` (see #2, withdrawn sub-finding).
4. **#3**, **#4** — limit and re-order open lots (handle NULL expiry explicitly; PostgreSQL
   and SQLite differ), paginate period activity rows.
5. **#7** — restore Filament-backed public filters and forms in **both** components, and
   move the buffer field onto `LocalizedDecimalInput`. This is the cleanest place to land the
   #1 conversion, since it handles decimal normalization.
6. **#8**, **#13** — validation-key placement and access re-assertion inside the transaction.
7. **#11** — `foreach` → collection pipeline.

**Not scheduled** — see [Spec/UX decisions](#specux-decisions-not-defects): the open-lot
material column and localized sorting. Both are judgement calls for the owner, not defects.

---

## Spec/UX decisions (not defects)

Two items were filed as findings in revision 1 and are better handled as product decisions.

1. **Open-lot material column.** Design spec line 128 lists material in every open-lot row;
   the implementation omits it. The spec is explicit, but the column is redundant on a
   single-material page. **Decide:** add the column, or amend the spec. Not a blocker either
   way.
2. **Localized sorting.** `sort_name` is derived from the canonical name (`:166`) while the
   row displays `localizedDisplayName()` (`:516`), so in a non-English locale the sort order
   will not match the visible order. The plan mandates sorting on a SQL alias and localizing
   in PHP, so this is structural, not an oversight. **Decide:** accept canonical sorting, or
   budget for a localization-aware sort key.

Also worth a product look, flagged under #2: there is currently **no UI path to set a first
buffer on a genuinely untracked material**, because `resolveSubject()` 404s untracked
subjects and the detail page is the only place a buffer can be edited. The settings-only
branch of `tracks()` is therefore unreachable through the UI. Pre-existing, not introduced
here.

---

## Response to counter-review

The counter-review challenged three of my calls. Two were my errors; one was half right.

**I was wrong — `InventoryIndex` does bypass Filament forms (#7).** Accepted in full. My
defence rested on the component implementing `HasActions, HasForms`, which was true but
irrelevant: I verified the component and never checked the view. The branch deleted
`filtersForm()` and replaced `{{ $this->filtersForm }}` with raw inputs — so this is a
regression, not just a missing substrate, and the supplied finding was right about both
components. This is the most significant correction in this revision.

**I was wrong — adding `tracks()` to `SaveMaterialBuffer` (#2).** Accepted. `tracks()` is
*derived from* settings (`settingKeys()`, plan Task 3 Step 4), so requiring it before a write
would foreclose the exact case the plan intends: the buffer that first makes a material
tracked. It would also be redundant with the `#[Locked]` re-resolve fix. Retracted.

**Half right — the transaction finding (#13).** The counter-review is right that I should not
have retracted it wholesale, and wrong that the code "does neither". `lockForUpdate()` is
present at `WorkspaceMaterialSettings.php:27`. I have split the finding:
- `withoutGlobalScopes()` — **retraction stands.** `WorkspaceMaterialSetting` registers no
  global scope. This half of the counter-review's claim does not survive inspection, and
  adding the call would be cargo-cult.
- Re-assert access inside the transaction — **restored.** I over-retracted. It is a real
  requirement, and the codebase follows it consistently (`ProductionBenchAccess.php:88` in
  the same family, plus `MediaAssetLibraryService`, `MediaAssetUploadService`,
  `IngredientMarketLabelService`). Restored at P3, matching the counter-review's own
  assessment that no exploit is demonstrated.

**Accepted demotions.** The open-lot material column (#3 → Spec/UX), localized sorting
(#9 → Spec/UX), nullable `buffer_quantity` (#10 → withdrawn; the plan specifies non-null
deliberately at line 162), and partial indexes (#12 → non-actionable).

**Unchanged.** The verdict, the P1 blocker (#1), and the confirmed findings: open lots
unbounded and mis-ordered, period movements unbounded, N+1 hydration, missing query-count
test, `#[Locked]` violation, validation namespace, `foreach`.

One thing I would add that neither review raised: **the buffer conversion fix needs an
imperial test, not just metric.** The plan mentions canonical display-unit conversion through
`MassConverter`, and the metric case is the obvious one. An imperial workspace converting
grams to pounds *and back* is where a rounding or precision bug will hide, and
`decimal(20, 9)` gives plenty of room for one.
