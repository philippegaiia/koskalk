# CSS, UI and frontend — koskalk deep reference

Moved out of `MEMORY.md` 2026-09-04 to keep that file under the injection limit.

## Build / preview

- **Owner runs `npm run dev`; do NOT run `npm run build` unprompted.** `public/build` is gitignored
  and is the *deploy* artifact — it goes stale during branch work, which is normal.
- **Check `public/hot` before believing `public/build`.** If `public/hot` exists (it holds the Vite
  dev-server URL, e.g. `https://koskalk.test:5173`), `@vite` serves **from source** and the build
  directory is irrelevant. If it doesn't, Blade falls back to `public/build` — gitignored, stale,
  silently serving an *old* CSS bundle. **Merging to `main` is not the same as the owner seeing the
  change.** Verify: `curl -s "$(cat public/hot)/resources/css/app.css" | grep -c '<your-new-class>'`.
- Design-polish contract tests read `resource_path('css/app.css')` — the **source**, never the
  bundle. The suite is green whether or not `public/build` is current.
- **Tracked Markdown is scanned as plain text and can break the production build.** `app.css`
  uses a bare `@import 'tailwindcss'`, so Tailwind auto-scans every file that is not
  gitignored — including `.workbuddy-ai/memory/*.md`. It understands neither backticks nor
  code fences, so a class-shaped string whose measurement is a *symbol* (an `N`, or
  `<value>`) is read as a real utility and emitted as a container query with an invalid
  measurement. The production minifier rejects it and **PHP tests cannot see it** — the suite
  stays green while `npm run build` fails. **Never write placeholder class names in tracked
  files; describe the pattern in prose, or use a concrete valid value.** This broke the build
  on 2026-09-09 (the owner fixed it) and `@source not` for `.workbuddy-ai` is the fallback if
  it recurs.

## Visual language

- **Surfaces are lifted by shadow, never outlined.** `.sk-card` has no `border` (internal
  `border-b` / `divide-y` only). Shadow lives in `--shadow-card`. Reference: ingredients page.
  Do **not** symmetrise `.sk-nav-rail`'s `padding-inline: 1.125rem 0.75rem` — the start excess is
  the nesting signal for level-2 tabs.
- **Undefined CSS custom properties fail silently.** An unresolvable `var()` is invalid at
  computed-value time: non-inherited props (background) fall back to **initial** (transparent);
  inherited props (color) fall back to **inherit**. Nothing flags it. Guard:
  `ProductionBenchLayoutTest` asserts every `var(--color-*)` in production bench views is defined
  in one of the four stylesheets.

## Badges

- **Per-category badge colours cannot ride on `getColor()`.** Filament's `HasColor` collapses
  categories into semantic buckets (Koskalk has 5× `gray`, 3× `danger`); a categorical palette with
  one hue per case can't be derived from that. Fix: `IngredientCategory::badgeVariant()` returning
  `$this->value` — CSS emits `sk-badge-<value>`, `getColor()` left untouched for Filament. Literals
  in `app.css`, not `--color-*` tokens: a purpose-built categorical scale, not a face of the
  semantic palette. Contract test in `IngredientTaxonomyTest.php` parses each `.sk-badge-<x> { body }`
  from `app.css` and asserts both `background-color:` and `color:` are present, plus all 22 unique.
- **The 22 badge hues are generated, not hand-authored.** `scripts/badge-palette.mjs` (node, no deps)
  solves every text colour for 6:1 against its own background, pulls chroma back into sRGB, reports
  contrast / gamut / Oklab ΔE. `node scripts/badge-palette.mjs --check` fails if `app.css` has
  drifted — the taxonomy test can't catch that, since it only sees that a rule exists. Change the
  inputs and regenerate; never hand-edit a single `oklch()` in `app.css`.
- **`.sk-badge-inert` is a 23rd rule that is not a category and is not generated** — the null-category
  fallback, emitted by `ingredients-index.blade.php:162` as
  `sk-badge-{{ $ingredient->category?->badgeVariant() ?? 'inert' }}`. Written in
  `--color-panel-strong` / `--color-ink-soft` **tokens**, deliberately, so it sits outside both the
  taxonomy test and `badge-palette.mjs --check` — **nothing catches its deletion.** It reads as dead
  CSS under a literal grep because the class is composed at runtime; it is not. 0 of 170 live
  ingredients have a null category, so it is unexercised but correct to keep.

