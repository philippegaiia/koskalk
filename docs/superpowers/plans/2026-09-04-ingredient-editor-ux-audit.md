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
