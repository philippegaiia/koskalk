# Production Bench Navigation Hierarchy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make parent → child relationships in the Production Bench navigation immediately obvious at a glance, surface that the bench has settings at all, and leave room for inventory and purchasing settings later — without changing any route and without editing the `active` / `subnavigation` props that the 27 Livewire views already pass.

**Architecture:** Replace the four hand-rolled, mutually inconsistent Blade navigation components with **one declarative navigation tree** in PHP plus **one recursive Blade partial** plus **one set of `sk-nav-*` component classes**. The tree is the single source of truth for structure; the partial is the single source of truth for markup; the CSS is the single source of truth for the visual tiers. The existing `active` / `subnavigation` prop contract is preserved verbatim, so no page view has to change.

**Tech Stack:** PHP 8.5, Laravel 13.26, Livewire 4 (with `wire:navigate`), Blade anonymous components, Tailwind CSS 4 (utility layer + a `@layer components` block of `sk-*` classes), Pest 4.7, Pint.

**Revision 2** — two owner decisions applied since the first draft:
1. The landing tab is relabelled **"Dashboard"** (was "Home").
2. **Production setup is promoted from a Production child to a top-level tab** and relabelled **"Settings"**, so users can see settings exist and so inventory/purchasing settings can be added later. This collapses the navigation from three levels to two.

**Corrections found during implementation** — three plan statements turned out to be wrong. They are corrected in place below and flagged here so the reasoning is not lost:

| # | Plan said | Reality | Fix applied |
| --- | --- | --- | --- |
| C1 | `production_bench.*` is **not** in `interface-translations.json`, so no translation-seed sync is required (§1 constraint, line 49) | **903 `production_bench` rows** are in the catalogue, and `InterfaceTranslationCatalogueTest` asserts **exact key parity** between `lang/en/production_bench.php` and the catalogue across all 6 locales | Phase 4 expanded to mirror every `lang/en` change into the catalogue |
| C2 | Change one line in `SettingsIndex::mount()`: `'calendar'` → `'working-calendar'` (§4, task 9) | **Unsafe.** `$section` is not only the subnavigation value — `settings-index.blade.php` also filters content with `in_array($section, ['all', 'calendar'], true)`. Renaming it hid the working-calendar section entirely (`ProductionBenchProductionSettingsTest::organizes production setup into focused submenu routes` failed) | Reverted. The **tree node** is keyed `calendar` instead, keeping the config in the same vocabulary as the views that drive it |
| C3 | The `calendar` key collides between Production and Settings, so rename to disambiguate (§2.1) | **No collision exists.** `resolve()` locates the level 1 node first, then looks up `subnavigation` **within that node's children only**, so the two `calendar` nodes are never in the same lookup scope. `locate()` walks the tree in declaration order and `production` is declared before `production-setup`, so `active="calendar"` deterministically resolves to Production's calendar | Kept both nodes keyed `calendar`; pinned by `ProductionBenchNavigationTest::resolves the calendar key to the production calendar, not the settings working calendar` |

---

## 1. Problem statement

### Current state

Two navigation tiers inside Production Bench, rendered by four components:

| File | Tier | Style |
| --- | --- | --- |
| `components/production-bench/navigation.blade.php` | primary | horizontal underline tabs, 8 items, `text-sm font-medium` |
| `components/production-bench/inventory-navigation.blade.php` | section | filled soft pill chips, 3 items |
| `components/production-bench/purchasing-navigation.blade.php` | section | filled soft pill chips, 5 items |
| `components/production-bench/production-settings-navigation.blade.php` | section | filled soft pill chips, 7 items |

`components/production-bench/page.blade.php` picks at most one section nav with an `if / elseif / elseif` chain.

### Why it reads flat

1. **Eight siblings, three of them secretly parents.** The primary row is `Home | Inventory | Production | Tasks | Flash planner | Calendar | Purchasing | Production setup`. `Inventory`, `Purchasing`, and `Production setup` each own a section nav; the other five do not. Nothing in the primary row distinguishes a parent from a leaf.
2. **Orphaned siblings.** `Tasks`, `Flash planner`, and `Calendar` are all `production-bench.production.*` routes but are rendered as peers of `Inventory` and `Purchasing`.
3. **"Home" is ambiguous; it becomes "Dashboard".** The app shell already has a global dashboard reached from the sidebar item labelled "Overview", and several pages carry a "Back to dashboard" link. A bare "Home" tab inside Production Bench does not say which home, so the tab is relabelled **"Dashboard"** (owner decision). Three things keep it unambiguous: the destination's `page_heading` and `<h1>` both already read "Production Bench" (`home.blade.php` line 4, `home-index.blade.php` line 4), the tab carries a scoped `aria-label` of "Production Bench dashboard", and the tab sits on its own behind a divider at the head of the row.
4. **Settings are undiscoverable and mis-scoped.** "Production setup" is the eighth tab, styled identically to every workflow tab, and its name claims it belongs to Production only. There is no visual affordance that settings exist, and no place to put inventory or purchasing settings when they arrive.
5. **The submenu has no visual tie to its parent.** The section row is a standalone chip row: no indent, no connector, no shared surface, no parent name.
6. **The submenu is conditional, so hierarchy appears and disappears.** It renders only for `inventory`, `purchasing`, and `production-setup`. On `Tasks`, `Flash planner`, and `Calendar` there is no second row at all, so the nav changes height between sections.
7. **Two unrelated "selected" idioms stacked on top of each other.** Tier 1 uses an accent underline; tier 2 uses a filled `accent-soft` pill. Different visual languages, not a descending system.
8. **No icons, no fixed leading slot.** Labels start wherever the previous label ends.
9. **Two naming collisions.** Translation keys are swapped relative to their meaning (`navigation.production` = "Production setup" while `navigation.production_workflow` = "Production"), and two different things are called "Calendar".
10. **A hard-coded English `aria-label`.** `purchasing-navigation.blade.php` uses `aria-label="Purchasing sections"` instead of `__()`, so screen-reader users on non-English locales get English.

