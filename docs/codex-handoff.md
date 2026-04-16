# Codex Handoff — koskalk

**Branch:** `codex-packaging-costing-clarification`
**Date:** 2026-04-14

## What was done on this branch

This branch started with a stale packaging costing plan. After grilling the plan against actual code, we pivoted to what actually needed doing. Here's the full sequence:

### Phase 1: Price management + ingredient duplication
- Added `user_ingredient_prices` table for per-user price memory (EUR/kg)
- Added price column to the ingredients index (inline editing via Filament `TextInputColumn`)
- Added ingredient duplication: users can duplicate a platform ingredient into their private catalog
- Added user-owned ingredient badge (amber dot) in recipe output INCI lists
- Dropped `price_eur` and `display_name_en` columns from ingredients table

### Phase 2: Follow-up fixes (4 issues from user testing)

**Issue 1: Carrier oil SAP editing**
- Added "Soap Chemistry" tab to `IngredientEditor.php` for carrier oils (KOH SAP, NaOH derived, iodine, INS, fatty acid repeater)
- Removed `source_notes` from fatty acid, allergen, and IFRA limit repeaters (users can use the ingredient-level notes field instead)

**Issue 2: Unified ingredient table**
- Merged the separate "Priced ingredients" Blade section into the Filament table
- Table now shows both user-owned ingredients and active platform ingredients, so users can discover platform ingredients before setting prices
- Added a source badge for `Mine` vs `Platform`
- Added `SelectFilter` for "My ingredients" / "Platform catalog" / "Priced platform"
- Added `userPricePerKg` virtual attribute on `Ingredient` model (reads from eager-loaded `userPrices` relation)
- Edit/Delete actions only visible for user-owned ingredients

**Issue 3: Weight unit in column headers**
- Moved unit from cell values to headers across all workbench tables: `Weight (g)` / `Weight (oz)` etc.
- Files: `reaction-core.blade.php`, `post-reaction.blade.php`, `costing-tab.blade.php`, `output-tab.blade.php`
- Standalone summary cards kept their units (no column header context)

**Issue 4: Saved recipe page refactoring**
- Refactored `resources/views/recipes/version.blade.php` and `resources/views/recipes/partials/version-sheet.blade.php`
- Changed border radius from `rounded-[2rem]` to `rounded-xl` to match workbench design
- Compacted recovery snapshots into a thin list (hidden when only 1 version exists)
- Applied workbench-style section styling (`bg-[var(--color-panel)] shadow-[...]`)
- Fixed Weight column headers here too

### Phase 3: Product type foundation
- Added platform-managed `product_types` under `product_families`, with optional default IFRA category suggestion, active flag, sort order, description, and fallback recipe-card image.
- Added nullable `recipes.product_type_id` so recipes can be categorized without forcing old soap recipes through the new taxonomy.
- Seeded the `cosmetic` product family and starter cosmetic product types: cream/lotion, balm/salve, lip product, deodorant, hair care, mask, oil blend/serum, cleansing non-saponified, bath salts/soaks, and other.
- Added a Filament admin resource for product types. Delete is disabled when recipes reference the product type.
- Product type fallback images follow the recipe-card 4:3 policy and are stored as fitted WebP images up to 800x600.

### Phase 4: Recipe index product-type wiring
- Added URL-bound product family and product type filters to the shared recipes page.
- Extended recipe search to match product type names as well as recipe and product family names.
- Updated recipe cards to show product type badges and created/updated metadata.
- Recipe card thumbnails now use the recipe image first, the product type fallback image second, and the text placeholder last.

### Phase 5: App UI class cleanup
- Added shared app-facing UI classes in `resources/css/app.css`: `sk-card`, `sk-inset`, `sk-eyebrow`, `sk-field`, `sk-input`, `sk-btn`, `sk-action-link`, and `sk-badge` variants.
- Migrated the main dashboard, recipes index, recipe workbench partials, saved recipe page, ingredient pages, packaging pages, settings page, and related ingredient partials from repeated card/eyebrow/inset utility strings to the shared classes.
- `sk-eyebrow` is intentionally bolder and slightly letter-spaced. Adjust it centrally in `resources/css/app.css` if the small uppercase labels need a future design tweak.
- The app-facing sweep no longer has the old repeated `rounded-xl bg-[var(--color-panel)] shadow-[...]`, `rounded-lg bg-[var(--color-panel-strong)]`, or `text-[0.6875rem] ... uppercase` patterns under `resources/views/livewire/dashboard`, `resources/views/recipes`, or `resources/views/dashboard.blade.php`.

## Key files to know

