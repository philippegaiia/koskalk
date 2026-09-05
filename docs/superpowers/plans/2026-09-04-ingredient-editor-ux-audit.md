# UX audit — user-side ingredient editor

**Status:** analysis only, nothing built (owner asked for analysis, 2026-09-04)
**Revised four times since:** §6 (`better-interface` / `better-accessibility` review), §6.7
(`.ai/rules`), §7 + a full §4 rewrite (journey-based revision brief, 2026-09-05), and the
**F1 / §4.5 correction** establishing that the `Viewer` role is vestigial (2026-09-05).

> **§4 is the authoritative recommendation.** It is organised around three user journeys —
> *customize a platform ingredient*, *duplicate a platform ingredient*, *create an ingredient* —
> plus **read-only visitor** behaviour, and it **supersedes §2 and §3 wherever they disagree**.
> §2 is retained as the record of how each finding was reached, including the ones since withdrawn
> (F3, F7) or rewritten (F1, F5, F8).
>
> **Naming note.** Earlier revisions of this document said "Viewer". No such user exists —
> `WorkspaceMemberRole::Viewer` is declared and never used (F1, §4.5). Read "Viewer" in the older
> sections as *"a user with no edit capability"*, which today means a **non-member**.

**Surface:** `app/Livewire/Dashboard/IngredientEditor.php` +
`resources/views/livewire/dashboard/ingredient-editor.blade.php`
**Entry points:** `ingredients-index.blade.php:189` (pencil) and `:256` (eye)
**Related:** `app/Models/Ingredient::isEditableBy()` (392),
`app/Services/UserIngredientAuthoringService::duplicate()` (188), `routes` → `ingredients.edit`
**Permission model:** *origin* (`owner_type`) is separate from *capability*
(`isEditableBy()`, `canEditWorkspaceGuidance()`, `canEditWorkspaceMaterialCode()`) — see §4.1.

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

`isEditableBy()` (Ingredient.php:392) admits only **Owner, Admin or Editor** in the owning
workspace. So the reachable actor is *not* a `Viewer` — see the correction below — it is **any
logged-in user with no membership in the owning workspace** who opens a **public, workspace-owned**
ingredient:

- `owner_type !== null` → **not** "platform" → **Save bar is rendered**
- `workspaceRoleFor()` returns `null` → not in [Owner, Admin, Editor] → **`isReadOnly()` true →
  form fully disabled**
- Clicking Save → `abort_if($this->isReadOnly(), 403)` (IngredientEditor.php:210) → **403**

The route admits them because `IngredientController::edit()` (`:38-41`) lets any authenticated user
through when `isAccessibleBy()` holds, and `Ingredient::isAccessibleBy()` (`:387`) is satisfied by
`isPublicCatalog()` alone — that is, by `visibility === Public` (`:352`) — with no membership
condition.

They also get none of the editable cards, because those are gated on `@if ($isPlatformIngredient)`
too. Result: a dead form and a button that errors.

The index sends them here deliberately — `$canEdit` is false (blade.php:122), so they get the
**eye** icon labelled "view" (blade.php:256). The destination contradicts that promise.

> **Correction (2026-09-05) — the actor is not a Viewer, and never could be one.**
> I originally wrote this as "a `Viewer` member of the owning workspace". **That user cannot
> exist.** `WorkspaceMemberRole::Viewer` (`app/Enums/WorkspaceMemberRole.php:10`) is declared and
> then referenced **nowhere** — not in `app/`, `resources/`, `routes/`, `database/` or `tests/`.
> The only code that creates a membership row assigns `Owner` (`SettingsIndex.php:167`);
> `WorkspaceMemberFactory` defaults to `Editor`; there is no member-management UI in Filament or
> Livewire; and the local database holds **2 membership rows, both `owner`**. Every capability
> check in the codebase names only Owner, Admin and Editor. The role is vestigial — an enum case
> waiting for an invite flow that does not exist.
>
> This changes F1's **likelihood**, not its **validity**. The two gates still disagree, and the
> reachable actor is the non-member above. No public workspace-owned ingredient exists in the local
> database today, but `visibility` is user-settable, so the path is live rather than theoretical.
> It also changes the *reason* to fix it: §4.1's capability-based gate is correct for **the role
> set that exists now**, and it stays correct if `Viewer` is ever wired up.