### Constraints discovered (these shape the design)

- **`tests/Feature/ProductionBenchLayoutTest.php` pins a lot of this.** It asserts exact `aria-current="page"` counts, asserts the literal class strings `border-[var(--color-accent)] text-[var(--color-ink-strong)]` and `bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]`, forbids `overflow-x-auto` and `-mb-px` in `navigation.blade.php`, and scans all 27 Livewire views for `active="…"` / `subnavigation` props. This plan keeps the prop contract and updates only the assertions that encode old styling.
- **`ProductionBenchProductionSettingsTest.php:196` asserts `Lang::get('production_bench.navigation.production', [], 'en') === 'Production setup'`.** Relabelling the tab to "Settings" **requires updating this assertion.** Flagged as a required test edit, not an accident.
- **Labels come from `lang/en/production_bench.php`, but the catalogue mirrors them.** No `production_bench.php` exists in `de`, `es`, `fr`, `it`, `nl`, or `pt_BR`, **but `production_bench.*` does have 903 rows in `database/seeders/data/interface-translations.json`**, and `InterfaceTranslationCatalogueTest` asserts exact key parity between `lang/en/production_bench.php` and the catalogue with every locale non-blank. **Every `lang/en` change must be mirrored into the catalogue** — see correction **C1** above.
- **`database/seeders/data/interface-translations.json` is bytewise-sorted with 2-space indent.** Rewriting it with `json.dumps(indent=4)` inflates it from 1.21 MB to 1.42 MB. Use `indent=2` and verify a no-op round-trip is byte-identical before making changes.
- **Horizontal budget is `--container-app: 74rem` (~1184px).** Level 1 drops from 8 items to 5, leaving roughly 500px spare even before shortening "Home" to "Dashboard". Label length is not a constraint at this tier.
- **`SettingsIndex::mount()` derives `$section` from `request()->routeIs()`, and that one string is overloaded.** It is passed straight through as `subnavigation` **and** used to filter sections inside `settings-index.blade.php` (`in_array($section, ['all', 'calendar'], true)`). It must keep matching the tree's node keys — see correction **C2** above.
- **Only automated quality gate is Pint.** No PHPStan/Larastan, so unused variables and dead branches are caught by nothing.
- **House style for nav CSS is `sk-*` classes in `resources/css/app.css` `@layer components` with an `.is-active` state**, as established by `.sk-workbench-tabs` / `.sk-workbench-tab`. Follow it rather than inlining 200-character Tailwind strings.
- **Production Bench is the app's first multi-level nav.** No precedent to mirror, so classes are named generically (`.sk-nav-*`) and are reusable by Settings/Account later.

---

## 2. The navigation tree

Declared once, in `app/Support/ProductionBenchNavigation.php`.

```
home                                 → Dashboard                (level 1, leaf)
inventory                            → Inventory                (level 1, group)
├── overview                         → Overview                 (level 2)
├── stock                            → Stock                    (level 2)
└── requirements                     → Requirements             (level 2)
production                           → Production               (level 1, group)
├── runs                             → Production runs          (level 2)
├── tasks                            → Tasks                    (level 2)
├── flash                            → Flash planner            (level 2)
└── calendar                         → Production calendar      (level 2)
purchasing                           → Purchasing               (level 1, group)
├── suppliers                        → Suppliers                (level 2)
├── listings                         → Supplier listings        (level 2)
├── quotations                       → Quotation requests       (level 2)
├── orders                           → Purchase orders          (level 2)
└── receipts                         → Receipts                 (level 2)
production-setup                     → Settings                 (level 1, group, behind a rule)
├── ── Production ──                                            (level 2 sub-heading)
│   ├── numbering                    → Numbering                (level 2)
│   ├── presets                      → Batch sizes              (level 2)
│   ├── task-types                   → Task types               (level 2)
│   ├── task-sets                    → Task sets                (level 2)
│   └── calendar                     → Working calendar         (level 2)
└── ── Resources ──                                             (level 2 sub-heading)
    ├── departments                  → Departments              (level 2)
    └── employees                    → Employees                (level 2)
```

### 2.1 Why the keys are what they are

**Node keys match the `active` / `subnavigation` values the views already pass**, so the 27 views need zero edits:

| Existing value passed by views | Node it resolves to |
| --- | --- |
| `home` | `home` |
| `inventory` | `inventory` (group) |
| `overview` / `stock` / `requirements` | children of `inventory` |
| `production` | `production` (group) |
| `tasks`, `flash`, `calendar` | children of `production` |
| `production-setup` | `production-setup` (group, **now level 1**) |
| `numbering`, `presets`, `departments`, `employees`, `task-types`, `task-sets` | children of `production-setup` |
| `purchasing` | `purchasing` (group) |
| `suppliers`, `listings`, `quotations`, `orders`, `receipts` | children of `purchasing` |

Three disambiguations:

