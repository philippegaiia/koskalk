# UX audit — user-side ingredient editor

**Status:** analysis only, nothing built (owner asked for analysis, 2026-09-04)
**Surface:** `app/Livewire/Dashboard/IngredientEditor.php` +
`resources/views/livewire/dashboard/ingredient-editor.blade.php`
**Entry points:** `ingredients-index.blade.php:189` (pencil) and `:256` (eye)
**Related:** `app/Models/Ingredient::isEditableBy()` (392), `routes` → `ingredients.edit`

---

## 1. One route, three very different pages

`ingredients.edit` serves three intents, chosen at runtime:

| Mode | Condition | Heading | Form | Save bar |
|---|---|---|---|---|
| **Create** | no ingredient | `create.heading` | live | yes |
| **Edit** | own / workspace ingredient you may edit | `edit.heading` | live | yes |
| **Reference** | `owner_type === null` (platform) | `reference.heading` | **entirely disabled** | no |

The heading correctly signals which mode you are in. **The rest of the page does not.** The
recurring problem below is that mode-dependent behaviour is gated on three *different*
predicates that do not agree with each other.

---

## 2. Findings

### F1 — Save button on a read-only form returns 403 *(defect, quick fix)*

The two gates disagree:

```php
// blade.php:185 — gates the Save bar
@unless ($isPlatformIngredient)          // $isPlatformIngredient === owner_type === null

// IngredientEditor.php:1380 — gates the disable
isReadOnly()  // owner_type === null || ! $user || ! $ingredient->isEditableBy($user)
```

`isEditableBy()` (Ingredient.php:392) returns **false** for a `Viewer` member of the owning
workspace. So a Viewer opening a *workspace-owned* ingredient gets:

- `owner_type !== null` → **not** "platform" → **Save bar is rendered**
- `isEditableBy() === false` → **`isReadOnly()` is true → form fully disabled**
- Clicking Save → `abort_if($this->isReadOnly(), 403)` (IngredientEditor.php:210) → **403**

They also get none of the editable cards, because those are gated on `@if ($isPlatformIngredient)`
too. Result: a dead form and a button that errors.

The index sends them here deliberately — `$canEdit` is false (blade.php:122), so they get the
**eye** icon labelled "view" (blade.php:256). The destination contradicts that promise.

**Verify with a Viewer-role account before fixing** — I traced this statically, not by logging in.

**Fix:** gate the bar on the same predicate that disables the form.

```blade
@unless ($this->isReadOnly())
```

and give read-only visitors a "Back to ingredients" button plus an inline note explaining why
nothing is editable.

---

### F2 — Platform ingredients render the whole 5-tab form, disabled *(biggest UX cost)*

For a platform ingredient the page is three useful cards plus a **fully inert five-tab form**
(details, composition, documents, soap chemistry, compliance). The form carries most of the
page's visual mass and all of it is dead.

Three consequences:

1. **Duplication.** The Reference summary card already shows INCI, CAS, EC, additional
   identifiers and allergens. The disabled form repeats CAS/EC (identity section) and allergens
   (compliance tab). The same fact appears twice, once as reference and once as a greyed field.
2. **No explanation at the point of confusion.** The only signal that this is read-only is the
   `<h1>` and the intro paragraph, both far above the form. Nothing on the form itself says why.
3. **Affordance noise.** Five tab labels read as editable destinations. Users will click them.

**Proposal:** stop rendering the Filament *form* for platform ingredients. Render the technical
data as read-only description lists (an infolist), or collapse it behind a
"Full technical data" disclosure. Keep the two genuinely editable cards at the top.

---

### F3 — Three different save models on one page

| Thing | How it saves | Where |
|---|---|---|
| Main form | one submit | sticky bottom action bar (`x-workflow-action-bar`) |
| Material code | own inline Save | inside its card |
| Workspace guidance | Edit → inline editor → Save / Cancel | inside its card |

Three positions, three idioms, on one screen. A user who changes the material code and then
scrolls to the bottom Save will silently not save it — and vice versa.

**Proposal:** unify. Either everything submits from one bar, or the two workspace overlays
autosave on blur. Mixed models are what cause the lost-edit reports.

---

### F4 — Workspace-scoped edits are not labelled as shared

Material code and guidance are **workspace-level overrides** — they change what every member
sees. The UI does not say so. The guidance badge indicates *source* (platform vs workspace),
not *blast radius*.

**Proposal:** an explicit scope marker on both cards — "Shared with everyone in
&lt;workspace&gt;" — so a user understands this is not a private note.

---

### F5 — The Composition tab vanishes when the type changes

