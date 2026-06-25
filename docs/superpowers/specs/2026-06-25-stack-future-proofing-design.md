# Stack Future-Proofing — Keep the Bet, Remove Filament From the User-Facing UI

- **Date:** 2026-06-25
- **Status:** Approved in brainstorming; first implementation plan ready
- **Decision owner:** Philippe
- **Type:** Architecture decision record (ADR) + scoped migration

## 1. Context

A pressure-test of whether the current stack is "future-proof," whether Filament earns its place given it is admin-only, and which stack would be chosen if the app were redeveloped today.

Operating context established during brainstorming:

- **Small team / occasional collaborators** (contractor-rampable matters; no large engineering org).
- **Pressure-test, not a rebuild** — validate the bet was right; produce an evaluation, not a re-platform.
- **Server-driven now, but want the option** to go more client-side / multi-surface later without being painted into a corner.

A concrete finding reshaped the discussion: Filament is **not** actually admin-only in the code. The public Livewire components use Filament's standalone form/table/action builders, which creates a visible design-language seam for end users. This spec resolves both the strategic stack question and that seam.

## 2. Decisions (summary)

1. **Keep the stack.** Laravel 13 / PHP 8.5 / PostgreSQL, Livewire + Alpine + Tailwind + Blade public UI, Filament admin. The bet is sound and contractor-friendly.
2. **Remove Filament from the user-facing UI** (custom Livewire/Blade forms & tables). Filament returns to **admin-only**, matching the rule already documented in `CLAUDE.md`.
3. **Buy future-proofing through architectural discipline, not re-platforming.** Protect the payload/service boundary so a later move to Inertia/React stays possible and cheap. Adopt Inertia **only** when concrete demand appears.

These three reinforce each other: removing Filament from the public UI fixes design consistency, restores the documented architecture, **and** shrinks Filament's upgrade blast radius — one migration, three wins.

## 3. Current stack (verified)

| Layer | Version | Constraint |
|---|---|---|
| PHP | 8.5 (runtime) | `^8.3` |
| Laravel | 13.15.0 | `^13.0` (floats across 13.x) |
| Filament | 5.6.7 | `^5.0` (floats across 5.x) |
| Livewire | 4.3.1 | **transitive** — pulled in by Filament |
| Tailwind | 4.x | `^4.0` |
| Vite | 8.x | `^8.0` |
| DB | PostgreSQL (target), SQLite (tests) | — |
| Tests | Pest 4.x | — |

**Note on the version matrix:** Laravel 13, Livewire 4, Filament 5, Vite 8, Tailwind 4 are all first-generation majors, and Livewire's version is governed by Filament (it is not a direct dependency). They move **together**. A future Filament 6 (likely alongside a Laravel 14) is a single joint upgrade effort, not independent ones.

## 4. Verdict per layer

### Backend (Laravel 13 / PHP 8.5 / PostgreSQL) — keep
Mainstream, long-lived, excellent for this domain, fast for a contractor to ramp on. No change.

### Service layer (~40 services) — keep, and double down
This is the **optionality engine**. Example: `IngredientEditor::save()` hands form state straight to `UserIngredientAuthoringService::create()/update()`, and `mount()` fills the form from `formData()`/`blankState()`. The form is **presentation**; the domain is portable. Guardrail going forward: keep domain logic in services, keep services JSON-serializable, keep Livewire components thin.

### Filament (admin) — keep, admin-only
16 stewardship CRUD resources (ingredients, SAP profiles, IFRA, regulatory regimes, allergens, fatty acids, substances, product types, plans) as pure `Resource/Table/Form/Pages` scaffolding. No user-facing logic. This is Filament's textbook sweet spot. **After this migration, Filament touches only admin data stewardship** — restoring the `CLAUDE.md` rule literally.

### Public UI (Livewire + Alpine + Tailwind + Blade) — keep, decouple from Filament
The crown jewel, `RecipeWorkbench`, is already the **most portable** surface: Alpine + Blade over server-built JSON preview payloads (`RecipeWorkbenchPreviewService`, `serializeDraft()`, `serializeCosting()`), domain in services. The Filament-form editors are the **least** portable surface — which is exactly what decision 2 fixes.

## 5. Decision A — Remove Filament from the user-facing UI

### Why
A professional tool whose thesis is *clinical, trustworthy, clear* cannot make a user **feel** two design languages. Filament's default chrome reads as "admin dashboard"; it does not match the restrained professional design system. Deep-theming Filament to hide this is a known trap: it is fragile across versions (every bump can break view overrides) and never fully stops looking like Filament — so it would **increase** the upgrade-coupling risk this exercise is trying to reduce. Replacing the public surface with custom components is the move that helps consistency, architecture, **and** upgrade safety at once.

### Scope (verified)
Four public components lean on Filament, plus a light touch on the workbench:

| Surface | Filament usage | Replacement cost |
|---|---|---|
| `IngredientsIndex`, `PackagingItemsIndex` | Filament **Tables** | **Cheap** — custom Livewire table |
| `PackagingItemEditor` | Filament **Forms** | Medium |
| `IngredientEditor` | Filament **Forms** (Tabs, Repeaters w/ `createOptionForm`, `FileUpload` + image editor, conditional tabs, derived fields) | **The investment** |
| `RecipeWorkbench` | Light (`InteractsWithForms`/`Actions`/`Schema`); bulk is Alpine + Blade | Small (verify extent in planning) |

### Approach — presentation-only migration
Each component keeps its **existing service contract unchanged** (`mount()` fills from `formData()`/`blankState()`; `save()`/actions call the same service methods). The migration swaps the Filament `Schema`/`Table` definition for custom Blade wired to the same contract. Domain logic does not move; **service (unit) tests stay green**, and the per-component feature tests are rewritten to drive the custom form (the form definition is what changes). This is the optionality discipline paying off for a concrete benefit today.