- **`runs`** is a new key for the "Production runs" child. `active="production"` (passed by `production-index`, `production-create`, `production-detail`, `stock-preparation`) resolves to the **group**, whose default child is `runs`.
- **The node key `production-setup` is deliberately kept** even though the tab now reads "Settings". Renaming it would mean editing six Livewire views and the layout test's view scanner, for a string no user ever sees. The label lives in `lang/`, so the rename is cosmetic-only. Noted in §6 as intentional.
- **`calendar` is genuinely ambiguous and is resolved by renaming.** Two nodes need it: Production's "Production calendar" and Settings' "Working calendar". `SettingsIndex::mount()` sets `$section = 'calendar'` for `production-bench.production.settings.calendar`, and `settings-index.blade.php` passes that straight through as `subnavigation`. Left alone, a full-tree lookup of `active="calendar"` would match whichever node is declared first. **Fix: change one line in `SettingsIndex::mount()` to emit `'working-calendar'`**, and name the node `working-calendar`. `$section` is only ever compared against `'all'` in the view (`settings-index.blade.php` lines 28 and 94), so the rename is behaviour-neutral there. **This is the only non-navigation, non-test source file the plan touches.**

  *Fallback if that one line is considered out of scope:* make `resolve()` deterministic (declaration order, shallowest first) and pin it with a unit test asserting `active="calendar"` resolves to the Production calendar. Documented in task 2. Both fixes are cheap; the rename is preferred because it makes the config self-documenting.

**Level-1 groups are also links.** `inventory` → `production-bench.inventory`, `production` → `production-bench.production.index`, `purchasing` → `production-bench.purchasing.suppliers`, `production-setup` → `production-bench.production.settings`. The group's landing page is therefore duplicated by its first child (exactly as `Inventory` / `Overview` is today). That duplication already exists and is intentional: it keeps the route contract and the `aria-current` assertions stable, and gives each group a clickable target.

### 2.2 Settings sub-headings

The seven settings pages sit in one rail under two sub-headings — **Production** and **Resources**. This costs nothing today and means an "Inventory" or "Purchasing" group is a one-line config addition.

- Grouping is expressed by an optional `group` key on each child node. The partial renders an eyebrow sub-heading whenever the sibling set has more than one distinct group, and renders a flat row when it has one — so Inventory and Purchasing keep their plain single row, and only Settings grows headings.
- Sub-headings live **inside** the existing level-2 rail. They are not a third indent level: the navigation has exactly two visual depths.

---

## 3. Visual system

Five signals fire together. No single signal is load-bearing, so the hierarchy survives translation, long labels, and colour-blindness.

### 3.1 Indentation and containment (strongest signal)

| Level | Container |
| --- | --- |
| 1 | no indent, `border-b border-[var(--color-line)]` |
| 2 | `ml-4 border-l border-[var(--color-line)] pl-5`, inside `rounded-xl bg-[var(--color-panel)] ring-1 ring-[var(--color-line)]` |

The rail makes the child row read as a drawer belonging to the parent above it, not as an independent toolbar.

### 3.2 Typography weight and size

| Level | Size | Weight | Tracking | Inactive colour |
| --- | --- | --- | --- | --- |
| 1 | `0.9375rem` (15px) | `600` | `0.005em` | `--color-ink-soft` → `--color-ink-strong` active |
| 2 | `0.875rem` (14px) | `400` | normal | `--color-ink-soft` → `--color-ink-strong` active |
| sub-heading | `0.6875rem` (11px) | `700` | `0.08em` | `--color-ink-soft`, muted |

The sub-heading reuses the existing `.sk-eyebrow` token from `DESIGN.md` (11px / 700 / 0.08em) already used in `app-shell.blade.php` and `settings-index.blade.php`.

A parent is never rendered at a child's weight. This is the cue that survives when colour and indentation are both unavailable.

### 3.3 Icon alignment

Every item at every level uses the identical leading slot: `<svg class="sk-nav-icon size-4 shrink-0">` followed by `gap-2`. Consequences:

- Icons and labels form two clean vertical columns per level.
- Level 1 gets stroke icons (`stroke-width: 1.7`, 24×24 viewBox, `stroke="currentColor"`).
- Level 2 gets stroke icons at `stroke-width: 1.6`.

Icons needed (inline SVG, no new dependency): house, boxes, factory, list-checks, bolt, calendar-days, sliders, cart, tag, truck, receipt, users, wrench.

### 3.4 Spacing, grouping, and the ruled-off Settings tab

- Level 1: `gap-x-1` between items. A `border-l border-[var(--color-line)]` divider with `ml-2 pl-2` after **Dashboard**, separating the landing page from the workflow sections.
- **Settings is ruled off with a `border-l` but keeps its place in the reading order.** It is a utility destination rather than a workflow step, and the rule is enough to say so. **O3 in §9 removes the original `ml-auto`: the owner judged the trailing edge worse than the natural position.**
- Level 2: `gap-1` between chips, `flex-wrap` so long translations wrap instead of overflowing.
- **Clusters stack, one per line, and each group label takes a line of its own.** Two clusters sharing a row read as a single run of chips separated only by a gap; stacking puts the boundary in position instead. The label's `flex-basis: 100%` is what forces the wrap, and it also keeps every stacked cluster's items on the same left edge rather than offset by the length of its heading.
- Vertical rhythm: level 1 → level 2 `mt-3`; `row-gap-2` between stacked clusters.

### 3.5 Restrained tone

One accent only. Selected-state intensity **descends** with depth, turning today's two unrelated idioms into one system:

| Level | Active treatment |
| --- | --- |
| 1 | `border-b-2 border-[var(--color-accent)] text-[var(--color-ink-strong)]` (underline — preserved from today) |
| 2 | `bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]` + `font-medium` (pill — preserved from today) |

Inactive hover on level 2: `hover:bg-[var(--color-panel-strong)] hover:text-[var(--color-ink-strong)]`.

