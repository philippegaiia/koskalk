# UX audit — user-side ingredient editor

**Status:** analysis only, nothing built (owner asked for analysis, 2026-09-04)
**Revised twice since:** §6 (review against `better-interface` / `better-accessibility`) and
§6.7 (review against `.ai/rules`). **§4 is the current recommendation** — it supersedes the
per-finding proposals in §2 where the two disagree. F8 was amended and F7 dropped; see §6.2.
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

> **Withdrawn 2026-09-05 (§7.1).** The premise is wrong. `blade.php:40` opens
> `@if ($isPlatformIngredient)` and `:180` closes it, enclosing **both** customisation cards; the
> main Save bar sits inside `@unless ($isPlatformIngredient)` at `:185`. The cards and the Save bar
> are **mutually exclusive** and never appear together, so there are not three save models and
> nothing "silently does not save". The unification proposal above is withdrawn. The underlying
> complaint that survives is F2: `{{ $this->form }}` renders unconditionally at `:182-184`, so a
> platform ingredient still shows a large disabled 5-tab form above two editable cards — an inert
> surface adjacent to an editable one, which is a presentation problem, not a save-model conflict.
> See §7.2(a).

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

> **Refined 2026-09-04 (§4 #2).** Prefer **reuse** over invention: the workbench already ships the
> indicator (`dirtyStateRegistry` + `<x-workflow-action-bar role="status">`,
> `partials/recipe-workbench/instructions-media.blade.php:8-34`). Adopt the indicator, with or
> without its autosave. CLAUDE.md requires it ("Clear unsaved state indicator always visible").

---

### F7 — Row entry points are icon-only

Both links are 36 px icon-only (`grid size-9`): a pencil for edit (`:189`), an eye for view
(`:256`). At 36 px they clear WCAG 2.5.8 (24 px minimum), but the primary action on every row
has **no visible text** — meaning lives entirely in `title` and `aria-label`, and the
edit-vs-view distinction is carried by icon literacy alone.

**Proposal:** show icon + text at wider widths; at minimum differentiate the two states visually
(weight or colour), not just by glyph.

> **Superseded 2026-09-04 (§6.2, §4 "Dropped").** Neither escalation trigger applies — both links
> already carry `aria-label` and `title` — so this is ladder step 5 for a non-problem. Not being
> taken forward. The observation stands as background; it is not a recommendation.

---

### F8 — The carrier-oil warning names no remedy *(amended 2026-09-04)*

`is_soap_saponification_trusted` is a `Hidden` field (IngredientEditor.php:656) deciding whether
the Soap chemistry tab exists (`soapChemistryAvailable()`, :1389). A carrier oil without it gets a
warning aside (blade.php:31-36).

**Retracted — the hidden flag is deliberate, not a defect.** `.ai/rules/dashboard.md` states it
outright: *"A workspace user cannot mark a manually created ingredient as trusted for soap
saponification… **Keep the trust flag hidden in the workspace editor.**"* The original audit called
this a finding; it is intended behaviour. **This clause is withdrawn.**

**What survives, and is now sharper.** The warning is correct to fire, but it names no way to
recover — and the same rule supplies the remedy the warning should state: soap chemistry is
retained only for an **editable duplicate of a trusted platform ingredient** whose `source_data`
contains `user_authoring.trusted_koh_sap_value`. The recovery path exists; it is simply never
communicated. `better-interface` escalates errors that name no recovery.

**Proposal (revised):** have the warning state the remedy — duplicate a trusted platform ingredient
to get editable soap chemistry — rather than only warning that something is off. Do **not** unhide
the flag.

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
   a reachable 403. *(Ladder step 4: correct the value, no new machinery.)*
2. **F6** — **reuse** the workbench's unsaved-state indicator rather than designing a guard:
   `dirtyStateRegistry` + `<x-workflow-action-bar role="status" aria-live="polite">`
   (`partials/recipe-workbench/instructions-media.blade.php:8-34`). Required by CLAUDE.md's
   *"Clear unsaved state indicator always visible"*. *(Ladder step 3.)*
3. **F4** — add a "shared with your workspace" marker to both override cards.
4. **F5** — validate the persisted Composition tab on load (`persistTabInQueryString` can hold a
   tab that no longer exists).
5. **F11** — add `wire:confirm` to Cancel at `blade.php:120`, copying `:131`. One attribute.
6. **F10** — `<h4>` → `<h2>` at `blade.php:43`. Platform branch only; if #7 lands first, this
   dissolves into it.

**Structural (the real win)**
7. **F2** — **delete** the disabled form from the platform branch first, and let the summary card
   that already exists (`blade.php:41`) carry INCI / CAS / EC / allergens. *(Ladder step 1.)* Only
   if that proves insufficient should a separate reference route be considered — and per
   `.ai/rules/views.md` it must stay **concise** (task copy, safety warnings) and link to WordPress
   for depth rather than duplicate it.

**Follow-ups**
8. **F8** — make the carrier-oil warning state the remedy: soap chemistry is editable only on a
   **duplicate of a trusted platform ingredient** carrying `user_authoring.trusted_koh_sap_value`.
   **Do not unhide the flag** — `.ai/rules/dashboard.md` requires it hidden.
9. **F12** — derive the guidance card's action set from state explicitly, so the primary button
   stops changing meaning invisibly (`blade.php:113-147`).
10. **F9** — decouple carrier-oil detection from the `Lipids` category.
11. **F13** — add a `title` to the truncating breadcrumb (`blade.php:14`).

**Dropped**
- **F7** — leave as-is. The targets are 36×36px, clearing the WCAG 2.5.8 floor of 24×24px, and
  both links already carry a contextual `aria-label` and a `title`. Adding text labels is the most
  expensive rung on the ladder for something that isn't broken.
- **F8 (first clause)** — the hidden `is_soap_saponification_trusted` flag is mandated by rule,
  not a defect. See §6.7.

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

1. **F2 — the ladder resolved an internal contradiction, and narrowed it further.** The audit
   already disagreed with itself: F2's own proposal (§2) said *stop rendering the form* and show the
   data as an infolist, while §4 proposal 5 said *split the route* and build a **reference page**.
   The ladder settles it in favour of deletion — and then goes further than F2 did: don't render a
   new infolist either, because the summary card **already exists** (`blade.php:41`) and already
   carries INCI / CAS / EC / allergens. `views.md` then caps how much may be shown at all (§6.7).
   Sequence: delete the form (step 1) → reuse the existing card (step 3) → only then consider a new
   route (step 5).
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

- **F7 (icon-only row actions): LOW, and dropped from the proposal list.** *Correction to the
  previous paragraph's framing:* the original F7 already noted that 36×36px clears the WCAG 2.5.8
  floor of 24×24px, so it never claimed the targets were too small — my first draft of this section
  mischaracterised the finding as a sizing error, and it was not one. The real reason to demote it
  is different: **neither escalation trigger applies**. Each link carries a contextual `aria-label`
  **and** a `title` (`ingredients-index.blade.php:189`, `:256`), so it is neither unnamed nor
  truncated-with-no-full-value. What remains is genuinely minor — edit vs. view is carried by icon
  literacy alone, and the two are mutually exclusive (`@if ($canEdit)`), so the eye is a Viewer's
  only entry point. Since nothing escalates, the proposed fix (visible text labels) is ladder
  **step 5**, the most expensive rung, for a non-problem. Dropped, not deferred.
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

### 6.7 Project rules (`.ai/rules`) — checked late, and it mattered

This audit was written against the codebase and the design skills without first reading
`.ai/rules/index.md`. That was a mistake — the index maps the audited files to rule files directly
(`app/Livewire/Dashboard/IngredientEditor.php` → `dashboard.md`; `resources/views/**` → `views.md`;
`app/Livewire/**` → `livewire.md`). Reading them changed one finding and confirmed another.

- **`dashboard.md` → F8 amended.** The hidden `is_soap_saponification_trusted` flag is required by
  rule ("Keep the trust flag hidden in the workspace editor"), so that clause is **withdrawn**. The
  rule also named the remedy the carrier-oil warning should communicate. See F8.
- **`views.md` → reinforces F2's delete-first conclusion.** *"WordPress owns… long-form end-user
  documentation. Laravel… keeps concise task copy, contextual help, and visible safety/compliance
  warnings; link to WordPress for deeper material instead of duplicating it."* So the platform
  reference surface should stay **concise**: badge-level facts (INCI, CAS/EC, allergens) plus a link
  out. Any proposal that expands this page into a full technical reference would violate the rule as
  well as lose the cheaper-fix argument.

  > **Withdrawn 2026-09-05 (§7.1).** I over-applied this rule. It assigns WordPress the *public*
  > marketing site, *editorial* content and *long-form end-user documentation*. Ingredient technical
  > data — INCI, CAS/EC, allergens, SAP values, fatty-acid profiles — is **application domain data,
  > not documentation**, and nothing in the rule prohibits displaying it in-app. The platform
  > reference surface may legitimately be as rich as the data supports. The delete-first argument for
  > F2 (§6.1) stands on the cheaper-fix ladder alone, not on this rule.
- **`livewire.md` → checked and cleared, not filed.** The rule says *"Enforce with the throwing
  `authorize()` call… in controllers and Livewire"*, but `IngredientEditor.php:210` uses
  `abort_if($this->isReadOnly(), 403)`. Measured before ruling: `$this->authorize()` appears 12
  times under `app/Livewire/`, **all in `RecipeWorkbench`**; `abort(403)` / `abort_if` appears 6
  times across **four other** components (`SettingsIndex` ×2, `IngredientEditor`, `SupplierEdit`,
  `SupplierListingCreate`, `SupplierCreate`). A rule that most of the owner's own components do not
  follow is not the house rule. **Not a finding.**

**Process note for next time:** read `.ai/rules/index.md` and load every rule whose glob matches the
file under review *before* writing findings — the same discipline as reading the governing spec.

---

## 7. Evaluation of the journey-based revision brief (2026-09-05)

A review brief proposed reorganising this audit around **three journeys** — *customize a platform
ingredient*, *duplicate a platform ingredient*, *create an ingredient* — and correcting six
findings. Every correction was checked against the code. **All six hold up.** Two need refinement
and one needs pushing back on before §4 is rewritten.

### 7.1 Verified as correct

**F1 — `isReadOnly()` is private.** Confirmed: `IngredientEditor.php:1380`
`private function isReadOnly(): bool`. So §4 #1 ("gate the save bar on `isReadOnly()`") is **not
implementable as written** — Blade cannot call it. `soapChemistryAvailable()` is private too
(`:1389`). *Accepted.*

**F5 — classification is already handled.** Confirmed: `IngredientEditor.php:749`
`->visible(fn (Get $get): bool => $get('ingredient_structure') === 'blend')`. The Composition tab
already hides itself for single ingredients. My original F5 **framed designed behaviour as a bug**.
*Accepted — F5 must be rewritten, not fixed.*

**F2 — the WordPress rule does not prohibit in-app technical data.** Re-read `views.md`: it assigns
WordPress the *"public soapkraft.com site, marketing, editorial content, and long-form end-user
documentation"*; Laravel keeps *"concise task copy, contextual help, and visible safety/compliance
warnings"*. INCI, CAS/EC, allergens and SAP values are **application domain data, not
documentation**. I over-applied the rule in §6.7 to argue for capping the reference surface.
*Accepted — §6.7's third bullet is wrong and is withdrawn.*

**F3 — not three save models; the cards and the Save bar are mutually exclusive.** Confirmed.
`blade.php:40` opens `@if ($isPlatformIngredient)` and `:180` closes it, enclosing the guidance card
(`:91-148`) and the material-code card (`:150-179`). The main Save bar sits inside
`@unless ($isPlatformIngredient)` at `:185`. A platform ingredient gets the cards and no Save bar;
a user-owned ingredient gets the Save bar and no cards. *Accepted — F3 is withdrawn as stated.*

**F6/F11 — reuse the whole dirty-state pattern, including navigation protection.** The existing
pattern is broader than the indicator I proposed reusing: `component.js:1109`
`dirtyStateRegistry.blocksNavigation()` and `:1138`
`window.addEventListener('beforeunload', …)`. My §4 #2 ("adopt the indicator, with or without its
autosave") would have taken the indicator and **discarded the protection**. *Accepted — an indicator
alone does not prevent lost edits.*

**F8 — trust belongs in the duplication journey, not only in a warning.** Confirmed, and the
mechanism is richer than the brief states. `UserIngredientAuthoringService::duplicate()` (`:188`) is
a first-class flow; `duplicateSourceData()` (`:545`) writes `user_authoring.trusted_koh_sap_value`
**and** `trusted_fatty_acid_profile` into the copy's `source_data`, and `hasInheritedSoapChemistry()`
(`IngredientEditor.php:1400`) reads exactly those keys. *Accepted.*

### 7.2 Refinements the brief does not mention

**(a) F3 dies, but F2 survives untouched — the two were entangled.** `blade.php:182-184` renders
`{{ $this->form }}` **unconditionally**, for platform ingredients too. A platform ingredient
therefore shows two editable cards, no Save bar, *and a large disabled 5-tab form*. That is not
three save models — but it is still an editable surface sitting directly above an inert one.
Withdrawing F3 does not touch F2.

**(b) F5 has a real defect, just not the one I named.** `->visible()` reads
`$get('ingredient_structure')` — **live form state, not the persisted model** — while
`persistTabInQueryString('ingredient-tab')` (`:646`) stores the active tab in the URL. Switching a
blend to single hides the tab but leaves the query string pointing at it, so on reload Filament is
asked to activate a tab that no longer exists. And hiding ≠ clearing: the constituent rows are still
in state. Both halves need handling.

**(c) Duplication has hard guardrails that journey 2 must surface.** From
`UserIngredientAuthoringService::duplicate()`:
- only **platform** ingredients can be duplicated (`duplicate_platform_only`, `:190`);
- the category must be workspace-authorable — **soapmaking alkalis are platform-only**
  (`assertWorkspaceAuthorableCategory`, `:362`);
- **a lipid with no `sapProfile->koh_sap_value` cannot be duplicated at all**
  (`duplicate_soap_profile_required`, `:200`) — a hard blocker, absent from this audit entirely;
- **images are dropped** (`featured_image_*` / `icon_image_*` → null, `:237-240`) and
  `info_markdown` → null (`:235`), while platform guidance is copied across as a workspace override
  (`:246-254`).

Copied by `deepCopyRelations()` (`:294`): `sapProfile`, `fattyAcidEntries`, `components`,
`allergenEntries`, `substanceEntries`, `functionAssignments`, `ifraCertificates` + `limits`, and up
to 5 aliases. Reset: `public_id`, `catalog_key` (→ `USR…`), owner (→ workspace), `visibility`
(→ Private), `requires_admin_review` (→ false).

### 7.3 One pushback

The brief says the `isReadOnly()` fix "needs adjustment because that method is currently private".
Making it public would fix the Save bar, but it is the **wrong shape** for the journey model being
requested. `isReadOnly()` (`:1380`) returns true for two different situations:

1. a **platform** ingredient — the form is read-only permanently, *and* the user has two editable
   workspace cards below it (journey 1);
2. a **user-owned** ingredient the user may not edit (a **Viewer**) — *everything* is read-only and
   there are no cards.

A single boolean cannot express "read-only form **plus** editable cards" versus "read-only
everything". If §4 is organised by journey, the view needs **two distinct signals** — e.g.
`isPlatformReference()` and `canEditIngredient()` — rather than one widened `isReadOnly()`. Otherwise
Viewers and platform-customisers keep rendering identically, which is exactly the permission
inconsistency the brief ranks first.

### 7.4 Verdict

All six corrections are sound and accepted. Three refinements (§7.2) and one change of shape (§7.3)
must be folded in before §4 is rewritten.

**Not yet done:** §4 has **not** been reorganised around the three journeys; no expected behaviour or
acceptance criteria have been written; §6.7's third bullet and F3 are still present and need
withdrawing. This stage remains analysis-only — no application code changed.