**Unverified — still traced statically, never reproduced in a browser.** Reproducing it does *not*
need a Viewer account, since one cannot be created: set a workspace-owned ingredient to **Public**,
then open it authenticated as a user who is not a member of that workspace.

**Fix (superseded — see §4.1 and §4.5).** The original suggestion here was
`@unless ($this->isReadOnly())`, which **cannot work**: `isReadOnly()` is `private`
(`IngredientEditor.php:1380`) and is not callable from Blade.

The corrected fix is to gate on the **ingredient-data capability that already exists and is already
public** — `$ingredient->isEditableBy($user)` (`Ingredient.php:392`), surfaced through a public
accessor on the component if one is preferred. Origin (`$isPlatformIngredient`) stays in charge of
*layout*; capability decides *controls*. Read-only visitors additionally get a "Back to ingredients"
button and an inline note explaining why nothing is editable.

Note also that §4.5 corrects the scope of this finding: fixing the Save bar alone is insufficient.
The **heading** and the **read-only presentation** must reflect the same capability, or the page
still promises editing it cannot deliver.

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

**Proposal (aligned with §4.2, which is authoritative):** stop rendering the Filament *form* for
platform ingredients and render the technical data as **readable information** — description lists
or an infolist — instead of disabled controls. Keep the two genuinely editable cards at the top.

Two clarifications added 2026-09-05:

- **The data is preserved, not hidden.** Composition (when the ingredient is a blend), documents and
  Soap chemistry all remain on the page. The earlier option of collapsing everything behind a
  "Full technical data" disclosure is **withdrawn** — it was justified by the WordPress rule in
  `views.md`, and §7.1 established that rule does not apply to in-app ingredient data. This change
  is about *how* the data renders, not how much of it survives.
- **Disabled controls are the actual defect.** An input carrying `disabled` still looks focusable,
  still announces as a form control, and still implies a save that will never come.

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

### F5 — Composition: classification already drives visibility *(rewritten 2026-09-05)*

```php
Tab::make(...)->visible(fn (Get $get) => $get('ingredient_structure') === 'blend')
```
`IngredientEditor.php:749`

**The original framing of this finding was wrong.** It treated the tab disappearing on a type change
as a defect. It is not: visibility is **classification-driven by design**, so Composition correctly
hides itself for a single ingredient such as coconut oil and shows only for ingredients made from
other ingredients. That behaviour is what the editor *should* do and needs no change.

Two things remain open, and **both are unverified — traced from source, never reproduced in a
browser**:

1. **Stale tab in the URL.** `persistTabInQueryString('ingredient-tab')` (`:646`) stores the active
   tab, while visibility is computed from **live form state**. Switching blend → single hides the
   tab but leaves the URL pointing at it; on reload Filament may be asked to activate a tab that no
   longer exists. **Reproduce before fixing.**
2. **Fate of existing constituents.** Hiding a tab does not clear state, so constituent rows persist
   while invisible. Whether they should be retained, discarded, or parked behind an explicit notice
   is a **product decision this audit deliberately does not make**. Recommended default: retain and
   disclose — do not silently discard entered data, and do not silently keep it invisible either.

**Proposal (revised):** keep classification-driven visibility exactly as it is. Separately,
reproduce (1) and settle (2) with the owner — see **§4.6**, which is authoritative. The previous
suggestion here ("keep the tab present but disabled with 'Only for blends'") is **withdrawn**: it
would show Composition for single ingredients, which is the opposite of the desired rule.

---

### F6 — No unsaved-changes protection

Five tabs, dozens of fields, one Save at the bottom, and `wire:navigate` links in the breadcrumb
and sidebar. Navigating away silently discards everything. This is the highest-friction item for
a form this long.

**Proposal:** a `beforeunload` / `wire:navigate` dirty guard, or autosave per section.

