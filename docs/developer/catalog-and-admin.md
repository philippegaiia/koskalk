# Catalog And Admin

Last updated: 2026-05-07

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
- solvent

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
- `Regulatory Regimes`
- `Regime Allergen Rules`
- `Substances`
- `Ingredient Substance Entries`
- `Regime Substance Rules`
- `IFRA Product Categories`
- `IFRA Certificates`

Resource responsibilities:

- `Ingredients`: classification and stewardship
- `Ingredient Versions`: names, identifiers, unit, price, active/current state
- `SAP Profiles`: KOH SAP entry, derived NaOH display, and the fixed carrier-oil fatty-acid set
- `Allergens`: permanent declarable-allergen reference catalog
- `Ingredient Allergen Entries`: per-ingredient-version allergen percentages for aromatic materials
- `Regulatory Regimes`: selectable market rule sets used by formula INCI allergen screening
- `Regime Allergen Rules`: per-regime allergen mappings, thresholds, labels, and effective/source metadata
- `Substances`: neutral platform catalog for tracked constituents, whole ingredients, and groups
- `Ingredient Substance Entries`: factual ingredient composition rows for tracked substances
- `Regime Substance Rules`: per-regime prohibited, restricted, or watch-only substance rules
- `IFRA Product Categories`: the product contexts recipes are evaluated against
- `IFRA Certificates`: versioned source documents and per-category limits for aromatic materials

## Compliance structure

The aromatic compliance model is now split intentionally:

- `allergen_catalog` stores the permanent allergen reference list
- `ingredient_allergen_entries` ties allergen percentages to a specific ingredient version
- `regulatory_regimes` stores selectable market regimes such as EU, Canada, or US preview
- `regulatory_regime_allergens` stores which allergens are declared by each regime and at what thresholds
- `substance_catalog` stores neutral tracked substances. Catalog membership does not imply a legal restriction.
- `ingredient_substance_entries` stores factual concentration data for a substance inside one ingredient.
- `regulatory_regime_substance_rules` stores the market meaning for each substance: prohibited, restricted, or watch-only.
- `ifra_product_categories` normalizes the product classes used during compliance
- `product_family_ifra_categories` lets product families advertise which IFRA categories they support
- `recipe_versions.ifra_product_category_id` stores the product context a formula will eventually be checked against
- `ifra_certificates` and `ifra_certificate_limits` store the versioned IFRA document data used during compliance

Allergen declaration and substance compliance are separate engines. A substance may optionally link to an allergen through `allergen_id`, but most substances are not allergens. The link exists for overlap cases such as Linalool; it is not required for heavy metals, prohibited colorants, preservatives, or other tracked substances.

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
