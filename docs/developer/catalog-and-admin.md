# Catalog And Admin

Last updated: 2026-03-23

## Catalog philosophy

The catalog is not generic inventory. It is formulation data stewardship.

If a data point affects calculation trust, INCI generation, allergen reporting, or compliance behavior, it belongs in the managed catalog model.

## Ingredient categories

The current ingredient category enum is `App\IngredientCategory`.

Current categories:

- carrier oil
- essential oil
- fragrance oil
- botanical extract
- CO2 extract
- colorant
- preservative
- additive
- alkali
- liquid

Important business rules:

- only carrier oils can appear in the initial saponification selection list
- an ingredient must also be explicitly marked as potentially saponifiable before it can drive soap math
- fragrance oils are not part of the platform starter seed and are intended to be user-added later
- essential oils are platform-managed and expected to grow significantly over time

## Why versions exist

An ingredient record is not enough for regulatory or formulation work.

We version the naming and legal/commercial data separately so we can change:

- display names
- INCI names
- NaOH / KOH soap INCI names
- CAS / EC identifiers
- price and sourcing metadata

without losing traceability.

## Where chemistry lives

Fatty acid profile and SAP data belong to `ingredient_sap_profiles`, not to the base ingredient row.

That separation matters because:

- commercial naming may change without changing chemistry
- chemistry may be corrected or enriched later without redefining the ingredient identity
- recipe versions can point to a specific ingredient version and its chemistry snapshot

## Filament resource intent

Current Filament resources:

- `Ingredients`
- `Ingredient Versions`
- `SAP Profiles`
- `Allergens`
- `Ingredient Allergen Entries`
- `IFRA Product Categories`
- `IFRA Certificates`

Resource responsibilities:

- `Ingredients`: classification and stewardship
- `Ingredient Versions`: names, identifiers, unit, price, active/current state
- `SAP Profiles`: KOH SAP entry, derived NaOH display, and the fixed carrier-oil fatty-acid set
- `Allergens`: permanent declarable-allergen reference catalog
- `Ingredient Allergen Entries`: per-ingredient-version allergen percentages for aromatic materials
- `IFRA Product Categories`: the product contexts recipes are evaluated against
- `IFRA Certificates`: versioned source documents and per-category limits for aromatic materials

## Compliance structure

The aromatic compliance model is now split intentionally:

- `allergen_catalog` stores the permanent allergen reference list
- `ingredient_allergen_entries` ties allergen percentages to a specific ingredient version
- `ifra_product_categories` normalizes the product classes used during compliance
- `product_family_ifra_categories` lets product families advertise which IFRA categories they support
- `recipe_versions.ifra_product_category_id` stores the product context a formula will eventually be checked against
- `ifra_certificates` and `ifra_certificate_limits` store the versioned IFRA document data used during compliance

## Admin access

Filament admin is not open to every authenticated user.

- users need `is_admin = true`
- this is enforced in `App\Models\User::canAccessPanel()`

## Seed behavior

Starter catalog import rules currently include:

- infer category from source code prefix and row content
- preserve French and English naming
- keep CAS / EC values as strings
- mark imported rows for admin review
- skip platform fragrance oils from the starter CSV

## Follow-up work

- support user-owned custom additives and fragrance oils
- support document attachments and audit flows in admin
- surface the aromatic category filters in the public formulation picker
- refine and version the soap-quality calculation profile if the business rules evolve