| Area | Files |
|------|-------|
| Ingredient editing (user-facing) | `app/Livewire/Dashboard/IngredientEditor.php` |
| Ingredient catalog | `app/Livewire/Dashboard/IngredientsIndex.php` |
| Recipe index | `app/Livewire/Dashboard/RecipesIndex.php` + `resources/views/livewire/dashboard/recipes-index.blade.php` |
| User ingredient CRUD service | `app/Services/UserIngredientAuthoringService.php` |
| Data entry service (admin + user) | `app/Services/IngredientDataEntryService.php` |
| Price & duplication endpoints | `app/Http/Controllers/IngredientController.php` |
| Recipe workbench JS | `resources/js/recipe-workbench/` |
| Workbench Blade partials | `resources/views/livewire/dashboard/partials/recipe-workbench/` |
| Saved recipe view | `resources/views/recipes/version.blade.php` + `partials/version-sheet.blade.php` |

## Conventions

- **No DB writes on field change** — Alpine manages draft state locally
- **Service-layer focus** — business logic in services, Livewire components stay thin
- **Filament for user tables too** — `IngredientsIndex` extends `TableComponent`, not plain Livewire
- **Testing:** Pest PHP, SQLite in-memory, `composer test` to run all
- **Frontend:** Blade + Alpine.js + Tailwind 4.0, desktop-first dense layouts
- **Virtual attributes:** `Ingredient::userPricePerKg` uses `Attribute` with getter + no-op setter for Filament's `TextInputColumn`

## Current state

All 201 tests passed after the latest recipe index product-type pass, but the branch is not final for launch. It is ready to continue from, with the decisions and backlog below.

## Decisions from 2026-04-13 grill session

- Finish the core app before redesigning the seed pipeline. The catalog seeding/export work is required before shipping, but it is not the immediate next implementation priority.
- The existing CSV and old carrier-oil seeders should not be treated as the long-term seeding path. Once the ingredient database is manually cleaned, generate a fresh platform catalog seed/export from that curated database.
- Production will be the operational source of truth for the live catalog after launch. Catalog edits can happen through the Laravel admin/app UI by authorized staff; the repo seed data is a launch/bootstrap snapshot, not the daily catalog authority.
- User-owned ingredients, user prices, recipes, workspaces, and packaging items must never be included in platform seed data.
- Ingredient price memory should keep `price_per_kg` as the canonical stored value. Formula weight units can convert to kg for costing. A later UX setting may let US/imperial users enter/display prices per lb, but that should convert at the UI/service boundary rather than storing mixed price units.
- Laravel owns app login, subscription status, app roles, catalog permissions, recipes, ingredients, workspaces, and feature gates.
- WordPress may own public marketing content, articles, SEO pages, and the main `soapkraft.com` site, but not app permissions.
- App login happens on `app.soapkraft.com`.
- Prefer Laravel + Stripe/Cashier for subscriptions instead of WordPress/FluentCart subscription sync. WordPress buttons can link users into Laravel registration/checkout.
- Keep roles separate from subscription tiers:
  - Roles answer staff/admin permissions, such as `admin`, `catalog_manager`, and future operator/support roles.
  - Tiers answer product access, such as Free, Solo, and Studio/Pro.
- Free users should register in Laravel. Free tier should be useful enough to save formulas and drive traffic; advertising is deferred until later.
- Initial tier direction:
  - Free with ads later: generous saved formulas, limited custom ingredients and packaging, basic access.
  - Solo: no ads, generous fair-use limits, branding/exports with a discreet "made with Soapkraft" mark.
  - Studio/Pro: higher fair-use limits and future team/company workflows.
- Existing company/workspace/member architecture can stay in the data model, but team/member UI should remain hidden until the higher tier is ready.
- Recipe lifecycle model should remain conceptually simple:
  - Draft = editable working copy.
  - Saved recipe = current official saved state.
  - Recovery snapshots = hidden safety backups, not user-facing version history.
- The UI should avoid exposing technical version numbers like `v16` as the primary concept. Recovery snapshots should be labeled by date/time and hidden behind a collapsed "recovery snapshots available" drawer.
- Workbench action language should stay plain:
  - `Save draft` keeps the working copy only.
  - `Save recipe` updates the current saved recipe from the draft.
  - `View saved recipe` opens the read-only/print/export surface.
  - `Duplicate` creates a separate recipe branch.