> **Corrected 2026-09-05 — see §4.7, which is authoritative.** The 2026-09-04 refinement below
> understated the existing pattern and its advice was wrong in one respect.
>
> - **Reuse, but reuse all of it.** The workbench ships the indicator *and* navigation protection:
>   `dirtyStateRegistry` + `<x-workflow-action-bar role="status">`
>   (`instructions-media.blade.php:8-34`), `blocksNavigation()`
>   (`component.js:1109`) and a `beforeunload` listener (`component.js:1138`).
> - **"With or without its autosave" is withdrawn.** An indicator alone **does not prevent lost
>   edits** — it tells you, it does not stop you. Navigation blocking is the part that matters, and
>   CLAUDE.md's *"Clear unsaved state indicator always visible"* is a floor, not the goal.
> - **No autosave is introduced** anywhere in this audit; unsaved-state handling is orthogonal to
>   save timing (§4.4, §5).
> - **Cancel confirms only when it would discard changes** (F11) — prompt if dirty, stay silent if
>   clean. `blade.php:120` currently discards with no confirmation while its sibling `:131` has
>   `wire:confirm`.

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

> **Integrated 2026-09-05 — see §4.3 and §4.4, which are authoritative.** The brief corrected this
> finding: trust should not be explained *only* as a remedy inside a warning, but built into the
> duplication and creation journeys. The mechanism, confirmed in code:
>
> - `duplicateSourceData()` (`UserIngredientAuthoringService.php:545`) writes
>   `user_authoring.trusted_koh_sap_value` **and** `trusted_fatty_acid_profile` into the copy's
>   `source_data`, but **only** when the source is trusted *and* has a `sapProfile->koh_sap_value`.
> - `hasInheritedSoapChemistry()` (`IngredientEditor.php:1400`) reads exactly those keys, so the
>   duplicate keeps an **editable** Soap chemistry tab.
> - A manually created ingredient has no `source_data`, so it can never be trusted — which is why
>   journey C must state the restriction up front rather than let it be discovered.
>
> Note the interaction with §4.3: a **lipid with no SAP value cannot be duplicated at all**
> (`duplicate_soap_profile_required`), so the remedy named in the warning is not always available.
> The warning must not promise a route that the blocker can refuse.

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

## 4. Recommendation (authoritative)

*Rewritten 2026-09-05 around three user journeys. **This section supersedes §2 and §3** wherever
they disagree. Behaviour labelled **Current** is what the code does today; **Recommended** is what
this audit proposes. **Nothing here has been implemented** — this stage is analysis only.*

### 4.1 Permission model — origin is not permission

The editor currently conflates two independent things:

- **Origin** — where the ingredient came from: `owner_type === null` (platform) vs workspace-owned.
  A property of the ingredient, not of the user.
- **Capability** — what this user may do. Three checks already exist, and **all three are public**:

| Capability | Existing check | True when |
|---|---|---|
| Edit ingredient data | `Ingredient::isEditableBy(User)` (`Ingredient.php:392`) | owner, or workspace Owner/Admin/Editor |
| Edit workspace guidance | `canEditWorkspaceGuidance()` (`:522`) | platform + active + Owner/Admin/Editor |
| Edit material code | `canEditWorkspaceMaterialCode()` (`:1343`) | platform + active + Owner/Admin/Editor |

`isReadOnly()` (`:1380`) is **private** and *derived*: true when the ingredient is platform **or**
not editable. Because it folds origin and capability together, it cannot express "the form is
read-only *and* two cards below it are editable" — yet that is exactly journey A.

**Recommended:** branch on **origin** for layout and on the **three capability methods** for
controls. No new flags are needed — the methods are public and already called from the view. What
must change are the two places that use origin as a stand-in for permission:

- `blade.php:185` — `@unless ($isPlatformIngredient)` gates the **Save bar** on origin. It should be
  gated on the ingredient-data capability instead. This single change removes the reachable 403.
