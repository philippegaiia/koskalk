# Codex Handoff — koskalk

**Branch:** `codex-packaging-costing-clarification`
**Date:** 2026-04-12

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
- Table now shows both user-owned ingredients AND platform ingredients the user has priced
- Added `SelectFilter` for "My ingredients" / "Priced platform"
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

## Key files to know

| Area | Files |
|------|-------|
| Ingredient editing (user-facing) | `app/Livewire/Dashboard/IngredientEditor.php` |
| Ingredient catalog | `app/Livewire/Dashboard/IngredientsIndex.php` |
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

All 181 tests pass. No pending work on this branch — it's ready to merge or continue from.