```php
Tab::make(...)->visible(fn (Get $get) => $get('ingredient_structure') === 'blend')
```

Switch type from *blend* to *single* while sitting on Composition and the tab disappears
underneath you. Combined with `persistTabInQueryString('ingredient-tab')`, the query string can
hold a tab that no longer exists.

**Proposal:** keep the tab present but disabled with "Only for blends", or redirect focus to
Details when the current tab becomes invalid.

---

### F6 — No unsaved-changes protection

Five tabs, dozens of fields, one Save at the bottom, and `wire:navigate` links in the breadcrumb
and sidebar. Navigating away silently discards everything. This is the highest-friction item for
a form this long.

**Proposal:** a `beforeunload` / `wire:navigate` dirty guard, or autosave per section.

---

### F7 — Row entry points are icon-only

Both links are 36 px icon-only (`grid size-9`): a pencil for edit (`:189`), an eye for view
(`:256`). At 36 px they clear WCAG 2.5.8 (24 px minimum), but the primary action on every row
has **no visible text** — meaning lives entirely in `title` and `aria-label`, and the
edit-vs-view distinction is carried by icon literacy alone.

**Proposal:** show icon + text at wider widths; at minimum differentiate the two states visually
(weight or colour), not just by glyph.

---

### F8 — A hidden flag gates an entire tab, with no path to resolve it

`is_soap_saponification_trusted` is a `Hidden` field (IngredientEditor.php:656). It decides
whether the Soap chemistry tab exists (`soapChemistryAvailable()`, :1389). A carrier oil without
it gets a warning aside (blade.php:31-36) — but the user cannot see the flag, change it, or act
on the warning.