- Recipes should stay on one shared recipe index page, not split into separate soap/cosmetic pages. Default ordering should be latest/recent recipes first. Existing search should remain and become broad/wildcard enough to search recipe/product name, and ideally ingredients if performance is acceptable. Add filters for product family and product type/category. Recipe card UI needs a separate cleanup pass.
- Recipe cards should use a simple blog-card pattern: image, title/product name, product type/category badge, small created/updated metadata, and minimal status. Do not show ingredient summaries on the card.
- Recipe cards should support user-uploaded images, with fallback images configured on each platform-managed product type when no recipe upload exists.
- Keep the current cropped 4:3 image policy unless a future design pass finds a concrete problem; `800x600` is a reasonable stored size for these cards. This app is laptop/desktop-first for serious formulation work, though mobile should remain usable.
- Soap recipes can use the same product-type fallback image pattern later, but cosmetic v1 should not be blocked on the broader soap recipe-card redesign.
- Cosmetic formulation v1 direction:
  - Users choose the formula/product type before entering the workbench.
  - Cosmetic formulas reuse the same recipe/version/phase/item tables; product family/context drives behavior.
  - Formula name stays free text.
  - Product family should represent the calculation engine/context, for example `soap` vs `cosmetic`.
  - Broad cosmetic product type should be a platform-managed database category under the cosmetic family, not a PHP enum. It can drive recipe cards, filters, display order, active/hidden status, defaults, and future translations.
  - Starter cosmetic product types should cover at least cream/lotion, balm/salve, lip product, deodorant, hair care, mask, oil blend/serum, cleansing products that are not saponified soaps, bath salts/soaks, and other. The platform should be able to add more later.
  - Cosmetic product types are platform-managed only in v1. Users should use the free-text formula/product name for custom nuance rather than creating private product types.
  - Add a small Filament/platform admin management screen for product types rather than requiring code/DB edits. It should cover at least label/name, active/disabled state, sort order, default IFRA category suggestion, and fallback image. Do not expose product type management in the subscriber app UI.
  - Store cosmetic product type labels English-first with stable slugs for now. Keep the model/columns easy to evolve for translations later rather than building translation admin UI in v1.
  - Product types with related recipes should not be deletable. Prefer disabling/hiding over destructive deletion. Disabled product types should be unavailable for new recipe selection, but still visible and filterable for existing recipes that use them. Product type merging can be a later admin maintenance tool, not a v1 requirement.
  - Product types may suggest a default IFRA category, but IFRA category remains a separate editable choice on the formula.
  - Cosmetic formulas can have a baby/child product context toggle. This should not change formula math or hard validation in v1; it only shows a clear reminder to verify the IFRA category, stricter safety context, lab testing expectations, and review with a qualified assessor/toxicologist.
  - Multilingual strategy should start with Laravel core localization for UI strings and translation-ready database records for platform-managed product types. Avoid adding a heavy translation plugin until the actual translation workflow demands it.
  - Start cosmetic formulas with one default phase named `Phase A`.
  - Users can add, rename, remove, and reorder phases.
  - Cosmetic batch size is total formula weight, not oil weight and not dry/wet basis.
  - Cosmetic edit mode should mirror soap: one shared formula edit mode for percentage vs weight entry.
  - Drafts can be incomplete; final save/export should require the formula to total 100%.
  - Cosmetic INCI output aggregates duplicate ingredients across all phases and sorts by total formula percentage.
  - Do not add below-1% label reordering tools in v1. Users can copy and manually adjust final label order if needed.
  - IFRA/allergen/restricted-ingredient behavior is warning/reference only in v1; no save/export blocks.
  - Do not add preservative warnings in v1 until the catalog has reliable water/preservative metadata.
  - Water activity calculation may be a later value-add feature, not a v1 dependency.
 - Old CSV/Mendrulandia seeder review findings are deferred/superseded by the fresh curated catalog export decision. Do not spend time polishing the old CSV diff/import path unless it becomes necessary before the new seed/export path exists.

## Carrier Oil Seeder (2026-04-16)

`database/seeders/CarrierOilSeeder` seeds carrier oils from `database/seeders/data/mendrulandia_oils.json` (98 oils with fatty acids, KOH SAP, iodine, INS).

**Seeding behavior:**
- Uses `updateOrCreate` — existing records are updated, missing records are created.
- Only fills **empty fields** — manually edited values in Filament are preserved on re-seed.
- Auto-generates `soap_inci_naoh_name` and `soap_inci_koh_name` from INCI or display name using naming rules. These need manual review in Filament — castor, copaiba, and other exceptions won't be correct.
- If you delete an oil from the DB and re-seed, it comes back. To permanently remove, delete from the JSON file too.

**INCI lookup:** `app/Data/InciNameLookup.php` — covers ~50 common oils. Extends over time as needed.

**Extraction scripts:**
- `scripts/extract_mendrulandia_oils.php` — parses Mendrulandia calculator JS
- `scripts/extract_soapcalc_sap.php` — merges SoapCalc SAP data
- `scripts/extract_fnwl_sap.php` — merges FNWL saponification chart data

## Pre-shipping backlog

- Complete cosmetic formulation support.
- Refine recipe lifecycle UI copy and layout: rename saved/draft actions, collapse recovery snapshots, and stop making technical version numbers prominent.
- Define the v1 IFRA behavior as warning/reference guidance rather than full heavyweight automated compliance enforcement.
- Review and refine print/export outputs.
- Implement Laravel subscription/auth/tier gating with Stripe/Cashier.
- Build a curated platform catalog export/seed flow from the cleaned database before production launch.
- Decide how production catalog backup/export should work after launch, since the live database will continue evolving.