- `blade.php:40` — `@if ($isPlatformIngredient)` gates the two workspace cards on origin, which is
  correct; their *controls* should be gated on the capability methods (already partly true).

### 4.2 Journey A — Customize a platform ingredient

**Who:** any signed-in member. **Origin:** platform. **Data capability:** none, ever.
**Guidance / material-code capability:** Owner/Admin/Editor only.

**Current**

- Two cards render — guidance (`:91-148`) and material code (`:150-179`) — each with its own
  Save/Cancel.
- The full 5-tab Filament form renders below them, **disabled** (`->disabled($this->isReadOnly())`,
  `:1002`), with no Save bar (`:185`).
- A regulatory summary card above shows INCI / CAS / EC / allergens (`:41-89`).
- The guidance badge indicates *source* (workspace vs platform), not blast radius.

**Recommended**

1. **Replace the disabled form with readable technical data** (F2, kept). The data already exists:
   identity (INCI / CAS / EC / additional identifiers), allergens, documents, Soap chemistry, and
   Composition when the ingredient is a blend. Render as description lists, not as inputs carrying
   `disabled`. Disabled controls still look focusable, still announce as form controls, and still
   imply a save that will never come.
2. Keep the two workspace cards where they are — they are the editable part of this journey.
3. **Label blast radius** (F4): both cards state that changes are shared with everyone in the
   workspace, and that platform data itself cannot be changed here.
4. **Heading and presentation follow capability** (F1): the page reads as a reference page, not
   "Edit ingredient"; no Save bar; nothing that looks actionable but is not.
5. **Fix the heading level** (F10): `<h4>` → `<h2>` at `:43`. Platform branch only; user-owned
   ingredients already render a clean `h1 → h2 → h2`.
6. **Derive the guidance action set from state** (F12) so the primary button stops changing meaning
   between the four branches at `:113-147`.

**Acceptance criteria**

- A read-only visitor and an Editor see the **same layout** on a platform ingredient; only the
  cards' controls differ. *(Stated as "Viewer" before 2026-09-05 — see the naming note in the
  header.)*
- No Save bar on this journey, for any role.
- No disabled `<input>` / `<select>` — technical data renders as text.
- Both cards name the workspace and state that edits are shared.
- Guidance Save/Cancel is the only save affordance on the page.
- Heading order is `h1 → h2 → h2` with no skipped level.
- `IngredientEditorLocalizationTest` still passes.

### 4.3 Journey B — Duplicate a platform ingredient

**Who:** Owner/Admin/Editor. **Result:** a new workspace-owned, **Private** ingredient.

**Current** — `UserIngredientAuthoringService::duplicate()` (`:188`) already exists. Everything
below is **existing behaviour, not an endorsement of it.**

*Copied* (`deepCopyRelations()`, `:294`): `sapProfile`, `fattyAcidEntries`, `components`,
`allergenEntries`, `substanceEntries`, `functionAssignments`, `ifraCertificates` with their
`limits`, and up to 5 aliases; translations and identifiers are synced separately (`:244`).

*Reset:* `public_id`, `catalog_key` (→ `USR…`), owner → workspace, `visibility` → Private,
`requires_admin_review` → false.

*Dropped:* **both images** (`featured_image_*` / `icon_image_*` → null, `:237-240`) and
`info_markdown` (→ null, `:235`); platform guidance is copied in as a workspace override
(`:246-254`).

*Hard blockers:*
- source must be a platform ingredient (`duplicate_platform_only`, `:190`);
- category must be workspace-authorable — **soapmaking alkalis are platform-only**
  (`assertWorkspaceAuthorableCategory`, `:362`);
- **a lipid with no `sapProfile->koh_sap_value` cannot be duplicated at all**
  (`duplicate_soap_profile_required`, `:200`);
- private-ingredient quota applies (`assertCanCreatePrivateIngredientInWorkspace`, `:210`).

**Recommended**

1. **Surface blockers before the action, not after.** A control that can only fail should be
   disabled *with the reason*, or the refusal should name the specific guardrail.