**Proposal:** surface the trust state and the remedy ("request verification", "supplier
documented value missing") rather than only warning that something is off.

---

### F9 — "Carrier oil" is hard-coded to one category

```php
$isCarrierOil = IngredientCategory::tryFrom((string) ($data['category'] ?? '')) === IngredientCategory::Lipids;
```

Semantic coupling: carrier-oil-ness is inferred from the *Lipids* category. If the taxonomy
gains a sibling category, the warning silently stops firing. Minor, but it will rot.

---

## 3. What is already good

Worth naming, because these are the patterns the fixes should extend rather than replace:

- **Accessibility is genuinely strong** — `aria-labelledby`, `aria-current`, `aria-describedby`,
  `aria-invalid`, `role="alert"`, and `aria-live="polite"` on the composition total. Also
  `role="status"` on the blend balance indicator. This is above average; don't regress it.
- **Progressive disclosure on compliance** — allergen and IFRA sections appear only when
  `requires_aromatic_compliance` is on. Correct use of a toggle to gate whole sections.
- **The platform/workspace guidance badge** — a clean way to show provenance and give
  "use platform / use workspace" escape hatches. Reuse this pattern for material code.
- **Mode-aware heading and intro** — the page does tell you what it is; the problem is that
  nothing below the heading agrees.
- **Blend composition UX** — live total, balance indicator, quick-create for a missing component,
  and inline validation with a 0–100 range check. This is the best part of the page.

---

## 4. Proposals, in order

**Quick wins (independent, low risk)**
1. **F1** — gate the save bar on `isReadOnly()`, not `$isPlatformIngredient`. One line; removes
   a reachable 403.
2. **F6** — add a dirty-state guard.
3. **F4** — add a "shared with your workspace" marker to both override cards.
4. **F5** — handle the disappearing Composition tab.

**Structural (the real win)**
5. **F2** — split the route by intent. Platform ingredients should open a **reference page**, not
   an editor: workspace overrides at the top, technical data as read-only description lists, no
   disabled form. Own/editable ingredients keep the current editor. This removes the dead form,
   removes the duplication with the summary card, and makes F3's mixed save model largely moot.

**Follow-ups**
6. **F8** — surface the soap-trust state.
7. **F7** — label the row actions.
8. **F9** — decouple carrier-oil detection from the Lipids category.

---

## 5. Open questions for the owner

- Should **Viewers** be able to reach this page at all, or should the index show a read-only
  detail page instead? (Affects whether F1 is a fix or a redirect.)
- Is the **material code** workspace-scoped *only* for platform ingredients by design, or should
  workspace-owned ingredients get one too?
- Should **guidance** and **material code** autosave, or keep explicit Save buttons?
- How much of the technical data do users actually need on a platform ingredient? If most open it
  just to check an INCI or an allergen, the reference card should lead and the rest should be
  behind a disclosure.

---

## 6. Skill-informed review (2026-09-04)

Reviewed against `jakubkrehel/skills` — already installed at `~/.agents/skills` and symlinked into
`~/.claude/skills`, so **nothing was installed or modified**. Applied: `better-interface`
(orchestrator — severity scale, escalation triggers, cheaper-fix ladder, 15-finding cap),
`better-accessibility` (+ `hit-areas.md`), `better-ui`, `better-writing`.

**`interface-review` is the wrong skill here and was not used.** It reviews a *diff*; this is an
analysis of existing screens with no change under review, so there is no scope to resolve and no
`Introduced` / `Regression` / `Pre-existing` classification to make. It becomes the right tool the
moment anything in section 4 is implemented.

### 6.1 What the review changed

Four changes to section 4, all from `better-interface`'s cheaper-fix ladder
(**1 Delete → 2 Use the platform → 3 Reuse the project's → 4 Correct the value → 5 Add**):

1. **F2 inverted from "build" to "delete" (step 5 → step 1).** Section 4 proposal 5 proposed
   building a reference page for platform ingredients. The ladder ranks deletion above
   construction: removing the disabled form from the platform branch gets most of the benefit with
   none of the new surface. Whatever reference data still needs a home should be absorbed by the
   summary card that **already exists** (`blade.php:41`). Building a new page is the last resort,
   not the first.
2. **F6 re-specified as "reuse", not "add" (step 5 → step 3).** The workbench already ships the
   pattern: `dirtyStateRegistry` + `recipeContentAutosave` + `<x-workflow-action-bar>` with
   `role="status" aria-live="polite" aria-atomic="true"` and `allSaved` / `unsaved` / `saving` /
   `savedAt` / `saveFailed` labels
   (`partials/recipe-workbench/instructions-media.blade.php:8-34`). The indicator can be adopted
   without the autosave. It is the *only* unsaved-state indicator under
   `resources/views/livewire/dashboard/`, and the ingredient editor has none.
3. **F1 is "correct the value", not "add a guard" (step 4).** The Blade gate and `isReadOnly()`
   disagree; aligning them is a one-line change, not new machinery.
4. **F7 downgraded and re-scoped (see 6.2).** Adding text labels is step 5, the most expensive
   rung, and the trigger for it turned out not to apply.

### 6.2 Corrections — things I over-called

- **F7 (icon-only row actions): downgraded HIGH-ish → LOW.** `better-accessibility/hit-areas.md`
  sets the WCAG 2.5.8 floor at **24×24px**; these targets are **36×36px** (`size-9`), comfortably
  clear — "too small" was simply wrong. Both escalation triggers I might have reached for are also
  cleared: each link carries a contextual `aria-label` **and** a `title`
  (`ingredients-index.blade.php:189`, `:256`). What remains is minor: edit vs. view is carried by
  icon literacy alone, and the two are mutually exclusive (`@if ($canEdit)`), so the eye is a
  Viewer's only entry point. Not worth spending step-5 effort on.
- **Tab ARIA: not a finding.** CLAUDE.md:181 documents a hand-written tab pattern
  (`role="tablist"` / `role="tab"` / `role="tabpanel"`), but these tabs are Filament
  `Tabs::make()` (`IngredientEditor.php:644`), and Filament emits `role="tab"` with
  `x-bind:aria-selected` itself (`vendor/filament/schemas/src/Components/Tabs.php:515`, `:415`).
  The project standard does not apply to framework-rendered tabs.
- **"State by colour alone": not a finding.** The workspace/platform badge
  (`blade.php:96-98`) is text-labelled, not colour-coded.
- **"Table-first UI over card-based design" (CLAUDE.md:148): not a violation.** The `sk-card`
  blocks here are content panels, not a data list presented as cards.

### 6.3 New findings

- **F10 — Heading level skip, platform branch only — MEDIUM (a11y).** Document order is
  `<h1>` (`:18`) → `<h4>` (`:43`) → `<h2>` (`:95`) → `<h2>` (`:153`). The `h1 → h4` jump skips two
  levels and then descends `h4 → h2`, which breaks heading navigation. Scoped precisely: the `<h4>`
  is inside `@if ($isPlatformIngredient)` (`:40`), so **user-owned ingredients render a clean
  `h1 → h2 → h2`**. Fix is step 4 (`h4` → `h2`, one character) — and it dissolves entirely into the
  F2 fix, since restructuring the platform branch re-levels this heading anyway.
- **F11 — "Cancel" discards the draft with no confirmation — MEDIUM (ui).** `blade.php:120`
  (`cancelWorkspaceGuidanceCustomization`) throws away in-progress edits unprompted, while its
  destructive sibling on the very next branch, `usePlatformGuidance` (`:131`), *does* carry
  `wire:confirm`. `better-interface` escalates destructive actions without confirmation. Fix is
  step 2: add `wire:confirm`, copying the line already there.
- **F12 — The guidance card's actions are a 4-branch state machine rendered as if/elseif —
  MEDIUM (ui/writing).** `blade.php:113-147`: with no override you get **Customize** (primary);
  with an active override **Edit** (secondary) + **Use platform** (ghost); with an inactive override
  **Edit** (secondary) + **Use workspace** (primary). "Edit" appears in three branches and the
  *primary* button changes meaning between branches, so the page's most prominent action depends on
  state the user cannot see. The badge is the only signal, and it sits above the fold-separated
  helper text.
- **F13 — Ingredient name truncates with no fallback — LOW (a11y).** The breadcrumb's
  `aria-current="page"` span is `min-w-0 truncate` with no `title` (`blade.php:14`). Full value is
  recoverable from the Details tab's name field, which is why this is LOW rather than an escalated
  HIGH.

### 6.4 Re-graded findings

13 findings, under `better-interface`'s cap of 15.

| Severity | Domain | Location | Before | After | Why |
|---|---|---|---|---|---|
| HIGH | error | F1 `IngredientEditor.php:210` / `blade.php:185` | Save bar shown on a read-only form → bare 403 | Gate on `isReadOnly()` | An error that names no way to recover |
| HIGH | ui | F2 `blade.php:40-89`, `:644` | Whole 5-tab form rendered disabled | **Delete** the form from the platform branch; let the existing summary card carry reference data | Ladder step 1 beats building a page |
| HIGH | a11y | F6 `blade.php` (whole page) | No unsaved indicator anywhere | Reuse the workbench `dirtyStateRegistry` + `role="status"` bar | Violates CLAUDE.md "Clear unsaved state indicator always visible"; 5 tabs × 3 save models |
| MEDIUM | a11y | F10 `blade.php:43` | `h1 → h4` (platform only) | `h4` → `h2` | Structure is navigation; nest without skipping |
| MEDIUM | ui | F11 `blade.php:120` | Cancel discards draft, no confirm | Add `wire:confirm` (copy `:131`) | Destructive with no confirmation |
| MEDIUM | ui | F12 `blade.php:113-147` | 4-branch chain, primary action shifts meaning | Derive the action set from state explicitly | Primary button should not change meaning invisibly |
| MEDIUM | ui | F3 three save models | Sticky bar / inline card / edit-then-save | Unify, or state the model per card | Mixed mental models on one page |
| MEDIUM | ui | F5 Composition tab | Tab vanishes on type flip; `persistTabInQueryString` may hold a dead tab | Validate the persisted tab on load | Persisted state pointing at something gone |
| MEDIUM | writing | F4 workspace overrides | Shared-scope edits not labelled | Add a shared marker | Users cannot tell local from workspace-wide |
| MEDIUM | ui | F8 `IngredientEditor.php:656` | `Hidden` flag gates a whole tab; warning names no remedy | Surface the state and the path to resolve it | Dead end with no recovery |
| LOW | a11y | F7 `ingredients-index.blade.php:189`, `:256` | Edit vs. view by icon alone | Leave as-is unless cheap | 36px clears the 24px floor; named and titled |
| LOW | a11y | F13 `blade.php:14` | Name truncates, no `title` | Add `title` | Full value recoverable elsewhere |
| LOW | domain | F9 `blade.php:4` | Carrier oil hard-coded to Lipids | Decouple | Correctness, not UX-critical |

### 6.5 Verification

Verified by reading source: heading order and its `@if` scoping; Filament's `role="tab"` /
`aria-selected` emission; the row actions' `aria-label` + `title` + `size-9`; the badge being
text-labelled; `wire:confirm` present on `:131` and absent on `:120`; the guidance branch chain;
`dirtyStateRegistry` existing only in the workbench.

**Not verified:** F1 was traced statically and never reproduced with a real Viewer-role account —
it remains the one finding that should be confirmed against a live session before it is acted on.
No browser check was performed (the Vite dev server is down; see memory).

### 6.6 Verdict

**Approve the analysis as a basis for work; do not start from proposal 5.** Three of the four quick
wins (F1, F4, F5) stand as written. F6 should be implemented by copying the workbench's existing
indicator rather than designing a new one. F2 should start as a **deletion**, with a new reference
page only if the summary card proves insufficient. F7 should be dropped from the follow-ups.
F10-F13 are small and independent; F10 and F11 are one-line changes.