This keeps the Soapkraft north star — "tonal layering first, restrained colour, dense and calm". No new palette entries; every colour is an existing token.

### 3.6 Accessibility

- **Exactly one `aria-current="page"` per page**, on the deepest active node. Ancestors get `aria-current="true"` and the `.is-branch` class for styling. **C7 in §8 resolves this bullet's original `data-nav-branch` attribute in favour of the class.**
  - *This is a deliberate change.* Today the page emits two `aria-current="page"` (parent + child) and the layout test asserts it. `aria-current="page"` means "the current item **within a set**"; marking nested ancestors as the current page is ambiguous for screen-reader users. Called out here because it requires updating `ProductionBenchLayoutTest`.
- Every `<nav>` gets a specific, **translated** `aria-label` (e.g. `__('production_bench.navigation.settings_sections')`). This also fixes the hard-coded English `aria-label="Purchasing sections"` noted in §1.
- Sub-headings are real headings (`<h3>` inside the rail, or `role="presentation"` if they are purely visual) and are associated with their chip group via `aria-labelledby` on the wrapping `<div role="group">`.
- Level-1 items keep `min-h-11` (44px). Level 2 uses `min-h-9` (36px) — above the WCAG 2.5.8 minimum of 24px — to keep two stacked rows from consuming the page.
- `focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]` is preserved on every item at every level.

### 3.7 Responsive / overflow

- Level 1 drops from 8 items to 5, fitting comfortably in 74rem even with a 20-character first label. **`overflow-x-auto` stays forbidden** on `navigation.blade.php` — the test must keep passing.
- Level 2 uses `flex-wrap` and may grow to two rows on narrow viewports.
- Below `sm`, halve the indent (`ml-2 pl-3`) and drop Settings' leading rule so it wraps without a stray vertical stroke at the start of a line.

---

## 4. File structure

### New

- **`app/Support/ProductionBenchNavigation.php`** — the tree plus resolution helpers:
  - `tree(): array` — the structure in §2. Each node carries `key`, `route`, `label` (a translation key), `icon` (a name), optional `group`, and `children`.
  - `resolve(?string $active, ?string $subnavigation): array` — returns `['path' => [keys from root to leaf], 'rows' => [level => [nodes to render]]]`.
    - Resolution rules, in order: (1) if `$active` is null, fall back to `request()->routeIs()` matching exactly as today; (2) locate `$active` in the tree, searching in declaration order and returning the shallowest first match; (3) if the located node is a group and `$subnavigation` is null, descend to its **default child** (its first child); (4) if `$subnavigation` is given, look for it **only among the children of the located node**; (5) if the located node is a leaf, ignore `$subnavigation`.
    - Rows to render: level 1 always; level 2 = children of the active level-1 node, carrying their `group` for sub-heading rendering.
  - `isActive(array $path, string $key): bool` and `activeLevel(array $path, string $key): ?int` for the partial.

- **`resources/views/components/production-bench/navigation-items.blade.php`** — the single recursive partial. `@props(['nodes', 'level', 'path'])`. Renders one `.sk-nav-row[data-level]` containing the optional group sub-headings and one `<a class="sk-nav-item">` per node, with `data-level`, `.is-active`, `.is-branch`, `aria-current`, and the icon slot. Level 2 is wrapped in the recessed rail + connector from §3.1.

- **`resources/views/components/production-bench/navigation-icon.blade.php`** — `@props(['name', 'level' => 1])`. A `match` of 13 inline stroke SVGs at `size-4`. Keeps path data in Blade (its natural home) and structure in PHP.

### Modified

- **`resources/views/components/production-bench/navigation.blade.php`** — reduced to a thin wrapper: resolve the tree, render level 1, delegate level 2 to `navigation-items`.
- **`resources/views/components/production-bench/page.blade.php`** — delete the `if / elseif / elseif` chain; it now only renders `<x-production-bench.navigation :active="$activeNavigation" :subnavigation="$activeSubnavigation" />`. **Keep the `purchasing` / `inventory` / `productionSetup` / `inventorySection` boolean props** (some views may still pass them) and keep `space-y-8` (asserted by test).
- **`app/Livewire/ProductionBench/Production/SettingsIndex.php`** — **unchanged.** The plan originally called for renaming `'calendar'` → `'working-calendar'` here, but task 1b proved that unsafe: the same `$section` string filters content in the view. See correction **C2**.
- **`resources/css/app.css`** — add the `.sk-nav-*` block to `@layer components`, following the `.sk-workbench-tab` precedent (component class + `.is-active` + `[data-level]` modifiers). Keep a dormant `[data-level='3']` selector so a future third tier is a three-line change.
- **`lang/en/production_bench.php`** — see §4.1.
- **`tests/Feature/ProductionBenchLayoutTest.php`** and **`tests/Feature/ProductionBenchProductionSettingsTest.php`** — see §5 Phase 5.

### Deleted

- **`resources/views/components/production-bench/inventory-navigation.blade.php`**
- **`resources/views/components/production-bench/purchasing-navigation.blade.php`**
- **`resources/views/components/production-bench/production-settings-navigation.blade.php`**

Safe to delete: `page.blade.php` is the **only** file that references any of them (verified by grep). Their route lists and `match` blocks become config entries.

### 4.1 Translation keys

