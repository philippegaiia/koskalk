# Decimal alignment consolidation — plan

**Status:** PROVISIONAL — owner decision 2026-09-04: **do not implement.** Kept for the future.
The only change in place from this whole thread is the earlier, separately-approved `text-right`
on the ingredient-index price input. Part 1's `pr-6` was built and then reverted the same day.
**Scope:** the ingredient-index price input (Part 1) and the workbench decimal anchor (Part 2)

---

## Part 0 — What the audit actually found

There are **40 call sites** combining `.sk-decimal-aligned` with
`:style="decimalAlignmentStyle(x)"`, across 7 Blade partials under
`resources/views/livewire/dashboard/partials/recipe-workbench/`.

| Group | Sites | Decimal count | Verdict |
|---|---:|---|---|
| Literal `format(x, N)` | 30 | fixed (19×2, 7×3, 2×5, 1×1, 1×4) | **JS redundant** |
| `additionWeightDecimals` (post-reaction 64/67/139/142) | 4 | constant per column | **JS redundant** |
| costing-tab inputs (109, 181) | 2 | fixed 2 | **JS redundant** |
| `oilWeightDecimals` (reaction-core 127/130/153) | 3 | **varies** | **JS needed** |
| `formatPackagingQuantity` (costing-tab 170) | 1 | **varies** | **JS needed** |

### **36 of 40 sites (90 %) do not need the anchor. 4 do.**

**Why the 4 are different.** `oilUnit` is a *single user-selectable scalar for the whole formula*
(`changeOilUnit()`, `component.js:492-501`; initialised from `payload.preferredMassUnit` with a
`'g'` fallback, `component.js:159-161`). It is **not** derived per row and **not** auto-promoted
from g→kg as the batch grows. So the unit is constant down a column, and the only thing that can
vary the fraction length is a **magnitude threshold** inside
`massDisplayDecimals()` (`mass.js:58-90`):

- `oilWeightDecimals` → profile `standard`, unit `g`: `>= 1000 ? 1 : 2`. A 1500 g batch in grams
  shows one oil as `1200.0` and another as `50.00`. **1 character of separator drift** if
  right-aligned. With unit `oz`: `>= 1 ? 2 : 3`, same 1-char drift.
- `formatPackagingQuantity()` (`costing-section.js:512-522`): integers get **0** decimals,
  everything else **3**. A packaging row of `4` and another of `2.500` in the same column →
  **3 characters of drift**. This is the worst case in the codebase and the least discoverable.

By contrast `additionWeightDecimals` (profile `addition`) returns 4 for kg/lb and 3 otherwise with
**no magnitude term at all** — so it is constant down a column and its 4 sites are pure overhead.

### The root cause is not the alignment technique

`text-right` fails on those 4 sites because **the decimal count varies row-by-row within one
column**. Fix that and the entire JS mechanism becomes deletable. That is the real decision
below — it is a *precision* decision, not an alignment one.

---

## Part 1 — Price input: nudge it off the hard right edge

**Current state (uncommitted):** `ingredients-index.blade.php:178` is
`class="sk-input numeric text-right"`. Separator alignment is correct; the value hugs the right
padding edge.

### ⚠️ The one thing that must not happen

**Padding-right that varies per value destroys the alignment we just fixed.**
Separator x-position = `right edge − padding-right − (2 × 1ch)`. If `padding-right` is computed
from the value, the separator moves with the value and the misalignment returns. Any "conditional"
padding must be conditional on something *other than the number* — e.g. "this input only", or a
breakpoint. **Never** per-row.

### Arithmetic (exact — `--font-numeric` is monospace, so `1ch` = one glyph advance)

`--font-numeric` is `ui-monospace, SFMono-Regular, Menlo, …` (`soapkraft.css:4`). At
`font-size: 0.875rem` (14px) one advance is ≈8.4px, and every glyph — separator included — is
exactly `1ch`. Prices are always 2 decimals with no grouping (`NumberLocale::formatDecimal`), unit
is kg or lb (`MassDisplaySystem::priceUnit()`), integer part 1–5 digits.

Widest realistic string: `99999.99` = **8ch ≈ 67px**.

The cell is `min-w-28` = 112px; `.sk-input` sets `padding: 0.75rem 1rem` (16px each side) →
**80px content box**.

| Option | padding-right | content box | 8ch fits? | leftward shift |
|---|---:|---:|:--:|---:|
| today | 16px (`1rem`) | 80px | ✅ | — |
| **B — `pr-6`** | **24px** | **72px** | **✅ (8.6ch)** | **8px** |
| C — `pr-7` | 28px | 68px | ⚠️ marginal (8.1ch) | 12px |
| D — `pr-8` | 32px | 64px | ❌ (7.6ch) | 16px |