## Decimal alignment

Full 40-site audit + consolidation plan (PROVISIONAL, owner said do not implement):
`docs/superpowers/plans/2026-09-04-decimal-alignment-consolidation.md`.

- `.sk-decimal-aligned` (`app.css:572`, top level in `@layer components`, so **global** — not scoped
  to `.sk-workbench`) sets `text-align: start` and
  `padding-inline-start: max(0.75rem, calc(50% - var(--sk-decimal-offset)))`, parking the separator
  on the **50% centre line**; the `max()` clamp stops long values pushing past the left edge. It
  needs `decimalAlignmentStyle(value)`
  (`resources/js/recipe-workbench/sections/formula-section.js:736`, emits
  `--sk-decimal-offset: <int-digits>ch`, bound via `:style`) plus
  `x-effect="syncFormattedInput($el, v, decimals)"` (rewrites to fixed decimals, **bailing out while
  focused** so it never fights typing) paired with `@blur="normalizeDecimalBlur($event)"`
  (`costing-section.js:617`). **`.numeric` (tabular-nums) is a hard prerequisite — without it the
  `ch` math breaks.** Used only on per-row workbench table inputs (`reaction-core`, `post-reaction`,
  `cosmetic-formula`); standalone settings fields use plain `numeric`; read-only values use
  `numeric … text-right` (`fatty-acid-profile`). **Nothing in `tests/` pins any of it.**
- **A decimal anchor is only needed when the fraction length varies — otherwise use `text-right`.**
  Where decimals are *fixed* (price, always 2 via `formatDecimal`), `text-right` aligns perfectly
  with zero new code. **Check the fraction length before reaching for the anchor.** Done 2026-09-04
  on the ingredient-index price input — one utility class, `.numeric` was already there.
- **Measured 2026-09-04: 36 of 40 call sites don't need the JS.** 30 pass a literal `format(x, N)`;
  4 use `additionWeightDecimals` (profile `addition` has **no magnitude term** — 3 for g/oz, 4 for
  kg/lb, constant down a column); 2 are costing inputs at fixed 2. **Only 4 genuinely vary:**
  `oilWeightDecimals` (reaction-core 127/130/153) — g: `>=1000 ? 1 : 2`, oz: `>=1 ? 2 : 3`; and
  `formatPackagingQuantity` (costing-tab 170) — integer → 0 decimals, else 3 (**3-char drift**, worst
  case). `oilUnit` is a **single user-selectable scalar for the whole formula** (`component.js:492`,
  `changeOilUnit`), NOT auto-promoted g→kg, so the unit is constant down a column — only the
  magnitude thresholds in `massDisplayDecimals` (`mass.js:58`) can vary the fraction length.
  **Root cause is the per-row decimal count, not the alignment technique:** make it uniform per
  column and the whole mechanism is deletable — but that changes displayed precision
  (`1200.0`→`1200.00`, `4`→`4.000`), so it's a product decision.
- **Padding-right that varies per value BREAKS decimal alignment.** Separator x =
  `right edge − padding-right − (fraction × 1ch)`, so a per-row computed padding moves the separator
  with the value. "Conditional" padding is only safe when the condition is not the number itself.
- **`--font-numeric` is monospace** (`ui-monospace, SFMono-Regular, Menlo…`, `soapkraft.css:4`) —
  `1ch` = one glyph advance exactly (≈8.4px at 14px), separator included, so `tabular-nums` is
  belt-and-braces. Makes `ch` arithmetic exact and predictable.
- **Tailwind utilities beat `.sk-input`'s padding without `!important`.** `.sk-input` is in
  `@layer components` (`app.css:213`); Tailwind emits `pr-*` into `@layer utilities`, registered
  after `components`. **Layer order beats specificity**, so `pr-6` overrides the
  `padding: 0.75rem 1rem` shorthand.