2. **Disclose what duplication carries and what it drops.** A short pre-duplication summary:
   chemistry and compliance data come across; **images and `info_markdown` do not**. Whether
   dropping images *should* remain the behaviour is a product decision this audit does not make —
   the finding is that it must not be **silent**.
3. **Explain trust inheritance here, not only in a warning** (F8, kept, integrated into this
   journey). If the source is trusted, `duplicateSourceData()` (`:545`) writes
   `user_authoring.trusted_koh_sap_value` **and** `trusted_fatty_acid_profile` into the copy, and
   `hasInheritedSoapChemistry()` (`:1400`) reads those keys — so the copy keeps an **editable**
   Soap chemistry tab. If the source is not trusted, the copy has none and cannot be given one.
4. **Name the result:** a Private, workspace-owned ingredient with a `USR` catalog key.

**Acceptance criteria**

- For a source that cannot be duplicated, the UI states which guardrail blocks it **before** the
  user commits.
- After duplicating a trusted lipid, the copy's Soap chemistry tab is present and editable.
- After duplicating an untrusted ingredient, the absence of soap chemistry is explained and the
  trust rule is named.
- Any data dropped by duplication is disclosed at the point of duplication.
- A read-only visitor never sees the duplicate control.

### 4.4 Journey C — Create an ingredient

**Who:** Owner/Admin/Editor. **Result:** a new workspace-owned, Private ingredient.

**Current:** broad editing access across the five tabs, saved from the bottom Save bar.
Restrictions are encountered only when hit:

- soapmaking alkalis cannot be authored at all (`soapmaking_alkalis_platform_only`);
- private-ingredient quota applies;
- **a manually created ingredient can never be trusted for saponification** —
  `.ai/rules/dashboard.md` requires the trust flag to stay hidden, and `hasInheritedSoapChemistry()`
  needs `user_authoring.trusted_koh_sap_value`, which **only duplication can produce**.

**Recommended**

1. **State the saponification restriction up front**, rather than letting someone build an
   ingredient and then find the chemistry tab missing.
2. **Composition follows classification** — see §4.6.
3. **Keep explicit save.** Do not introduce autosave here for the sake of uniformity; §4.7 covers
   unsaved-state handling independently of save timing.
4. **Decouple carrier-oil detection from the `Lipids` category** (F9, `blade.php:4`) so the warning
   does not silently stop firing if the taxonomy gains a sibling.

**Acceptance criteria**

- A user creating a lipid learns, before saving, that soap chemistry will not be available and why.
- The trust flag is never exposed as a control.
- Non-workspace-authorable categories are excluded from the picker, or the refusal is explained.
- Quota exhaustion produces an actionable message, not a generic failure.

### 4.5 Read-only visitors, across all three journeys

> **Correction (2026-09-05) — this section was titled "Viewers".** The `Viewer` role is
> **vestigial**: `WorkspaceMemberRole::Viewer` (`app/Enums/WorkspaceMemberRole.php:10`) is declared
> and referenced nowhere, nothing can assign it, and no such member exists in the database. Full
> evidence in F1. The section is kept because the capability model still has to be right and
> `Viewer` is plainly meant to be wired up later — but the reachable read-only case today is the
> one described below, and it is not a Viewer.

**Current:** `isEditableBy()` (Ingredient.php:392) admits only Owner, Admin and Editor, and both
workspace capability methods require Owner/Admin/Editor — so a user with **no membership** in the
owning workspace has **no capability anywhere**. The UI does not consistently say so:

- on a **platform** ingredient the Save bar is already hidden (`:185`), so the page is coherent —
  and this is the case that essentially every read-only visitor hits today;
- on a **public, workspace-owned** ingredient opened by someone who is **not a member** of that
  workspace, the Save bar **is** rendered, while the form is disabled (`:1002`) and `save()` aborts
  with a bare 403 (`:210`). **This is F1.**

**Recommended:** heading, controls and actions all derive from the same capability checks, so a
read-only visitor never sees an enabled action. Concretely: gate `:185` on the ingredient-data
capability rather than on origin (§4.1). Do this for **the role set that exists now**, not for a
hypothetical one — the one change fixes both cases above.

