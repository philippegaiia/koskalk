# Soap & Cosmetic Recipe App — Final Build Plan

## 1. Product direction

This is a **professional formulation workspace**, not a consumer app and not a social platform.

The UX target is:
- **SoapCalc-level efficiency**
- better clarity
- better data structure
- better compliance support

Do **not** overdesign this product.
People use ugly tools if they are fast, dense, and trustworthy.
The goal is to be **clearer than SoapCalc, not prettier than necessary**.

---

## 2. Locked decisions

### 2.1 Core stack
- Laravel
- PostgreSQL
- Filament for admin only
- Blade + Livewire + Alpine for the user-facing product
- `spatie/laravel-permission` from the start
- Filament Shield for the Filament admin panel only

### 2.2 Product structure
- **Formulation page** = main workbench
- **Compliance page** = separate deliberate step
- **Dashboard** = recipe/custom ingredient/account management
- **Filament admin** = stewardship of platform data

### 2.3 Runtime model
- Alpine manages the **live draft state** in the browser
- field changes trigger **instant local recalculation only**
- **no database write on field change**
- Livewire/Laravel act only on **explicit actions**

Explicit actions:
- Save draft
- Save as new version
- Run compliance
- Export
- Duplicate

### 2.4 Save model

Two distinct save actions exist:

- **Save draft** — updates the current `recipe_version` record in place while it remains in draft state. Does not create a new version. Replaces the working draft. This is what the main Save button does.
- **Save as new version** — explicitly creates a new immutable `recipe_version` record. The previous version is preserved and never overwritten. This is the audit trail.

Unsaved changes must be indicated visibly in the top bar at all times. The app must warn before navigation away from a page with unsaved changes.

> **Implementation note for Sprint 1:** Livewire's internal navigation and browser-level navigation (back button, refresh, tab close) handle `beforeunload` differently. This must be explicitly solved in Sprint 1 — not assumed to work and not discovered mid-build. Both navigation paths must be covered.

### 2.5 Data policy
- users **cannot** create new saponifiable oils that drive soap math
- users **can** create private custom additives
- all compliance results are tied to a specific recipe version
- versioning is mandatory:
  - ingredient versions
  - recipe versions
  - compliance runs
  - snapshots

---

## 3. Page map

### Public
- `/`
- `/login`
- `/register`

### Dashboard
- `/dashboard`
- `/dashboard/recipes`
- `/dashboard/recipes/new`
- `/dashboard/ingredients`
- `/dashboard/account`

### Formulation
- `/formulas/{id}`
- `/formulas/{id}/compliance`
- `/formulas/{id}/export`

### Admin
- `/admin`

---

## 4. Formulation page

### Goal
Replicate the speed and logic of SoapCalc while improving clarity, structure, and support for full formulas.

### Layout
One page only. No wizard. No tabs for core work.

#### Top bar
- formula name (inline editable)
- product family
- batch size
- unit
- unsaved/saved state indicator
- Save
- Save as new version
- Duplicate
- Archive
- Run compliance

#### Main workspace
Two-column desktop layout.

**Left panel**
- ingredient search (instant, Alpine-driven, no server call)
- category filter tabs
- ingredient results list
- click-to-add interaction

**Right panel**
- formula table (ingredient rows with % and weight columns)
- percent ↔ weight toggle
- totals row
- lye block (soap recipes only)
- phase headers (cosmetic recipes only)
- soap properties panel (soap recipes only)

#### Footer / sticky action area
- Run compliance
- Duplicate
- Share
- Export (premium gated)

### Behaviour rules
- ingredient search is instant — searches pre-loaded ingredient list, no server call
- clicking an ingredient adds it immediately to local Alpine draft state
- changing batch size rescales all weights instantly
- percent ↔ weight toggling is instant
- soap properties update instantly
- no server round-trip for live soap math
- no DB write on field change
- unsaved changes indicator always visible
- warn before leaving page with unsaved changes (see implementation note in section 2.4)

### What is loaded into the formulation page
Only the minimum data needed for fast formulation:
- ingredient id, display name, kind
- SAP values (for saponifiable oils)
- fatty acid / soap property inputs
- minimal display metadata

**Do not preload compliance data** (full allergen profiles, IFRA limits, restriction rules) into the formulation workspace. These are large datasets needed only on the compliance page. Load them there, not here.