- `decimalAlignmentStyle` / `syncFormattedInput` are **methods inside `createFormulaSection()`**, not
  standalone exports, so "just import them" doesn't work — extract to a shared module first.
  `.sk-decimal-aligned` *is* reusable as-is (top level in `@layer components`).
- **All number formatting deliberately omits thousands grouping** — that is what makes a decimal
  anchor viable. PHP `NumberLocale::formatDecimal($v, n, $locale)` and JS `formatNumber(v, n,
  locale)` (`Intl.NumberFormat`, `useGrouping: false`) both give exactly n decimals, no separators,
  locale-aware `.`/`,`. Input side: `formatAdaptiveDecimal` (trailing zeros trimmed) and
  `formatDecimalInput` (0–12 decimals). Price unit is **kg** or **lb** only
  (`MassDisplaySystem::priceUnit()`), never g — integer parts run 1–5 digits.

## Content width — how wide is a card, actually

**The app caps at 1184px, not 1280.** `--container-app: 74rem` (`soapkraft.css:14`) surfaces as the
native Tailwind `max-w-app`. Introduced by `7eb53093` (2026-08-07), which replaced the scattered
`max-w-7xl` (1280) / `max-w-[90rem]` (1440) and deleted the production-bench compact (1024) variant.
The comment in the file says it plainly: *"on laptops (~1440–1512 CSS px) it never binds"*.
**Every container is on the token as of `9dbb8829` (2026-09-09).** The last six holdouts —
`layouts/public.blade.php:21,70`, `welcome.blade.php:44,129`,
`dashboard/recipe-workbench.blade.php:6`, `formula-bottom-action-bar.blade.php:4` — were
hardcoded `max-w-[1180px]`, 4px off the token; all now `max-w-app`. Guarded by
`PublicShellPagesTest` "bounds the public shell with the shared content width token",
which renders the homepage and asserts `max-w-app` present / `max-w-[1180px]` absent.

`max-w-app` works on the **public** pages too: `resources/css/public.css` imports
`shared/soapkraft.css` just like `app.css` does, and although it uses
`@import 'tailwindcss' source(none)` its explicit `@source` list covers
`layouts/public.blade.php` and `welcome.blade.php`. Confirmed in the compiled output of
both entries (`.max-w-app { max-width: var(--container-app); }`), not assumed. The token's
own definition is guarded by `ProductionBenchLayoutTest` against `shared/soapkraft.css`.

Derivation for any authenticated page (`layouts/app-shell.blade.php:39,45,132` +
`x-production-bench.page`), all from committed CSS:

| viewport W | available card width |
|---|---|
| W < 1024 (no grid, `px-6`) | `W − 15 − 48` → 768 = **705** |
| W ≥ 1024 (sidebar 272 + `lg:px-8`) | `min(W − 15 − 272 − 64, 1184)` = `min(W − 351, 1184)` |
| 1024 / 1280 / 1440 / 1512 | **673** / **929** / **1089** / **1161** |
| W ≥ 1535 | **1184** (the cap; never grows again) |

The 15px is a classic (non-overlay) scrollbar — matches the flake noted below. This model
reproduces four independent headless-Chrome measurements exactly, so trust it over a fresh probe.
Collapsing the sidebar only widens the column, so 272px is the conservative case.

**Consequence for sticky thresholds:** a `min-w-*` floor F sticks at `W ≥ F + 351`. Materials
(880) → 1231. Lot register (1024, raised 2026-09-08) → 1375. Both fit at the 1184 cap with
304 / 160px to spare, and both still stick at a 1440 laptop (card 1089).

**Do not invent a reference viewport.** 1280 was my own pick and it is now a *narrow* desktop —
below the range the app targets. Derive the number from the CSS instead of choosing one.

## Sticky table headers

**Two different designs; pick deliberately.** `sticky top-0` resolves against the *nearest scroll
container*, which is the whole ballgame.

- **Bounded panel** (header sticks inside a box): `max-h-[70dvh] overflow-auto`. Precedent
  `production-bench/production/batch-size-form.blade.php:56`. Prefer `dvh` over `vh`. Costs you the
  per-page selector — a capped box fixes the list at ~one screen whatever it says.