**Acceptance criteria**

- A non-member opening a public workspace-owned ingredient sees no Save bar, a read-only heading,
  and no enabled control.
- No reachable path produces a 403 from `save()`.
- A read-only visitor on a platform ingredient sees the two workspace cards read-only, with the
  same blast-radius labelling.
- *If `Viewer` is later wired up:* the same criteria apply unchanged, with no further gate edits.

### 4.6 Composition visibility (F5)

**Current:** visibility is *already* classification-driven —
`->visible(fn (Get $get): bool => $get('ingredient_structure') === 'blend')` (`:749`). The editor
therefore already hides Composition for a single ingredient such as coconut oil. **That part is
correct and needs no change.**

Two things remain open. **Neither has been reproduced in a browser** — both are traced from source
and must be confirmed before any fix is written:

1. **Stale tab after a type switch.** `persistTabInQueryString('ingredient-tab')` (`:646`) keeps the
   active tab in the URL, while visibility is computed from **live form state**. Switching a blend
   to single hides Composition but leaves the URL pointing at it; on reload Filament may be asked to
   activate a tab that is no longer visible. **Unverified — reproduce first.**
2. **What happens to existing constituents.** Hiding a tab does not clear state, so constituent rows
   persist while invisible. Whether they should be retained (so switching back restores them),
   discarded, or parked behind an explicit *"N constituents are not applied while this is a single
   ingredient"* notice is a **product decision this audit does not make**. Recommended default:
   **retain and disclose** — do not silently discard entered data, and do not silently keep it
   invisible either.

**Acceptance criteria**

- Composition is never shown when `ingredient_structure !== 'blend'`.
- Fatty-acid profile stays under **Soap chemistry**, not Composition.
- Switching type never *silently* loses constituent entries — whichever resolution is chosen, the UI
  says what happened to them.
- After a type switch the URL never resolves to a hidden tab *(pending reproduction of (1))*.

### 4.7 Unsaved changes (F6, F11)

**Recommended:** reuse the existing pattern **in full**, not just its indicator.
`resources/js/recipe-workbench/component.js` provides `dirtyStateRegistry.blocksNavigation()`
(`:1109`) and a `beforeunload` listener (`:1138`); the workbench wires it through
`recipeContentAutosave` (`instructions-media.blade.php:8-34`).

1. **Indicator** on every journey with an explicit save — guidance, material code, ingredient form.
   Required by CLAUDE.md's *"Clear unsaved state indicator always visible"*.
2. **Navigation protection** — the half that is missing. An indicator tells you; only
   `blocksNavigation()` and `beforeunload` stop the edit being lost.
3. **Cancel confirmation only when it would discard changes** — confirm if dirty, stay silent if
   clean. `blade.php:120` currently discards with no confirmation at all, while its sibling `:131`
   already carries `wire:confirm`.

No autosave is introduced by this section.

**Acceptance criteria**

- Editing any field flips a visible unsaved indicator.
- Navigating away with unsaved changes prompts; with no changes it does not.
- Cancel prompts when dirty and does not prompt when clean.
- Saving returns the indicator to a saved state.

### 4.8 Revised priorities

1. **Permission consistency** (§4.1, §4.5) — gate the Save bar on capability, not origin; derive
   heading and controls from the same checks. Removes the only reachable 403.
2. **Lost-edit protection** (§4.7) — indicator **plus** navigation blocking; conditional cancel
   confirmation.
3. **Composition** (§4.6) — classification already drives visibility; reproduce the stale-tab case
   and settle constituent retention with the owner.
4. **Platform reference presentation** (§4.2) — replace disabled controls with readable technical
   data. Re-scoped by §7.1: the WordPress rule does **not** limit how much ingredient data may be
   shown, so this is about *how* it renders, not how much.
5. **Duplication and creation clarity** (§4.3, §4.4) — surface blockers, disclose copied/dropped
   data, state the saponification restriction up front.