### SoapCalc parity checklist
- Add oil by clicking (not typing into a field)
- Edit percentage directly in table row
- Edit weight directly in table row
- Switch between percent and weight mode
- Change batch size and have all weights update
- See INS and Iodine values live
- See fatty acid profile live, with only present acids shown by default
- Support a normalized fatty-acid catalog and per-ingredient-version fatty-acid rows
- Expose grouped fatty-acid buckets and superfat behavior outputs in the calculation engine
- Keep legacy soap-quality outputs during transition while introducing compact Koskalk qualities
- Present only a small default quality set first, with lather summary and advanced disclosure for deeper metrics
- Drive live workbench fatty-acid, lye, and quality displays from a backend preview payload instead of duplicated frontend chemistry formulas
- Tune Koskalk quality formulas against benchmark archetypes (castile, high coconut, balanced palm/olive/coconut, high shea, tallow-style)
- Add short explanation text for quality cards and warning flags so the UI teaches tradeoffs instead of showing bare numbers only
- Compare the current workbench against the loaded saved baseline with deltas on totals and key quality metrics
- Improve admin data entry so normalized fatty-acid rows are edited directly on ingredient versions, with SAP-profile fatty-acid columns treated as legacy fallback only
- NaOH and KOH calculated simultaneously
- Water mode options: percent of oils / lye:water ratio / lye concentration
- Superfat / lye discount

---

## 5. Compliance page

### Goal
A deliberate review step after formulation, not a live warning wall during editing.

### Output sections
- summary status banner (pass / warnings / blocked)
- allergen report
- IFRA evaluation
- restriction checks
- generated INCI list

### Behaviour rules
- always run against a specific `recipe_version` — version used is shown clearly
- each run creates a new `compliance_run` record — never overwrite
- incomplete or user-entered allergen profiles must be flagged visibly
- the report must say clearly what it knows and what it could not verify
- INCI list is generated from this run context and stored in `inci_outputs`

---

## 6. Dashboard

Dashboard is **not Filament**. It is a standard Blade + Livewire user-facing area.

### Recipes
- list with formula name, product family, last edited, compliance status
- filter by type / family / compliance status
- create new recipe
- duplicate / archive / open formulation

### Custom ingredients
- list of user's private additives
- show allergen and IFRA data completeness per ingredient
- add / edit / archive
- cannot create saponifiable oils — blocked at UI level with clear explanation

### Account
- profile and preferences
- subscription tier and usage counters
- upgrade prompts

---

## 7. Admin panel (Filament)

Filament is for **data stewardship**, not just CRUD.

### Modules required in v1–v2
- ingredient catalog + versions
- SAP profiles
- allergen catalog + ingredient allergen entries
- IFRA categories + product family mapping
- rule sets + jurisdictions
- restriction rules
- user management
- subscriptions / plans / quotas

### Required capabilities
- create and edit ingredient versions
- mark current active version
- view full version history
- attach source documents (TDS, SDS, IFRA certificates)
- activate / deactivate rules
- audit data changes
- archive records safely — no destructive deletes

**Rule:** If it affects trust in the data engine, it belongs in admin.

---

## 8. Design system guidance

This is a tool, not a lifestyle brand.

### Visual direction
- desktop-first
- dense layout
- minimal empty space
- very limited card use
- table-first UI
- neutral palette with one accent color
- subtle borders and dividers
- hierarchy through typography and spacing, not decoration
- sticky top bar if helpful
- sticky totals or soap block if it improves productivity

### Interaction direction
- row hover states
- inline editing
- obvious selected row state
- keyboard-friendly where possible
- clear save state at all times
- no ornamental animations
- no giant paddings
- no oversized dashboard cards

### Typography
- neutral sans-serif
- readable but compact
- prioritize scan speed over style

---

## 9. Data model principles

### Mandatory entities

**Platform-owned (admin-controlled)**
- `ingredients`
- `ingredient_versions`
- `ingredient_sap_profiles`
- `allergen_catalog`
- `ingredient_allergen_entries`
- `ifra_categories`
- `product_families`
- `ingredient_ifra_limits`
- `jurisdictions`
- `rule_sets`
- `ingredient_restriction_rules`

**Tenant-aware (user/workspace-owned)**
- `workspaces`
- `workspace_members`
- `recipes`
- `recipe_versions`
- `recipe_phases`
- `recipe_items`
- `recipe_snapshots`
- `compliance_runs`
- `compliance_run_allergens`
- `compliance_run_ifra_results`
- `compliance_run_restriction_results`
- `inci_outputs`
- `share_links`
- `subscriptions`
- `usage_counters`

### Core data rules
- `recipe_items` stores both `ingredient_id` and `ingredient_version_id`
- compliance runs are append-only — never overwrite
- recipe versions are immutable once snapshotted
- user-created ingredients can be partial — system must surface incompleteness clearly
- `product_family_id` drives IFRA mapping, restriction applicability, and calculation defaults — never hard-code these as conditionals

---

## 10. Service layer

Keep Livewire components and controllers thin. Logic lives in services.

**Sprint 1–2 (required)**
- `SoapCalculationService`
- `RecipeNormalizationService`
- `AllergenCalculationService`
- `InciGenerationService`
- `ComplianceRunService`