### Reusable component kit (one-time investment)
Build a small set of custom Blade/Alpine components matching the design system, then reuse across all four surfaces and all future user-facing forms:

- Text input, textarea, select (searchable), toggle, file upload (with the existing `MediaStorage` fit/crop pipeline).
- **Repeater** (Alpine-powered) — the hard one; used for components, fatty-acid profile, allergens, IFRA limits. Modeled on the repeater patterns already present in the workbench.
- Inline "create-option" affordance for selects (mirrors Filament's `createOptionForm`).
- A table component for the two indexes (sort, search, inline text input, row actions).

This kit is the asset; the four migrations are its first consumers.

### Sequencing (priority adjustable)
1. **Packaging catalog vertical slice** - migrate `PackagingItemsIndex` and `PackagingItemEditor` together so one visible flow stops mixing design languages.
2. **`IngredientsIndex`** - reuse the table pattern after it is proven on packaging.
3. **`IngredientEditor`** - build the reusable field/repeater kit only when the larger ingredient form actually needs it.
4. **`RecipeWorkbench`** residual Filament cleanup.

> Priority note: ingredient surfaces are where users spend the most time, so the `IngredientEditor` may be promoted even though it is the largest effort. Sequencing is confirmed during planning.

### Domain-rule preservation (must keep)
The `IngredientEditor`'s "Soap Chemistry" tab is category-gated (`isCarrierOilCategory`) and editable only when editing an existing record; SAP values are derived server-side (`SoapSap::deriveNaohFromKoh`). The non-negotiable rule **"users cannot create saponifiable oils that drive soap math"** must be preserved verbatim in the custom form — same category gating, same server-side derivation, same SAP-edit thresholds/disclaimers. This migration must not weaken that guardrail; it only changes presentation.

## 6. Optionality discipline (the "future-proof" part)

The goal is to preserve the **option** to move the public UI to Inertia + React/Vue (or SPA + API) if real client-side / multi-surface demand appears — without paying for it now.

Discipline (cheap, ongoing):

- **Services emit serializable contracts.** Preview/payload services already do (`serializeDraft()`, `RecipeWorkbenchPreviewService`). Keep it that way; don't introduce non-serializable model instances into payloads.
- **Livewire components stay thin.** Wire data to/from services; no domain logic in components or in Alpine.
- **No domain logic in Blade/Alpine.** The view layer is a consumer of server payloads, nothing more.

**Escalation trigger to Inertia (concrete, not hypothetical):** real-time multi-user collaboration, offline/PWA, or a shared mobile/marketing component system. Until then, Livewire + Alpine + Blade is the higher-velocity choice and stays.

## 7. Filament upgrade-coupling mitigation (addresses the flagged risk)

After decision 2, Filament touches **admin only**, so a major bump no longer endangers the public product. The remaining mitigations:

1. **Pin, don't float.** Currently `filament/filament: ^5.0` and `laravel/framework: ^13.0` float across minors. Tighten to a known-good minor range and upgrade as a **deliberate, scheduled task**, not via `composer update` drift.
2. **Mind the joint matrix.** Laravel 13 / Livewire 4 / Filament 5 are first-gen majors and move together (Livewire is transitive via Filament). Treat the next combined bump as one effort; run `php artisan filament:upgrade` (already wired in `post-autoload-dump`).
3. **Keep a thin integration surface.** Standard Filament components only in admin; no subclassing internals; route media through the existing `MediaStorage` hooks. Keep admin resources declarative.
4. **Test the surface.** Ensure admin resources have covering tests; CI is the first-line gate on any bump.

## 8. "If redeveloped today"

- **Backend + admin:** the same — Laravel + PostgreSQL + Filament.
- **Public UI:** Livewire + Alpine + Blade again, and I would **not** put Filament forms/tables in the user-facing surface (decision 2 applied from day one).
- **Honest caveat:** I would try *not* to sit on day-one majors of all five frameworks simultaneously — that is the one thing I'd change about the current posture, mitigated by §7.

## 9. Non-goals

- Not rebuilding the admin surface.
- Not adopting Inertia/React now (only when the §6 trigger fires).
- Not a big-bang migration — incremental, service-backed.
- Not changing any domain rule (including the saponifiable-user-edit rule) — only preserving it through the presentation change.

## 10. Deferred / out of scope

- **`MediaStorage` god-node coupling (54 edges, top god node).** Flagged as the *actual* largest maintainability risk in the codebase — larger than any framework choice. Deferred to a separate spec; not addressed here.
- **Re-examining the saponifiable-user-edit rule** itself (only preserved, not redesigned).

## 11. Open questions / assumptions

- **Sequencing/priority** of the four migrations — defaults proposed in §5, to confirm in planning.
- **Reusable kit ownership:** whether the component kit lives in `resources/views/components/` (Blade anonymous components) or as a small set of Alpine-backed Livewire/Blade partials. Decide in planning based on the repeater's needs.
- Assumption: each of the four public components has (or will get) a feature test so the migration is gated by CI. Verify during planning.

## 12. Follow-ups

- **`CLAUDE.md` correction** (after this lands): state explicitly that Filament is used **two ways** — admin panel for platform stewardship **and** (transitionally) standalone form/table builders in public Livewire — with the target state being admin-only. Removes the current doc/code contradiction.
- **First implementation plan:** `docs/superpowers/plans/2026-06-25-migrate-packaging-catalog-public-ui-off-filament.md`.