- **Page scrolls** (header sticks to the viewport): needed whenever a "rows per page" selector is
  supposed to decide the list length. Then the table must have **no scroll-container ancestor at
  all**. Any wrapper scrolling in X is a scroll container in *both* axes, and a wrapper that is
  exactly content-height never scrolls — the header pins to nothing, silently.

**The escape hatch for wide tables that need horizontal scroll:** container query on the card —
`@container overflow-clip sk-card` plus a wrapper `overflow-x-auto @min-[70rem]:overflow-x-visible`.
Set the threshold equal to the table's `min-w-*` floor, so the moment the table fits, overflow goes
`visible` and the header sticks to the viewport; below it you keep horizontal scroll and lose the
sticky header. Verified compiled output: `.@container { container-type: inline-size }`,
`.@min-\[70rem\]\:overflow-x-visible { @container (width >= 70rem) { overflow-x: visible } }`.

**`overflow-clip`, not `overflow-hidden`, on the card.** `hidden` creates a scrollport and kills
sticky; `clip` still clips to the rounded corners and does not. `container-type: inline-size`
(layout containment) also does **not** break sticky — measured in headless Chrome: wide card
`theadTop=0` after scrolling 700px, narrow card (overflow auto) `theadTop=-300`, i.e. falls back
exactly as designed.

**Measuring the width a table actually needs** (for choosing `min-w-*` / thresholds):

1. Dump the real markup: `Livewire::test(...)->html()` in a scratch test (needs
   `uses(RefreshDatabase::class)` — `tests/Pest.php` has it commented out globally).
2. **Get the CSS with `?direct`.** `curl -sk "https://koskalk.test:5173/resources/css/app.css"`
   returns a **Vite JS module** (`__vite__css = "…"`) — injecting that into `<style>` applies
   nothing, and the failure is silent: computed `font-size` reads 16px where `text-xs` should be
   12px, `size-2.5` measures 0. Append **`?direct`** and you get compiled CSS. **Do not use
   `public/build/assets/app-*.css`** — it is gitignored *and* stale, so it silently lacks any
   utility or container query added since the last build (this made every width report
   `overflowX=auto` and looked like a broken feature). After editing a Blade file, allow a moment
   for Vite to rescan before fetching, or you get the previous compile.
   Three traps here, all hit in one session:
   - **Dev-server CSS is not minified.** `grep -o "\.max-w-app{[^}]*}"` matches *nothing*;
     the rule is `.max-w-app {\n    max-width: …;\n  }`. Use `grep -n -A2 "max-w-app"`.
     Also, BSD `grep` does not support `\s` — `^\s*\.foo {` silently returns nothing.
   - **The dev bundle keeps utilities for classes you already deleted.** Tailwind's dev
     cache retains them, so seeing `.max-w-[1180px]` *next to* `.max-w-app` does **not**
     mean the source still has it. Confirm against the Blade source, not the bundle.
   - **`npm run build` is blocked in *the agent's* sandbox** — Vite's `loadEnv` reads `.env`
     and the broker denies it ("Sensitive content approval timed out"). That is a restriction
     on me, **not a property of the project**: the owner runs it fine. Don't retry; verify
     with `?direct` and then say plainly that the production build is unverified. Corollary:
     the dev bundle is not proof either — see the dev-cache trap above.
3. `table.style.minWidth='0'; table.style.width='min-content'` then read
   `getBoundingClientRect().width`. That is the narrowest the table goes before any cell wraps.
4. Pin the wrapper and let the table size itself (`width:auto`) to answer "does it fit floor N".

Read it back through `google-chrome --headless=new --dump-dom`, writing results into the DOM and
grepping them out — an inline `<script>` that appends `<p id="probe-result">RESULT …=NNN</p>`. Take
≥3 samples (see the 15px flake note). **Beware: `grep -o` also matches the string literal inside
your own script**, so use a distinctive prefix and a pattern that requires digits.

Measured 2026-09-08 with real CSS: lot register min-content **981px** with a bare status dot,
**1009px** once the status column carries a visible word — a 28px delta, far less than the ~74px you
would predict by adding up the glyphs, because the binding column is not always the one you changed.
Measure the delta; don't estimate it.