**Sprint 3–4 (planned)**
- `IfraEvaluationService`
- `RestrictionEvaluationService`
- `CosmeticFormulaService`
- `RecipeSnapshotService`

**Sprint 6 (premium)**
- `ExportBatchSheetService`

---

## 11. Sprint plan

### Sprint 1 — Soap calculator foundation

**Goal:** A working soap calculator with SoapCalc-level core workflow.

Migrations:
- `users`
- `workspaces`, `workspace_members` — **schema only, no UX yet. Must exist now so ownership is correct from the first tenant-aware migration.**
- `product_families`
- `ingredients`, `ingredient_versions`, `ingredient_sap_profiles`
- `recipes`, `recipe_versions`, `recipe_items`

Services: `SoapCalculationService`, `RecipeNormalizationService`

Frontend:
- formulation page (Blade + Livewire)
- instant ingredient search (Alpine, pre-loaded data)
- click-to-add
- live soap calculation (Alpine, client-side only)
- lye block
- soap properties panel
- explicit save + save-as-new-version flow
- unsaved changes indicator
- navigation guard (both Livewire and browser navigation — see section 2.4)

Admin (Filament):
- ingredient catalog
- ingredient versions
- SAP profile management

**Done when:** A professional soap maker can reproduce their core SoapCalc workflow inside this app.

---

### Sprint 2 — Allergen engine + compliance page

**Goal:** First serious differentiator. The feature SoapCalc does not have.

Migrations:
- `allergen_catalog`
- `ingredient_allergen_entries`
- `jurisdictions`, `rule_sets`
- `compliance_runs`, `compliance_run_allergens`
- `inci_outputs`

Services: `AllergenCalculationService`, `InciGenerationService`, `ComplianceRunService`

Frontend:
- compliance page (`/formulas/{id}/compliance`)
- Run compliance action on formulation page footer
- allergen breakdown table
- INCI output block with copy-to-clipboard

Admin: allergen catalog, allergen entries, EU rule set only — do not build multi-region yet

**Done when:** A formula with EO/FO produces a correct allergen breakdown and a generated INCI list.

---

### Sprint 3 — IFRA evaluation layer

**Goal:** Practical advisory IFRA output on the compliance page.

Migrations:
- `ifra_categories`
- `product_family_ifra_category_map`
- `ingredient_ifra_limits`
- `compliance_run_ifra_results`

Services: `IfraEvaluationService`

Frontend: IFRA section on compliance page

Admin: IFRA categories, product family mapping, IFRA limits per ingredient version

**Done when:** Compliance run shows pass / warn / fail / no-data per fragrance ingredient against IFRA.

---

### Sprint 4 — Cosmetic recipe structure + restriction rules

**Goal:** Phase-based cosmetics fully supported. Curated restriction checks live.

Migrations:
- `recipe_phases`
- `ingredient_restriction_rules`
- `compliance_run_restriction_results`
- `recipe_snapshots`

Services: `CosmeticFormulaService`, `RestrictionEvaluationService`, `RecipeSnapshotService`

Frontend:
- phase-based layout on formulation page (cosmetic recipes)
- restriction section on compliance page
- snapshot created on each compliance run

**Done when:** A phase-based cosmetic formula can be built and run through a full compliance check with snapshot.

---

### Sprint 5 — Dashboard, sharing, subscriptions

**Goal:** Usable end-to-end SaaS product.

Migrations:
- `share_links`
- `subscriptions`, `usage_counters`

Frontend:
- `/dashboard` — full recipe library, custom ingredients, account
- workspace member management (activate dormant tables)
- subscription gating (recipe count limit, export gate)
- upgrade prompts

Admin: user management, subscription plan management, usage monitoring

**Done when:** A free user hits their recipe limit and sees an upgrade prompt. A premium user can generate a share link and access export.

---

### Sprint 6 — Premium professional exports

**Goal:** Features that justify the premium subscription.

Migrations: `export_jobs`, `recipe_cost_snapshots`

Services: `ExportBatchSheetService`

Frontend:
- PDF batch sheet export with user logo
- cost breakdown per batch / per unit
- basic ingredient cost input

**Done when:** A premium user exports a branded, professional-grade batch sheet PDF.

---

### Sprint F (future — not scheduled)
- inventory and stock movements
- supplier ingredient links and document parsing
- public formula library and community discovery
- toxicologist collaboration portal
- multi-region rule engine (UK, US MoCRA, GCC)
- verified professional profiles
- marketplace

Build these when paying users ask for them. Not before.

---

## 12. Non-negotiable technical rules