| Key | Action | Value |
| --- | --- | --- |
| `navigation.home` | **change value** | `Dashboard` |
| `navigation.home_aria` | **add** | `Production Bench dashboard` (scoped `aria-label` only — the visible text stays "Dashboard") |
| `navigation.settings` | **add** | `Settings` |
| `navigation.production` | **remove** (was `Production setup`) | — |
| `navigation.production_workflow` | keep | `Production` |
| `navigation.production_runs` | **add** | `Production runs` |
| `navigation.production_calendar` | **add** | `Production calendar` |
| `settings.numbering` … `settings.working_calendar` | keep, retarget | unchanged values |
| `navigation.settings_group_production` | **add** | `Production` |
| `navigation.settings_group_resources` | **add** | `Resources` |
| `navigation.settings_sections` | **add** | `Settings sections` (aria-label) |

Every other label stays byte-identical — several are pinned by `assertSee` in `ProductionBenchSupplierPagesTest` and `ProductionBenchProcurementPagesTest`.

Removing `navigation.production` and changing `navigation.home` are the two changes that break `ProductionBenchProductionSettingsTest.php:196`-style assertions; both are listed as explicit test tasks.

Note that `settings-index.blade.php:4` renders `<p class="sk-eyebrow">{{ __('production_bench.navigation.production') }}</p>`. That call site must switch to `navigation.settings`, and the page eyebrow then reads "Settings" — which is more accurate than "Production setup" now that the tab hosts Resources and will host Inventory and Purchasing groups.

---

## 5. Tasks

### Phase 1 — Model (no visual change yet)

- [ ] 1a. Create `app/Support/ProductionBenchNavigation.php` with `tree()` and `resolve()`. Include every route name from `routes/web.php` lines 155–197.
- [ ] 1b. Grep the test suite for the literal string `'calendar'` used as a settings section value, so the `SettingsIndex::mount()` rename is known to be safe.
- [ ] 2. Add a Pest test (`tests/Unit/ProductionBenchNavigationTest.php`) asserting, for every existing `active` / `subnavigation` pair in `ProductionBenchLayoutTest`'s data provider: the resolved path, the rows to render, and the deepest key. **Write this before any view changes**, so it locks the model against the old contract. Include explicit cases for:
  - `['calendar', null]` resolves to Production's **Production calendar**, not Settings' **Working calendar** — this is the collision guard from §2.1.
  - `['production-setup', 'calendar']` resolves to Settings → Working calendar.
  - `['production-setup', null]` defaults to Settings → Numbering.
  - `['tasks', null]`, `['flash', null]` resolve through the `production` group.
- [ ] 3. Assert that level 1 has exactly five nodes and that `production-setup` is one of them, so the promotion cannot silently regress.

### Phase 2 — Markup

- [ ] 4. Create `navigation-icon.blade.php` with the 13 inline SVGs.
- [ ] 5. Create `navigation-items.blade.php`: one row per level, `data-level` on rows and items, `aria-current` per §3.6, icon slot on every item, optional group sub-headings with `role="group"` + `aria-labelledby`, `wire:navigate` preserved on every link.
- [ ] 5b. Give the Dashboard tab `aria-label="{{ __('production_bench.navigation.home_aria') }}"` so screen-reader users hear "Production Bench dashboard" rather than a bare "Dashboard" that competes with the global one.
- [ ] 6. Rewrite `navigation.blade.php` as the resolver + level-1 wrapper, with `ml-auto` and a divider on Settings. Keep it free of `overflow-x-auto` and `-mb-px`.
- [ ] 7. Simplify `page.blade.php` — drop the `if/elseif` chain, keep all props and `space-y-8`.
- [ ] 8. Delete the three section-navigation components.
- [x] 9. ~~Change the one line in `SettingsIndex::mount()` (`'calendar'` → `'working-calendar'`).~~ **Cancelled by task 1b — see correction C2.** `$section` doubles as the content filter in `settings-index.blade.php`, so it must keep matching the tree's node keys. Key the Settings node `calendar` in `ProductionBenchNavigation::tree()` instead.

### Phase 3 — Styling

- [ ] 10. Add the `.sk-nav-*` block to `resources/css/app.css` `@layer components`: `.sk-nav`, `.sk-nav-row[data-level]`, `.sk-nav-item[data-level]`, `.sk-nav-item.is-active`, `.sk-nav-item.is-branch`, `.sk-nav-icon`, `.sk-nav-rail`, `.sk-nav-group-label`. Use only existing tokens from `resources/css/shared/soapkraft.css`.
- [ ] 11. Encode the tiers as `data-level` attribute selectors rather than parallel class sets, and leave a dormant `[data-level='3']` rule.
- [ ] 12. Reduce the level-2 indent and drop `ml-auto` below the `sm` breakpoint.
- [ ] 13. Verify contrast of `--color-ink-soft` on `--color-panel` for level-2 inactive items, and note that the `.dark` variant may need `--color-ink-soft` overrides later (no dark tokens exist for the nav today).

### Phase 4 — Copy

- [ ] 14. Apply the §4.1 key table to `lang/en/production_bench.php`, including the new `navigation.home_aria`.
- [ ] 15. Update the `sk-eyebrow` call site at `settings-index.blade.php:4` from `navigation.production` to `navigation.settings`.

### Phase 5 — Tests and verification