6. **Blast-radius labelling** (F4) and the minor repairs — F10 heading level, F12 guidance action
   set, F13 breadcrumb `title`, F9 carrier-oil coupling.

**Dropped**

- **F3** — withdrawn; the cards and the Save bar are mutually exclusive (§7.1).
- **F7** — no escalation trigger applies (§6.2); 36×36px clears the 24×24px floor and both links
  carry `aria-label` and `title`.
- **F8 first clause** — the hidden trust flag is mandated by `.ai/rules/dashboard.md`. The second
  clause is kept and folded into journeys B and C.

**Not prescribed:** no specific number of new booleans. §4.1 reuses the three capability methods
that already exist and changes two Blade gates.

### 4.9 Unverified — must be confirmed before any of this is built

- **F1's read-only path** — traced statically, never reproduced in a browser. Note the actor is
  **not** a Viewer: no such role can be created (F1, §4.5). Reproduce it as a **non-member opening
  a public, workspace-owned ingredient**.
- **§4.6(1) stale tab after a type switch** — traced, not reproduced.
- **§4.6(2) constituent retention** — a product decision, not yet taken.
- **Images dropped on duplication** — observed in code, **not** endorsed as desirable.

---

## 5. Open questions for the owner

*Updated 2026-09-05 to reflect what the code investigation settled and what the §4 rewrite raised.*

**Still open**

1. **Is `WorkspaceMemberRole::Viewer` meant to be wired up?** It is declared
   (`WorkspaceMemberRole.php:10`) and referenced nowhere: no invite flow, no member UI, and no
   capability check names it. If multi-member workspaces are planned, §4.1's capability model
   already covers them with no further change; if they are not, the case should be **deleted**
   rather than left as a dead enum value. Separately: should a **non-member** reach a public
   workspace-owned ingredient's editor at all, or be routed to a read-only detail page? That
   decides whether F1 is a gate fix or a redirect. **Both branches are blocked on reproducing F1 in
   a browser** — it is still traced statically only.
2. Is the **material code** workspace-scoped *only* for platform ingredients by design?
   `canEditWorkspaceMaterialCode()` (`:1343`) requires `owner_type === null`, so workspace-owned
   ingredients have no material code by construction. Whether that is intended is not recorded.
3. **What should happen to existing constituents when an ingredient switches type?** Retain,
   discard, or park behind a notice? §4.6(2) recommends *retain and disclose* as a default but this
   is a product decision, not one this audit takes.
4. **Should duplication keep dropping images?** `duplicate()` nulls `featured_image_*` and
   `icon_image_*` (`:237-240`). That is observed behaviour, **not** an endorsement — is it wanted?
5. **Should a lipid with no SAP value remain impossible to duplicate?** The
   `duplicate_soap_profile_required` blocker (`:200`) is severe: it makes some platform ingredients
   simply not duplicable. Is that deliberate, or should duplication be allowed without chemistry?

**Settled by investigation (no longer open)**

- *Should guidance and material code autosave?* — **No.** Keep explicit save; do not introduce
  autosave for uniformity (§4.4, §4.7). Unsaved-state handling is orthogonal to save timing.
- *Does the WordPress rule cap how much ingredient data may be shown?* — **No.** `views.md` covers
  public marketing, editorial and long-form documentation, not in-app ingredient domain data
  (§7.1). How the data is *organised* is still a design choice, but there is no rule against
  showing it.
- *Should the `is_soap_saponification_trusted` flag be surfaced?* — **No.** `.ai/rules/dashboard.md`
  requires it hidden (§7.1).
- *Are the customisation cards and the Save bar competing?* — **No.** They are mutually exclusive
  (§7.1); F3 is withdrawn.

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
  literacy alone, and the two are mutually exclusive (`@if ($canEdit)`), so the eye is a
  read-only visitor's only entry point. Since nothing escalates, the proposed fix (visible text labels) is ladder
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

**Not verified:** F1 was traced statically and never reproduced in a browser — it remains the one
finding that should be confirmed against a live session before it is acted on. *(This said "with a
real Viewer-role account" until 2026-09-05; no such account can exist — §7.5. The reproduction is
now the non-member case described in F1.)* No browser check was performed (the Vite dev server is
down; see memory).