1. No DB write on field change
2. No server round-trip for live soap math
3. Every tenant-aware model gets a global scope and a policy before its first controller is written — no exceptions
4. Saponifiable oils are admin-controlled only — blocked at service layer, not just UI
5. Private custom additives are user-controlled and private by default
6. Compliance runs are append-only — never overwrite
7. Partial compliance data must be surfaced visibly — never silently omitted
8. `product_family_id` drives IFRA and restriction applicability — never hard-code as conditionals
9. Filament is admin-only — no user-facing functionality leaks into Filament
10. EU allergen list only for v1 — do not build a multi-region engine before demand is proven

---

## 13. What not to build yet

- public formula feed or community discovery
- supplier auto-import or TDS parsing
- full inventory / MRP system
- toxicologist collaboration portal
- mobile app
- multi-region legal engine beyond EU
- approval workflows

---

## 14. Tenancy / Ownership model

### Decision
Adopt lightweight tenancy from day one: single database, row-level ownership, Laravel policies for access control.

**Filament's built-in tenant system is not used.** Filament's tenancy is designed for the "user switches between multiple organisations" pattern. That is not this product in v1. Ownership is handled entirely through Laravel global scopes and policies on tenant-aware models.

Do **not** implement:
- database-per-tenant
- schema-per-tenant
- subdomain tenancy
- Filament's built-in tenant panel switching
- hard enterprise multi-tenant infrastructure in v1

### Ownership classes

**Platform-owned data** — global, admin-controlled, users never own or directly modify:
- ingredients (verified global catalog)
- ingredient_versions for global ingredients
- ingredient_sap_profiles
- allergen_catalog
- ifra_categories
- jurisdictions
- rule_sets
- ingredient_ifra_limits
- ingredient_restriction_rules

**User- or workspace-owned data** — tenancy-aware from day one:
- recipes, recipe_versions, recipe_phases, recipe_items, recipe_snapshots
- compliance_runs and all compliance run result tables
- inci_outputs
- private custom ingredients and their versions
- share_links
- subscriptions / usage counters

### Core schema rule for tenant-aware tables

```
owner_type       enum: user, workspace
owner_id         unsignedBigInteger
workspace_id     FK → workspaces (nullable)
visibility       enum: private, workspace, shared_link, public
```

Do not hard-code ownership as `user_id` everywhere. Records must be ownable by either an individual user or a workspace.

### Workspace tables (Sprint 1 — schema only)

```
workspaces:         id, name, slug, owner_user_id, timestamps
workspace_members:  id, workspace_id, user_id, role (owner/admin/editor/viewer), timestamps
```

Create in Sprint 1. No UX until Sprint 5.

### The non-negotiable implementation rule

**Every tenant-aware model gets an `OwnedByCurrentTenant` global scope and a Laravel Policy before its first controller or Livewire component is written. No exceptions.**

Without this, data leakage between users is not a theoretical risk — it is a near-certainty when building alone under deadline pressure.

### Access policy rules

- **Global catalog data:** readable by users where appropriate, writable only by admin via Filament
- **Private user data:** visible only to owner, optionally shareable via explicit share links, never leaks into global catalog
- **Workspace data:** visible according to workspace membership role, editable only by authorized members
- **Share links:** explicit, revocable, token-based, limited scope (view or duplicate), never imply broader tenant access

### v1 active behaviour
In v1 most users operate as single-user owners. Workspaces exist in schema and policies. Team workflows remain limited until Sprint 5. This is intentional.

---

## 15. Authorization / Permissions

### Decision
- Use `spatie/laravel-permission` from the start
- Use Filament Shield for the Filament admin panel only
- Laravel policies are the source of truth for business-domain access control in the user-facing app
- Do not rely on Shield for recipe / private-ingredient / workspace ownership logic
- Do not enable Spatie teams in v1 unless workspace-scoped roles are explicitly required now
- If teams will be used later, enable them before running permission migrations — retrofitting is painful

### Admin roles (suggested)
- `super_admin`
- `data_steward`
- `compliance_operator`
- `support_admin`

### Business-domain authorization uses policies for:
- recipe access
- private ingredient access
- workspace membership checks
- share link scope
- export access

### Important rule
Permissions and roles are not a replacement for ownership policies.
Use roles for admin/operator capabilities.
Use ownership + policies for tenant/user/workspace data.

---

## 16. Final instruction to coding agent

Build for:
- **speed** — the formulation page must feel instant
- **clarity** — a professional must understand the output without a manual
- **trust** — data integrity is non-negotiable; bad SAP values cause harm
- **auditability** — every calculation result must be traceable to versioned source data

Do not optimise for:
- visual flourish
- speculative enterprise complexity
- generalised regulation engines before real demand exists

The formulation page should feel like a serious desk tool.
The compliance page should feel like a deliberate review report.
The admin panel should feel like data stewardship.