- [x] 16. Update `ProductionBenchProductionSettingsTest.php:196`, which asserts `navigation.production === 'Production setup'`, to assert `navigation.settings === 'Settings'`.
- [x] 17. Update `ProductionBenchLayoutTest::keeps the inventory overview navigation visibly selected across live updates`: `aria-current="page"` count 2 → 1; replace the two literal class assertions with the new `.sk-nav-item.is-active` selectors.
- [x] 18. Update the `renders explicit production bench navigation state…` data provider for the new `aria-current` semantics and the promoted Settings tab:
  - `['home', null]` → 1 row, count 1
  - `['production', null]` → path `production → runs`, 2 rows
  - `['tasks', null]` → path `production → tasks`
  - `['calendar', null]` → path `production → calendar`
  - `['purchasing', 'quotations']` → only `production-bench.purchasing.quotations` carries `aria-current="page"` (previously the parent's `.suppliers` href did too)
  - `['production-setup', 'numbering']` → **2 rows, not 3** (Settings is now level 1)
  - `['inventory', 'stock']` → count 1, not 2
- [x] 19. Add assertions that the level-2 row renders for **all five** level-1 destinations — previously only three sections had one, and Tasks / Flash planner / Calendar had none. This is the core "no more disappearing hierarchy" guarantee. → `renders a nested second row for every branch destination`, over all seven branch keys.
- [x] 20. Add an assertion that Settings is the last level-1 item and carries the layout flag that sets it apart. → **`keeps the dashboard first and sets settings apart at the end of the top level row`**, asserting `data-nav-end`. The test originally asserted `data-nav-end` "resolved into CSS `margin-inline-start: auto` + `border-left`"; **§9 O3 removed the auto margin, so only the rule remains.** Renamed accordingly — the name should describe the behaviour, not the mechanism that happened to implement it on the day.
- [x] 21. Keep the existing assertions that `navigation.blade.php` contains no `overflow-x-auto` and no `-mb-px`. The `aria-current="page"` half of that assertion moved to `navigation-items.blade.php` and was retargeted.
- [x] 22. Run the full suite: `php artisan test --compact`. ~~(Expect the one pre-existing failure in `UserIngredientAuthoringTest`.)~~ **The pre-existing failure set turned out to be larger than the plan claimed** — see "Verification results" below.
- [x] 23. Run `vendor/bin/pint --dirty --format agent`. **Passed.**
- [x] 24. Run `npm run build` and confirm the new `sk-nav-*` classes are emitted. **Passed** — all 8 classes (`.sk-nav`, `-row`, `-cluster`, `-item`, `-icon`, `-label`, `-rail`, `-group-label`) present in `public/build/assets/app-*.cs`, 24 rules.
- [x] 25. ~~Manually walk every level-2 route...~~ **Automated instead**, as `ProductionBenchLayoutTest::marks exactly one navigation entry current on every production bench page` — a 21-case route walk over every reachable production-bench route. This also closes a real coverage gap: task 18's tests pass `active` explicitly, so the request-route fallback (`detectActiveKey()` / `detectSubnavigationKey()`) was previously unpinned.

### Verification results

Full suite: **2606 passed, 14 failed, 25 skipped.** Every failure is pre-existing and outside this change's surface:

| Count | Test | Cause |
| --- | --- | --- |
| 4 | `InterfaceTranslationCatalogueTest` — various | `InvalidInterfaceTranslationCatalogue: The catalogue key [ingredients.validation.translation_write_intent_invalid] is not application-owned` |
| 1 | `ProductionBenchProductionSettingsTest` — various | same catalogue exception, surfacing inside a Production Bench HTTP test |
| 1 | `InterfaceTranslationCatalogueTest > it recovers the…` | `translations:catalogue:import --mode=authoritative` exits 1 — same root cause, reached through the command |
| 3 | `StartIngredientIntakeResearchTest` | `Failed asserting that Enum #75028 (Intake, 'intake') is identical to 'intake'` — enum-vs-string comparison |
| 1 each | `IngredientEnrichmentTrustDimensionsTest`, `MediaStorageTest`, `RecipeWorkbenchDesignPolishTest`, `SearchComboboxAdoptionTest`, `WorkflowActionConsistencyTest` | Recipes / media / ingredients / search domains |

**Proof that the 6 catalogue failures predate this work:** `lang/en/ingredients.php` nests those keys under `editor.validation.*`, but the catalogue still holds 7 rows at `ingredients` / `validation.translation_write_intent_*`. Those same 7 rows are present **identically at HEAD** (verified from a pristine `git worktree` at `9c4b18f6`). A key-set diff of HEAD vs. the working tree shows a net change of **+4 / −1 rows, all in `production_bench`** — nothing outside it.

**Proof for the other 8:** no file those tests read appears in `git status`; the domains (ingredients, media, recipes, search, workflow buttons) are disjoint from the navigation change.

### Follow-up — **done** (owner approved, 2026-08-29)

The 7 stale `ingredients` / `validation.translation_write_intent_*` catalogue rows were deleted. The
correct `editor.validation.*` rows already existed alongside them, so this was a pure deletion.

Net catalogue change vs. HEAD is now **+4 / −8**: `navigation.home_aria`, `navigation.production_runs`,
`navigation.settings`, `navigation.settings_group_resources` added; `navigation.production` renamed away
and the 7 stale rows removed.

**Why the import could not have run before this.** `InterfaceTranslationCatalogue::read()` validates the
whole catalogue and throws before any DB write, so `translations:catalogue:import` was **impossible**
while the stale rows existed — it was not merely unwise. Running `translations:sync` first would have
inserted 58 empty rows and then the import would have failed, leaving blank translations. Note that
`translations:sync` alone *was* runnable (it reads `lang/en`, not the catalogue), but it only creates
empty rows and so would not have landed the relabel.

Sequence run, with measured blast radius beforehand:

| Step | Result |
| --- | --- |
| dry comparison | 58 rows to create, **18** to overwrite (17 `production_bench`, 1 `packaging`), 1 DB-only row (untouched) |
| `translations:sync` | 51 created, 2681 already present, 0 pruned |
| `translations:catalogue:import --mode=authoritative` | 0 created, **69 updated**, 2663 unchanged, 0 production values preserved |

69 = the 51 empty rows `sync` had just created, plus the 18 predicted overwrites.

Verified after import — `de` now reads `Dashboard` (was `Start`), `Einstellungen`, `Produktionen`,
`Ressourcen`; `fr`, `nl`, `es`, `it`, `pt_BR` all resolve too.

---

## 6. Risks and open decisions

| Risk | Mitigation |
| --- | --- |
| Changing `aria-current` semantics breaks the existing count assertions | Intentional and documented in §3.6. Tasks 17/18 update them. If the owner prefers zero test churn, keep `aria-current="page"` on every ancestor — superseded semantics, no test edits. **Needs an owner decision.** |
| Relabelling to "Settings" and deleting `navigation.production` breaks `ProductionBenchProductionSettingsTest.php:196` | Intentional. Task 16 updates it. The alternative is keeping a dead `navigation.production` key solely to satisfy an assertion. |
| **"Dashboard" is also the name of the app-wide dashboard** (sidebar item `navigation.items.overview` → `route('dashboard')`, plus "Back to dashboard" links on ingredient and packaging pages) | Accepted at the owner's request. Mitigated three ways rather than avoided: the destination's page heading and `<h1>` already read "Production Bench"; the tab gets a scoped `aria-label` of "Production Bench dashboard" (task 5b); and the tab is isolated behind a divider at the head of the row. **If it still confuses in use**, the cheapest fix is `navigation.home` = `Bench dashboard` — one string, no structural change. |
| `"Settings"` collides with the global sidebar's own "Settings" item (`navigation.items.settings` → `route('settings')`) | They live on different surfaces — one in the persistent left sidebar, one in the page-level bench nav — and the bench one is scoped under the Production Bench row that starts at Dashboard. If it proves confusing in use, fall back to **"Bench settings"**; the change is one translation string. **Owner can override cheaply.** |
| The node key `production-setup` now reads oddly for a tab labelled "Settings" | Deliberate. The key is invisible to users and renaming it means editing six Livewire views plus the test's view scanner. Can be renamed later in one place if desired. |
| Group landing page duplicates its first child (`Inventory` / `Overview`, `Purchasing` / `Suppliers`, `Production` / `Production runs`) | Exists today and preserved deliberately. Making groups non-links would break the `href`-based test assertions and remove an expected click target. |
| `settings-index.blade.php` passes `null` when `$section === 'all'`, and passes values like `departments` that are settings children | Resolution rules 3 and 4 in §4 handle both: `null` falls back to the default child (Numbering); a child key is matched within the active group. |
| Unused parameters / dead branches are caught by nothing (no PHPStan) | Keep `resolve()` small and covered by the unit test in task 2. |

### Rejected alternatives

- **Vertical left rail for Production Bench.** Clearer for deep trees, but the app shell already owns a left sidebar and Production Bench is one item in it. Two sidebars would compete. Rejected.
- **Collapsing non-active groups.** Saves space but hides sibling destinations; the whole complaint is that the hierarchy is invisible. Rejected.
- **Keeping Production setup nested under Production.** Solves the styling problem but leaves settings undiscoverable and gives inventory/purchasing settings nowhere to live — the owner explicitly rejected this.
- **A flat settings row with no sub-headings.** Simplest today, but adding inventory and purchasing settings later grows it past a dozen undifferentiated chips, which recreates the exact flatness being fixed. Rejected in favour of grouped sub-headings.
- **Dropdown / hover menus.** The nav is `wire:navigate` links, not tabs; dropdowns add keyboard and touch complexity for no gain at this depth. Rejected.
- **Colour-coding each group.** Violates the "restrained colour, one accent" north star and fails for colour-blind users. Rejected in favour of weight + indent + containment.
- **Pixel-perfect alignment of child labels under their parent's label via JS measurement.** Real indent communicates nesting more directly and needs no JS. Rejected as complexity.

---

## 7. Definition of done

- Every Production Bench page shows its full ancestry: the level-1 group and its level-2 children.
- Level 1 has five items, ending with a Settings tab that is set apart by a rule rather than by position.
- A first-time viewer can name the parent of any child without hovering or clicking, and can see that the bench has settings.
- Adding an "Inventory" or "Purchasing" group to Settings is a config-only change with no view or CSS edits.
- The five level-1 items are visually identical to each other in weight, size, and icon treatment; so are all level-2 items. No item is styled by hand.
- No Livewire view under `resources/views/livewire/production-bench/` was modified.
- No route was added, removed, or renamed. The only non-navigation source change is one line in `SettingsIndex::mount()`.
- Full suite green except the one pre-existing ingredient-authoring failure; Pint clean.

---

## 8. Corrections from review (2026-08-29)

An external review of the committed implementation surfaced two defects and three
inaccuracies in this plan. All five are recorded here; the two defects are fixed.

### C4 — §2.1 is wrong about `production-setup` (was a live defect)

> Level-1 groups are also links … The group's landing page is therefore duplicated
> by its first child (exactly as `Inventory` / `Overview` is today).

True for three of the four groups, false for the fourth:

| Group | Group route | First child route | Duplicated? |
| --- | --- | --- | --- |
| `inventory` | `production-bench.inventory` | `production-bench.inventory` | yes |
| `production` | `production-bench.production.index` | `production-bench.production.index` | yes |
| `purchasing` | `production-bench.purchasing.suppliers` | `production-bench.purchasing.suppliers` | yes |
| `production-setup` | `production-bench.production.settings` | `production-bench.production.settings.numbering` | **no** |

The unconditional `?? $node['children'][0]` fallback in `resolve()` therefore
announced `/settings/numbering` as the current page while the browser sat on
`/settings`, which renders **all seven** sections. Fixed by making the fallback
conditional on `$node['route'] === $node['children'][0]['route']`, which is
exactly the duplication the plan describes.

The default cannot simply be deleted: `detectSubnavigationKey()` resolves via
`routeIs()`, which sees `livewire.update` rather than the page route during a
Livewire update request, so the default is what keeps a child highlighted across
live updates for the three groups that do share a route.

### C5 — Settings was not right-aligned (was a live defect)

`margin-inline-start: auto` on `[data-nav-end]` never did anything. The item sits
in `.sk-nav-cluster`, a content-sized flex item inside `.sk-nav-row`, so the row's
free space was outside the cluster where an auto margin cannot reach it. Fixed
with `.sk-nav-row[data-level='1'] > .sk-nav-cluster { flex: 1 1 auto; }`, scoped
to level 1 so the two Settings sub-groups keep their natural gap.

**Superseded by O3 in §9.** The fix worked and Settings did reach the trailing
edge; having seen it, the owner preferred the original inline position. The
`flex: 1 1 auto` rule and the auto margin are both gone. The diagnosis above is
still correct and still worth keeping — it is the reason the flag now has to be
honest about what it does, and it is the trap to avoid if a trailing-edge pin is
ever asked for again.

### C6 — §3.1 understates the cost of a third tier

> Keep a dormant `[data-level='3']` selector so a future third tier is a three-line
> change.

Not configuration-only: `ProductionBenchNavigation::rows()` emits levels 1 and 2
by index and `navigation.blade.php` renders them by index, so both need a third
entry. The rule is kept as instructed, but the CSS comment now says so.

### C7 — §3.6 contradicts itself (resolved in favour of the class)

§3.6 named a `data-nav-branch` attribute on ancestors; §5 and task 10 both name an
`.is-branch` class. The implementation emits the class, and `data-nav-branch`
appears nowhere else in the codebase. Resolved by keeping the class and correcting
§3.6.

The apparent inconsistency with `data-nav-end` / `data-nav-divider` is not one. The
two families differ in where the flag comes from:

| Flag | Source | Mechanism |
| --- | --- | --- |
| `data-nav-end`, `data-nav-divider` | declarative, carried on the node in `tree()` | attribute |
| `.is-active`, `.is-branch` | computed per render from the resolved `$path` | class |

`.is-branch` also shares its CSS rule with `.is-active` — both mean "this item is on
the active path" — which an attribute selector could not do without duplicating the
declaration or abandoning the grouping:

```css
.sk-nav-item[data-level='1'].is-active,
.sk-nav-item[data-level='1'].is-branch {
    border-bottom-color: var(--color-accent);
    color: var(--color-ink-strong);
}
```

---

## 9. Owner overrides after seeing it rendered (2026-08-29)

Three changes, all made by looking at the built UI rather than by reading the
plan. Each one reverts or amends a decision recorded above; where they conflict,
**this section wins.**

### O1 — `.sk-nav` no longer has a bottom border

§3.2 specified `border-b` on the container, carried over from the original
markup. Rendered, it sat directly under whichever row renders last — the
level-1 tabs' 2px underline when no second row shows, the level-2 rail's own
border when one does — and read as a double line in both cases.

Removed. The page wrapper's `space-y-6` is what separates the navigation from
the content; the accent underline is what marks the active tab, and it needs no
baseline under it.

Pinned by `ProductionBenchLayoutTest::does not draw a rule under the whole
navigation`, asserted against `.sk-nav { … }` in `app.css`.

### O2 — nav clusters stack vertically

§3.4 let clusters share a row. On a normal display they did, and the two
Settings sub-groups became one run of chips separated only by a gap — the
grouping the sub-headings were added to make visible was the thing that
disappeared.

`.sk-nav-row` is now `flex-direction: column`, so each cluster owns a line. A
cluster's group label takes a line of its own via `flex: 1 0 100%` on
`.sk-nav-cluster > .sk-nav-group-label`: too wide to share a line, so the items
wrap underneath it.

That second half is not cosmetic. With the label left inline, a stacked
cluster's items start at whatever offset its heading's translation happens to
be, so "Production workflow" and "Resources" would indent their chips
differently. Forcing the wrap keeps every cluster's items on one left edge.

Note the label trick, not a wrapper element: the items are direct children of
`.sk-nav-cluster`, so a column layout on the cluster would have stacked them
too. This way the markup is untouched.

Pinned by `ProductionBenchLayoutTest::stacks one nav cluster per line and gives
each group label a line of its own`.

### O3 — Settings is ruled off, not pushed right

§3.4 pinned Settings to the trailing edge with `ml-auto`, and C5 in §8 fixed the
flex chain so the pin actually worked. It worked, and the owner preferred the
original position: five items with one parked alone at the far right reads as
more important than the four workflow sections it sits beside, which is the
opposite of "Settings is a utility".

`margin-inline-start: auto` and the `.sk-nav-row[data-level='1'] >
.sk-nav-cluster { flex: 1 1 auto; }` rule that fed it are both removed. What
remains of `data-nav-end` is the `border-l` separator, in the natural reading
order next to Purchasing.

The flag keeps its name: it still marks the last top-level tab. Its docblock in
`ProductionBenchNavigation::tree()` and its CSS comment now describe it as
drawing a rule rather than as moving the item, because the previous wording is
what made C5 look like a defect worth fixing.