**Verifying sticky behaviour in that same harness** (append a tall spacer above and below so the
page scrolls, scroll to `tableTop + 120`, read `thead.getBoundingClientRect().top`):

| card width | wrapper overflowX | theadTop | sticky? |
|---|---|---|---|
| 900 / 1000 | `auto` | −120 | no |
| **1024** (the floor) | `visible` | **0** | **yes** |
| 1089 (1440 laptop) | `visible` | **0** | **yes** |
| 1184 (the cap) | `visible` | **0** | **yes** |

The flip is exactly at the floor, three samples identical. To check the sticky *column*, set
`wrapper.scrollLeft = 240` and confirm the first `th`/`td` stay at `left - wrapper.left = 0` —
it only has work to do while the wrapper scrolls, so probe it below the floor.

Layers: thead `z-20` > corner `<th>` `z-30` > sticky body `<td>` `z-10`. Filament dropdown panels are
`position: absolute; z-index: 20` and **not teleported** (`teleport => false`), so they lose to a
sticky `z-20` thead that comes later in the DOM — give their wrapper its own stacking context
(`relative z-30`). A sticky body `<td>` needs an **opaque** fill, not a translucent one: a `/40` row
tint shows the row scrolling underneath. Use `color-mix(in_oklab, ...)` against the panel colour.

## WCAG contrast from oklch tokens

oklch → OKLab → **linear** sRGB → relative luminance. **If your `oklch_to_rgb` already returns
linear, luminance is just `0.2126*r + 0.7152*g + 0.0722*b` — do NOT apply the sRGB transfer function
again.** That one mistake has bitten three times and roughly halves every ratio: 6.69 → 2.58,
10.33 → 4.01. Tell-tale symptom: *every* pair fails, including near-black on near-white. (A second
variant: feeding lightness as `98.5` instead of `0.985` reported a 4.11:1 fail as a 4.90:1 pass.)
Always assert one known anchor before trusting the table — `ink-soft` on `panel` = **6.690:1**
(`#5e5851` on `#fcfaf6`). Sanity-check tokens by printing them as hex
(`ink-soft` `#5e5851`, `ink-muted` `#6d6861`, `accent-strong` `#7e3a00`).

**A token that tints its own background is self-referential.** `--color-ink-muted` is used as
`bg-[var(--color-ink-muted)]/10 text-[var(--color-ink-muted)]`, so darkening the text also darkens
the surface — that pair, not the plain panel, is the binding constraint. Darkened 58.0% → 52.0%
(2026-09-08): panel 4.11→5.28, panel-muted 3.86→4.97, panel-strong 3.53→4.54, own `/10` tint
3.65→4.60, field 4.22→5.42.

## Livewire / Alpine / Filament

- **Filament actions hide markup from static contract tests.** The page renders
  `{{ $this->addStockAction }}`; Filament emits the `<button>` at runtime, so no `sk-btn` literal
  exists in the Blade source. Assert the action slot, not the rendered class.
- **Server-rendered Alpine `x-data` options self-refresh** — no `wire:key` or `replaceOptions()`
  needed. Livewire's `patchAttributes` re-sets `x-data` when the expression string changes; Alpine
  re-runs `directives()`, and `reconcileData` assigns every key (incl. `options`) onto the existing
  reactive object. Side effect: transient combobox state (`query`/`open`/`activeIndex`) clears.
  `replaceOptions()` and `x-effect` are for *client-side* option sources only. Alpine is bundled
  inside `vendor/livewire/livewire/dist/livewire.js` — no separate alpinejs package.

## Project design rules (CLAUDE.md)

`CLAUDE.md` (725 lines) and `AGENTS.md` (486 lines) exist at the repo root; no
`CONTRIBUTING.md` / `CODING_STANDARDS.md`. `CLAUDE.md` carries **"Design Principles"** (line 145 —
including *"Table-first UI over card-based design"* at line 148) and **"Frontend Accessibility
Standards"** (line 181). Read those before proposing UI changes; fix in the project's idiom.
