# Current State

Last updated: 2026-04-27

## Stack

- Laravel 13
- PostgreSQL in the target environment
- Filament 5 for admin only
- Blade + Livewire + Alpine for the user-facing product
- Pest for tests

## What exists today

### Catalog foundations

- `ingredients`
- `ingredient_versions`
- `ingredient_sap_profiles`
- `fatty_acids`
- `ingredient_version_fatty_acids`
- `allergen_catalog`
- `ingredient_allergen_entries`
- `ifra_product_categories`
- `product_family_ifra_categories`
- `ifra_certificates`
- `ifra_certificate_limits`
- `product_families`

CSV seeders are in place for:

- the starter ingredient catalog
- the EU allergen reference list

### Ingredient model split

The data is intentionally split into three layers:

- `Ingredient`: category, stewardship flags, source identity
- `IngredientVersion`: display names, INCI names, CAS / EC, unit, price, source version data, normalized fatty-acid row editing for saponifiable oils
- `IngredientSapProfile`: KOH SAP, derived NaOH SAP, legacy fixed fatty-acid fallback values, source notes
- `FattyAcid`: normalized fatty-acid catalog with core and extended acids
- `IngredientVersionFattyAcid`: normalized fatty-acid percentages by ingredient version

For aromatic materials, there is now an additional compliance layer:

- `Allergen`: permanent declarable-allergen reference rows
- `IngredientAllergenEntry`: allergen percentages by ingredient version
- `IfraCertificate`: source IFRA documents attached to an ingredient version
- `IfraCertificateLimit`: certificate limits by normalized IFRA product category
- `IfraProductCategory`: the product context a recipe version can be evaluated against

### Ownership foundations

Tenant-aware schema and policies already exist for:

- workspaces
- workspace members
- recipes
- recipe versions
- recipe phases
- recipe items

### Soap calculation foundations

The first calculation services already exist:

- `App\Services\SoapCalculationService`
- `App\Services\RecipeNormalizationService`

These currently cover:

- KOH-first SAP handling with derived NaOH
- professional-style SAP normalization such as `245` => `0.245`
- superfat, including negative superfat only for high-KOH/liquid contexts
- NaOH, KOH, and dual-lye selection
- KOH purity handling for 90% commercial KOH
- water modes, including lye-concentration process modifiers for firmness and cure speed
- produced glycerine estimation
- fatty acid aggregation from normalized fatty-acid rows
- transparent legacy soap quality reference metrics derived from fatty acids and KOH SAP
- grouped fatty-acid buckets (`vs`, `hs`, `mu`, `pu`, `sp`, `sat`, `unsat`)
- context-aware soap output through `soap_context` (`bar`, `soft`, `liquid`)
- quality applicability metadata through `properties.quality_applicability`
- warnings for liquid/high-KOH superfat and neutralization situations
- superfat behavior outputs (`base_cleansing_potential`, `superfat_buffer`, `effective_cleansing`, `dos_risk_modifier`, `superfat_softening`, `superfat_lather_penalty`)
- first parallel Koskalk quality outputs alongside the legacy SoapCalc-style keys
- nonlinear PU-aware DOS risk, with PU above about 15% escalating strongly
- lather model direction separates quick soluble bubbles, hard-fat foam body/persistence, and capped ricinoleic stability support
- soap recipe normalization on an oil-weight basis

The next normalization change already agreed in the specs is:

- soap drafts need both oil-based working percentages and derived total-formula percentages
- non-soap formulas should normalize on total-formula percentages only

### Admin

Filament resources currently exist for:

- ingredients
- ingredient versions
- SAP profiles
- allergens
- ingredient allergen entries
- IFRA product categories
- IFRA certificates

Admin access is restricted through `User::canAccessPanel()` and an `is_admin` flag on users.

### Public UI foundation

The public app now has a real Blade + Tailwind shell:

- `/` uses `HomeController` and a custom marketing-style landing page
- `/dashboard` uses `DashboardController` and a first dashboard shell
- shared layouts live in `resources/views/layouts/public.blade.php` and `resources/views/layouts/app-shell.blade.php`

## Immediate next slice

The next product slice should focus on using this data foundation inside the public formulation flow:

- expose category-filtered ingredient picking in the workbench
- model the soap reaction core separately from post-reaction additions in the recipe editor
- support both oil-basis and derived total-basis percentage views for soap
- let recipe versions carry an IFRA product category
- prepare live compliance summaries for aromatic materials
- then build the first real recipe creation and editing workflow on top of the public shell

Recipe images are understood as a later slice:

- one featured image
- one gallery per recipe
