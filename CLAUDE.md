# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Koskalk is a professional formulation workspace for soap and cosmetic recipes. The product is a technical tool designed for SoapCalc-level efficiency with better clarity, data structure, and compliance support. It is not a consumer app or social platform.

### Tech Stack

- **Backend:** Laravel 13, PostgreSQL (target environment), SQLite (testing)
- **Frontend:** Blade + Livewire + Alpine.js + Tailwind 4.0
- **Admin:** Filament 5 (admin only, not user-facing)
- **Testing:** Pest PHP
- **AI:** Laravel Boost (for AI development assistance)

### Key Architecture Principles

1. **No DB writes on field change** - Alpine manages live draft state locally; explicit actions only
2. **Service-layer focus** - Livewire components and controllers remain thin; business logic lives in services
3. **Tenant-aware data** - User/workspace data uses global scopes and policies from day one
4. **Versioning is mandatory** - Ingredients, recipes, and compliance runs are all versioned
5. **Admin vs Public separation** - Filament is for data stewardship only; public UI uses custom Blade/Livewire

## Common Development Commands

### Setup
```bash
composer install
npm install
php artisan key:generate
php artisan migrate
npm run build
```

### Development
```bash
# Run all services (server, queue, logs, vite)
composer run dev

# Individual services
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
php artisan pail --timeout=0
npm run dev
```

### Testing
```bash
# Run all tests
composer test

# Run specific test file
php artisan test --filter=SoapCalculationServiceTest

# Run with coverage
php artisan test --coverage
```

### Code Quality
```bash
# Pint (Laravel's code formatter)
./vendor/bin/pint

# Pail (log monitoring)
php artisan pail
```

## Project Structure

### Service Layer (`app/Services/`)

Services contain the business logic. Key services include:

- `SoapCalculationService` - Core soap math, fatty acid aggregation, quality metrics
- `RecipeNormalizationService` - Phase-based formula normalization
- `InciGenerationService` - INCI list generation with compliance context
- `RecipeVersionCostingSynchronizer` - Cost calculation and synchronization
- `UserIngredientAuthoringService` - User ingredient creation and editing
- `RecipeWorkbenchService` - Formulation workspace logic

### Livewire Components (`app/Livewire/Dashboard/`)

Public UI uses Livewire components for the dashboard and formulation workspace:

- `RecipeWorkbench` - Main formulation page with Alpine for local state
- `IngredientsIndex` / `IngredientEditor` - Ingredient catalog browsing and editing
- `PackagingItemsIndex` / `PackagingItemEditor` - Packaging item management

### Models (`app/Models/`)

Three-tier ingredient model:
- `Ingredient` - Category, stewardship flags, source identity
- `IngredientVersion` - Display names, INCI, CAS/EC, price, source version data
- `IngredientSapProfile` - KOH SAP, derived NaOH SAP, fatty acid profiles

### Frontend Stack (`resources/`)

- `resources/js/recipe-workbench/` - JavaScript for the formulation workspace
- `resources/views/layouts/` - Public and app-shell layouts
- `resources/views/livewire/` - Livewire component views

## Domain Rules

### Ingredient Categories

Defined in `App\IngredientCategory` enum. Only carrier oils can appear in the saponification selection list. An ingredient must be explicitly marked as potentially saponifiable before driving soap math.

### Product Families

Product families (defined in `ProductFamily`) drive IFRA mapping, restriction applicability, and calculation defaults. Never hard-code these as conditionals.

### Data Ownership

**Platform-owned (admin-controlled):**
- Ingredients, ingredient versions, SAP profiles
- Allergen catalog, IFRA categories
- Compliance rules and jurisdictions

**Tenant-aware (user/workspace-owned):**
- Recipes, recipe versions, recipe items, recipe phases
- User ingredient prices and packaging items
- Compliance runs and results
- Private custom additives

### Non-negotiable Rules

1. Users cannot create saponifiable oils that drive soap math
2. Compliance runs are append-only — never overwrite
3. Every tenant-aware model gets global scope + policy before first controller
4. `product_family_id` drives IFRA and restriction logic — never hard-code
5. Filament is admin-only — no user-facing functionality leaks there

## Frontend Architecture

### Formulation Workspace

The formulation page uses Alpine.js for instant local calculations:

- **Live state:** Alpine manages the draft state locally (no DB writes on field change)
- **Ingredient search:** Pre-loaded data, no server calls during search
- **Calculations:** Server provides preview payload, Alpine updates instantly
- **Save actions:** Explicit actions (Save Draft, Save as New Version) trigger server writes

### Design Principles

- Desktop-first, dense layout
- Table-first UI over card-based design
- Small-option controls use ticks/toggles instead of selects
- Minimal animations, focus on speed and clarity
- Clear unsaved state indicator always visible

## Testing Patterns

Use Pest PHP for all tests. Tests use SQLite in-memory database. Feature tests cover user workflows, while unit tests test services in isolation.

Example test structure:
```bash
tests/Feature/             # User workflows and integration tests
tests/Unit/                # Service-level unit tests
database/factories/        # Factory definitions for test data
```

## Documentation Structure

Project documentation lives in `docs/`:

- `docs/README.md` - Documentation index
- `docs/developer/` - Implementation details and architecture
- `docs/specs/` - Product rules and domain decisions
- `plan.md` - Overall build plan and product direction

## Important Files to Understand

- `app/Services/SoapCalculationService.php` - Core soap calculation logic
- `app/Livewire/Dashboard/RecipeWorkbench.php` - Main formulation component
- `app/Models/Ingredient.php` - Ingredient model structure
- `resources/js/recipe-workbench/` - Frontend formulation workspace
- `plan.md` - Complete product build plan

## Development Context

This is a professional tool targeting SoapCalc users who want better data structure and compliance support. The goal is clarity and speed, not visual flourish. When making decisions, prioritize:

- **Speed** - The formulation page must feel instant
- **Clarity** - A professional must understand output without a manual
- **Trust** - Data integrity is non-negotiable; bad SAP values cause harm
- **Auditability** - Every calculation must trace to versioned source data

Avoid:
- Visual flourish over functionality
- Speculative enterprise complexity
- Generalized regulation engines before demand exists

## graphify

This project has a graphify knowledge graph at graphify-out/.

Rules:
- Before answering architecture or codebase questions, read graphify-out/GRAPH_REPORT.md for god nodes and community structure
- If graphify-out/wiki/index.md exists, navigate it instead of reading raw files
- For cross-module "how does X relate to Y" questions, prefer `graphify query "<question>"`, `graphify path "<A>" "<B>"`, or `graphify explain "<concept>"` over grep — these traverse the graph's EXTRACTED + INFERRED edges instead of scanning files
- After modifying code files in this session, run `graphify update .` to keep the graph current (AST-only, no API cost)