> **Note:** full centring is not reachable at this width. Putting the separator on the 50 % line
> needs `padding-right ≈ W/2 − 2ch ≈ 39px`, which leaves 57px — too narrow for an 8-character
> price. Centre-parking requires widening the column first, which costs horizontal budget against
> the Tier-2 density target (fits 1280+).

### Recommended step

**Option B — add `pr-6` to the price input only.** One utility class, no JS, no CSS, no layout
change, trivially reversible.

```blade
class="sk-input numeric text-right pr-6"
```

This is safe because `.sk-input` lives in `@layer components` (`app.css:213`) and Tailwind v4 emits
`pr-6` into `@layer utilities`, which is registered after `components` — **layer order wins over
the padding shorthand**, so the override lands without `!important`.

**Verification (mandatory, per the measurement rule):**
1. Take **≥3 samples** at 1280px and note the median — single headless-Chrome measurements are
   flaky by up to 15px (one scrollbar).
2. Confirm a 5-digit price (`99999.99`) does not clip at the narrowest supported width.
3. Confirm decimals still line up across rows with 1-, 3- and 5-digit integer parts.

**Alternative if 8px reads as too subtle:** widen the cell `min-w-28` → `min-w-32` and use
`pr-8`. Costs 16px of horizontal budget — must re-verify the 1280px fit, since the density pass
exists precisely to hit that target.

---

## Part 2 — Workbench: consolidate onto one mechanism

Three options, in ascending order of ambition. **None is required for correctness** — the current
code works. This is about having two mechanisms doing one job.

### Option 1 — Strip the 36 redundant sites *(low risk, moderate churn)*

Replace `sk-decimal-aligned` + `:style="decimalAlignmentStyle(x)"` with `text-right` (plus `w-full`
for the `<span>` readouts) at the 36 fixed-decimal sites. Keep the anchor on reaction-core
127/130/153 and costing-tab 170.

- **For:** purely mechanical; no visible change; deletes ~36 inline-style bindings.
- **Against:** 36 edits across 7 files with **zero test coverage** (`grep -rn 'sk-input' tests/`
  is empty and nothing pins `decimalAlignmentStyle`). Every edit is unverified by the suite, and
  the anchor survives — so you keep both mechanisms anyway.

### Option 2 — Delete the JS entirely by fixing the root cause *(recommended)*

Make the decimal count **uniform per column** at the 4 genuine sites, then `text-right`
everywhere and remove `.sk-decimal-aligned`, `decimalAlignmentStyle()`, `syncFormattedInput()`'s
offset coupling and `--sk-decimal-offset`.

Concretely:
- `oilWeightDecimals` → one count for the whole column (e.g. `2` for g/oz, `3` for kg/lb) instead
  of the `>= 1000` magnitude switch.
- `formatPackagingQuantity` → always `3` (drops the integer special case).

- **For:** one mechanism instead of two; no per-row inline style for Alpine to re-evaluate on
  every recompute; no `ch`-width coupling to keep in sync with the font.
- **Against — the real trade-off:** this changes **displayed precision**, not just alignment.
  `1200.0` becomes `1200.00`; `4` becomes `4.000`. Longer strings, and it discards the deliberate
  "big numbers compact down" behaviour in `massDisplayDecimals`. **This is a product decision and
  needs the owner's call.**

### Option 3 — Keep variable precision, drop the JS for read-only cells

Split integer and fraction into two spans in a 2-column grid (`text-right` / `text-left`). Zero JS,
variable precision preserved.

- **Against:** cannot work on `<input>` — you cannot split an input's value. So reaction-core:127
  and the costing inputs would still need JS, leaving you with two mechanisms again. More markup
  complexity than it removes. **Not recommended.**

### Recommendation

Hold Part 2 until Part 1 is settled — it is a much smaller change and unblocks the question of
whether the nudge is even wanted. Then decide Option 2 vs Option 1 on the precision question:
**if the owner is willing to accept uniform decimals per column, Option 2 deletes the whole
mechanism; if the compaction behaviour must stay, Option 1 is the safe trim.**

---

## Risks / open questions

- No test covers any of this. Any Part 2 change is verified by eye only. Consider one contract
  test asserting each numeric cell carries exactly one alignment mechanism.
- The Vite dev server is currently **down** (`public/hot` absent), so `koskalk.test` is serving the
  stale 2026-08-30 bundle with **0 of 22 badge hues**. Nothing here is visible until the owner runs
  `npm run dev`.
- Part 1's `pr-6` is uncommitted, as is the `text-right` change beneath it. `main` is 13 ahead /
  0 behind `origin/main`, unpushed.
