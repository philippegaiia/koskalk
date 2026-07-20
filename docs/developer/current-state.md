# Current State

Last updated: 2026-07-20

## Stack

- Laravel 13
- PostgreSQL in the target environment
- Filament 5 for admin only
- Blade + Livewire + Alpine for the user-facing product
- Pest for tests
- Symfony Intl for maintained localized currency reference data

## What exists today

### Catalog foundations

- `ingredients`
- `ingredient_versions`
- `ingredient_translations`
- `ingredient_sap_profiles`
- `fatty_acids`
- `ingredient_version_fatty_acids`
- `allergen_catalog`
- `ingredient_allergen_entries`
- `regulatory_regimes`
- `regulatory_regime_allergens`
- `substance_catalog`
- `ingredient_substance_entries`
- `regulatory_regime_substance_rules`
- `ifra_product_categories`
- `product_family_ifra_categories`
- `ifra_certificates`
- `ifra_certificate_limits`
- `product_families`

CSV seeders are in place for:

- the starter ingredient catalog
- the EU allergen reference list
- seeded regulatory regimes for EU, Canada, and US preview
- a starter substance catalog with watch-only regime rules

### Ingredient model split

The data is intentionally split into three layers:

- `Ingredient`: category, stewardship flags, source identity
- `IngredientVersion`: display names, INCI names, CAS / EC, unit, price, source version data, normalized fatty-acid row editing for saponifiable oils
- `IngredientSapProfile`: KOH SAP, derived NaOH SAP, legacy fixed fatty-acid fallback values, source notes
- `FattyAcid`: normalized fatty-acid catalog with core and extended acids
- `IngredientVersionFattyAcid`: normalized fatty-acid percentages by ingredient version
- `IngredientTranslation`: non-English platform display names and guidance with canonical English fallback

For aromatic materials, there is now an additional compliance layer:

- `Allergen`: permanent declarable-allergen reference rows
- `IngredientAllergenEntry`: allergen percentages by ingredient version
- `RegulatoryRegime`: selectable market rule set for allergen declarations and substance checks
- `RegulatoryRegimeAllergen`: per-regime allergen declaration rules with exposure thresholds
- `Substance`: neutral tracked substance catalog for constituents, whole ingredients, and groups
- `IngredientSubstanceEntry`: factual substance concentration data by ingredient
- `RegulatoryRegimeSubstanceRule`: per-regime prohibited, restricted, or watch-only substance rules
- `IfraCertificate`: source IFRA documents attached to an ingredient version
- `IfraCertificateLimit`: certificate limits by normalized IFRA product category
- `IfraProductCategory`: the product context a recipe version can be evaluated against

The catalog is deliberately split from the rule type. A substance row only means the platform tracks that substance. The selected regulatory regime decides whether the substance is prohibited, restricted, watch-only, or ignored.

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
- regulatory regimes
- regime allergen rules
- substance catalog
- ingredient substance entries
- regime substance rules
- IFRA product categories
- IFRA certificates
- supported languages
- interface translations
- platform ingredient translations inside the ingredient editor

Admin access is restricted through `User::canAccessPanel()` and an `is_admin` flag on users.

The Filament admin remains English-only.

### Localization foundation

The public interface localization foundation now uses Laravel localization with `spatie/laravel-translation-loader`:

- English interface source text remains in version-controlled language files
- `language_lines` stores only reviewed, application-authored interface translations
- `supported_locales` stores locale metadata and activation state
- English is active and is the fallback locale
- French, Spanish, German, Italian, and Dutch are registered but inactive by default until translated and reviewed
- Laravel Lang supplies framework translations outside the interface editor
- Symfony Intl supplies localized currency names and current selectable codes
- `php artisan translations:sync` inserts missing owned keys without overwriting translations; `--prune` explicitly removes non-owned rows
- `homepage.*`, currency names, and Laravel framework strings are excluded from `language_lines`

Platform-managed catalog and compliance translations are intentionally not stored in `language_lines`.

The first platform-data translation slice is implemented for ingredients:

- `ingredient_translations` stores one non-English display name and guidance record per platform ingredient and registered locale
- canonical English remains on `ingredients`
- the Filament ingredient editor provides the trusted translation workflow
- workbench, platform search, and ingredient catalog delivery use localized names with English fallback
- private ingredients remain exactly as authored

Other platform models and official regulatory nomenclature still require their own deliberate translation architecture. See [localization.md](./localization.md).

### Public UI foundation

The public app now has a real Blade + Tailwind shell:

- `/` uses `HomeController` and a custom marketing-style landing page
- `/dashboard` uses `DashboardController` and a first dashboard shell
- shared layouts live in `resources/views/layouts/public.blade.php` and `resources/views/layouts/app-shell.blade.php`
- the authenticated side menu is the first contextually translated application surface; `Admin` remains English-only

## Immediate next slice

The next product slice should focus on using this data foundation inside the public formulation flow:

- expose category-filtered ingredient picking in the workbench
- model `Saponification` separately from `Formula additions` in the recipe editor
- support both oil-basis and derived total-basis percentage views for soap
- keep expanding curated allergen, substance, and IFRA data through admin stewardship
- refine official source import/review workflows after launch, instead of auto-activating bulk regulatory imports

Recipe images are understood as a later slice:

- one featured image
- one gallery per recipe
