# Catalog And Ingredient Data Spec

Last updated: 2026-04-27

## Scope

This spec defines how ingredients are classified and what role they play in formulation, soap calculation, and future compliance work.

## Ingredient categories

The app needs explicit ingredient categories so users are not forced to browse one massive undifferentiated list.

Current intended categories:

- carrier oils
- essential oils
- fragrance oils
- botanical extracts
- CO2 extracts
- colorants
- preservatives
- additives
- alkalis
- liquids

## Soap-specific rule

For the initial soap calculation, the selectable list must show only carrier oils that truly saponify.

Essential oils, additives, colors, preservatives, and other materials are added later in the formula flow and must not pollute the initial saponification picker.

## Essential oils and fragrance oils

- the platform catalog should contain a meaningful essential oil library
- the expected scale is at least 100 main essential oils over time
- fragrance oils are different because the platform cannot know every supplier blend
- fragrance oils should be user-added rather than starter-seeded platform records

This same enrichment path should also cover adjacent aromatic ingredient types such as:

- botanical extracts
- CO2 extracts
- other aromatic specialty extracts that participate in allergen or IFRA logic

## Allergens

Allergen relevance is strongest for:

- essential oils
- fragrance oils
- botanical extracts with aromatic profile
- CO2 extracts and similar aromatic specialty extracts

The app should be designed so those ingredient families carry richer allergen composition data and compliance logic from the start.

For these ingredient families, allergen data and IFRA context are compulsory, not optional.

## Compliance data layers

The compliance model must stay normalized instead of collapsing everything into one ingredient row.

The current target structure is:

- `allergen_catalog` as the permanent declarable-allergen reference list
- `ingredient_allergen_entries` for per-ingredient-version allergen percentages
- `ifra_product_categories` for the product context the formula is evaluated against
- `ifra_certificates` for product-level IFRA documents attached to an ingredient version
- `ifra_certificate_limits` for the per-category limits inside each certificate

This split matters because lavender, vetiver, and similar aromatic materials are products, not allergens. They need allergen composition and IFRA documents, while the allergen catalog remains the underlying reference vocabulary.

## IFRA and restricted ingredients

For aromatic and compliance-sensitive ingredients, the app needs a path for:

- allergen composition
- IFRA-related data
- restricted-ingredient rules

IFRA category limits are not ingredient-only values. They depend on the product being formulated. That means recipe versions need an IFRA product category context so compliance can compare a material's usage level against the correct certificate limit.

Restricted ingredients can be provisioned progressively while the platform is being built, rather than requiring a perfect completed rule library before the rest of the product moves forward.

## SAP value policy

In real-world source material, SAP is commonly provided as KOH SAP.

Preferred product direction:

- KOH SAP should be treated as the canonical source value when that is what the supplier or trusted source provides
- NaOH SAP is always derived from the fixed conversion ratio
- the platform does not persist a second editable NaOH SAP field

Working conversion note:

- `NaOH SAP ~= KOH SAP * 0.713`

The admin UX should use KOH-first entry and display derived NaOH where helpful.

## Fatty acid profile

Fatty acid profile is first-class formulation data and must live alongside SAP data, not as optional display metadata.

It is required for:

- soap quality metrics
- ingredient comparison
- formulation guidance
- future richer reporting

For carrier oils, the app supports a controlled fatty-acid vocabulary rather than arbitrary ad-hoc keys. The visible/common set remains:

- lauric
- myristic
- palmitic
- stearic
- ricinoleic
- oleic
- linoleic
- linolenic

The data model and calculation engine may also use extended fatty acids when present, including caprylic, capric, palmitoleic, arachidic, behenic, gondoic/eicosenoic, erucic, and other catalogued minor acids. Missing extended values must be treated as zero, not as invalid data.

Normalized fatty-acid data supports two different output families:

- classic calculator references such as iodine, INS, and transitional SoapCalc-style values
- Koskalk practical quality outputs based on grouped fatty-acid behavior, superfat, water/lye context, and NaOH/KOH context

## Soap quality strategy

Soap quality outputs should be transparent derived values, not manually curated opaque numbers. Koskalk should not blindly reproduce SoapCalc as the source of truth.

Current direction:

- store KOH SAP as canonical source where available
- derive NaOH SAP
- store normalized fatty-acid data for platform oils
- derive grouped fatty-acid buckets: very soluble saturates, hard structural saturates, monounsaturated, polyunsaturated, ricinoleic/special lather, saturated, and unsaturated
- keep iodine and INS as technical references
- keep legacy calculator references separate from Koskalk's practical quality model
- derive practical qualities such as unmolding firmness, cured hardness, longevity, cleansing strength, mildness, lather behavior, DOS/oxidation risk, slime risk, and cure speed

Quality calculations must account for:

- fatty-acid profile
- superfat as a global behavior modifier
- water / lye concentration for process qualities
- NaOH/KOH context

Lather calculations must distinguish creation, body, and persistence:

- lauric/myristic-rich oils create quick open bubbles and should be the main bubble-volume driver
- caprylic/capric contribute soluble lather but should be weighted separately from lauric/myristic
- palmitic/stearic hard saturated fats give body, creaminess, and foam persistence; they should only mildly dampen immediate bubble volume when high
- ricinoleic/castor improves lather quality and stability in a capped useful range around 4-10%, and should not add linearly beyond that
- excess ricinoleic should not make up for too little soluble lather fat

Polyunsaturated fatty acids need sharper practical risk handling:

- PU <= 10%: generally acceptable
- PU 10-15%: caution zone
- PU > 15%: high risk for soap stability / DOS
- PU > 20%: very high risk / strong warning

Liquid/high-KOH soap must not reuse the full bar-quality model. High-KOH contexts should hide or mark bar-only metrics not applicable and instead show KOH calculation, superfat/lye-excess warnings, fatty-acid tendencies, and liquid-soap process caveats.

## Out of scope for now

Packaging is not part of the current product scope.