### 6.6 Verdict

**Superseded 2026-09-05 — see §4.8 for the current priorities.** Retained for the record.

The verdict below was written before the journey-based rewrite and is now partly stale:
F5 no longer stands as written (§7.1 — classification was already correct), and F6's "copy the
indicator" understated the pattern, which also carries navigation blocking (§7.1). The F2
delete-first recommendation survives, but its justification no longer rests on the WordPress rule.

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
2. a **workspace-owned** ingredient the user may not edit — today that means a **non-member**; the
   `Viewer` role I originally named here cannot exist (§7.5) — *everything* is read-only and there
   are no cards.

A single boolean cannot express "read-only form **plus** editable cards" versus "read-only
everything". If §4 is organised by journey, the view needs **two distinct signals** — e.g.
`isPlatformReference()` and `canEditIngredient()` — rather than one widened `isReadOnly()`. Otherwise
read-only visitors and platform-customisers keep rendering identically, which is exactly the
permission inconsistency the brief ranks first.

### 7.4 Verdict

All six corrections are sound and accepted. Three refinements (§7.2) and one change of shape (§7.3)
were folded in when §4 was rewritten.

**Done 2026-09-05:**

- **§4 rewritten** around the three journeys (§4.2 customize, §4.3 duplicate, §4.4 create) plus
  read-only-visitor behaviour (§4.5 — retitled from "Viewers", see §7.5), each with expected
  behaviour and acceptance criteria, and revised priorities (§4.8).
- **§7.3 resolved without new booleans.** Rather than prescribe a number of flags, §4.1 separates
  **origin** from **capability** and reuses the three checks that already exist and are already
  public: `Ingredient::isEditableBy()`, `canEditWorkspaceGuidance()` and
  `canEditWorkspaceMaterialCode()`. Only two Blade gates change.
- **§6.7's third bullet withdrawn** (the WordPress rule does not cap in-app technical data).
- **F3 withdrawn** as a finding, with the surviving complaint folded into F2.
- **F1, F5 and F8 in §2 updated** to match: F1 no longer prescribes calling the private
  `isReadOnly()`; F5 no longer calls correct classification a bug; F8's trust rules are integrated
  into journeys B and C.
- **§5 open questions refreshed** — five still open, four settled by investigation.
- **§6.6 marked superseded** by §4.8.

**Still analysis-only** — no application code changed. Two items remain unverified and are recorded
in §4.9: F1's read-only path, and the stale-tab behaviour in §4.6(1).

### 7.5 Correction to the brief's Viewer premise (2026-09-05)

The brief instructs: *"Apply Viewer permissions across these journeys."* I applied them as asked,
and **the premise is mistaken — but the instruction was still worth following.**

`WorkspaceMemberRole::Viewer` (`app/Enums/WorkspaceMemberRole.php:10`) is declared and then
referenced **nowhere**: no invite flow, no member-management UI in Filament or Livewire, and no
capability check names it. Every check in the codebase admits only Owner, Admin and Editor. The
only code path that creates a membership row assigns `Owner` (`SettingsIndex.php:167`);
`WorkspaceMemberFactory` defaults to `Editor`; the local database holds **2 rows, both `owner`**.
A Viewer cannot be created, so F1 could never have been reproduced with one — my §4.9 note asking
for a "real Viewer account" was asking for something that does not exist.

What the brief's instruction got right, though, is the **shape** of the fix. Designing the journeys
around *capability* rather than *origin* is what makes the page correct for the read-only case that
**is** reachable — a non-member opening a public, workspace-owned ingredient — and it means that if
`Viewer` is ever wired up, no gate has to change. The permission work in §4.1 and §4.5 stands; only
the actor's name was wrong.

This is the second time in this audit that a finding named an actor rather than a condition. The
lesson carried forward: **state the condition the code actually evaluates**
(`workspaceRoleFor()` ∉ [Owner, Admin, Editor]), not a role label that may or may not be reachable.
