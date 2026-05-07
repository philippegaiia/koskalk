# Allergen Regimes

Last updated: 2026-05-07

This document explains how fragrance allergen declaration works in the formula workbench, and where to update it when a market rule changes.

## Core idea

The system separates factual ingredient composition from regulatory declaration rules.

- `allergen_catalog` stores the stable allergen reference names.
- `ingredient_allergen_entries` stores what an ingredient contains, for example Lavender Essential Oil contains Linalool at a declared percentage.
- `regulatory_regimes` stores selectable market rule sets, for example EU, Canada 2026, or US MoCRA preview.
- `regulatory_regime_allergens` maps which allergens are declarable in a specific regime, with thresholds and optional printed labels.
- `recipe_versions.regulatory_regime_id` stores the selected regime relationship for saved formulas.
- `recipe_versions.regulatory_regime` keeps the legacy code string for compatibility and payload readability.

Do not duplicate ingredient allergen composition per country. The ingredient remains factual; the selected regime decides which of those factual allergens are declared.

Allergen rules are label-declaration rules only. Substance restrictions use `substance_catalog`, `ingredient_substance_entries`, and `regulatory_regime_substance_rules`. When a molecule appears in both systems, such as Linalool, the substance row may link back to the allergen row through `allergen_id`; that link is optional and is the exception, not the default.

## Runtime flow

When the INCI preview is generated:

1. The workbench payload sends `regulatory_regime`, usually a code such as `eu` or `canada_2026`.
2. `InciGenerationService` loads that regime when its status is `active` or `preview`.
3. The service loads active allergen rules for that regime.
4. Each ingredient's `ingredient_allergen_entries` are screened against those rules.
5. If the selected regime has a rule for an allergen, the allergen can be declared when it crosses the exposure threshold.
6. If the selected regime does not have a rule for an allergen, that allergen is ignored for that regime.
7. If the selected regime exists but has no active mappings, no allergens are declared.
8. If the selected regime code does not exist, the service falls back to the legacy all-recorded-allergens behavior.

Only one regime is selected for a formula. Regimes are not combined.

## Seeded regimes

`RegulatoryRegimeSeeder` creates four regimes:

- `eu`: active default, mapped to the current full platform allergen catalog.
- `canada_2026`: active, mapped to Canada's initial 24 fragrance allergens required from April 12, 2026.
- `canada_expanded_preview`: preview, mapped to the platform allergen catalog for Canada's expanded Annex III alignment.
- `us_mocra_preview`: preview shell only, with no allergen mappings until the FDA final rule is available.

The EU and Canada thresholds are seeded as:

- rinse-off: `0.01%`
- leave-on: `0.001%`

## Admin process

To add or modify a regime:

1. Open Filament admin.
2. Go to `Regulatory regimes`.
3. Create or edit the regime code, market, status, source, and notes.
4. Go to `Regime allergen rules`.
5. Add one rule per declarable allergen for that regime.
6. Select the existing allergen from `Allergens`.
7. Leave `Declaration label` empty unless the regime requires a different printed name.
8. Set rinse-off and leave-on thresholds.
9. Keep `Active` enabled when the rule should be used by the workbench.

Example:

- Ingredient composition: Lavender Essential Oil contains `LINALOOL`.
- Regime rule: Canada 2026 declares `LINALOOL`.
- Formula selection: `canada_2026`.
- Output: `LINALOOL` is appended only if its finished-formula percentage reaches the selected exposure threshold.

## Grouped allergens

Some regimes allow or require grouped allergen labels.

Use:

- `group_key` for the internal grouping identifier.
- `group_label` or `declaration_label` for the printed label.

The current engine can use the rule label, but grouped concentration summing should be reviewed carefully before relying on it for complex grouped entries. Canada guidance says grouped entries are triggered by the sum of substances in the group.

## Updating Rules Later

When a regulation changes:

1. Add or update the regime in `RegulatoryRegimeSeeder`.
2. Add source URLs and date notes to `source_data`.
3. Map new allergens in `regulatory_regime_allergens`.
4. Add or update a Pest test in `tests/Feature/CatalogSeederTest.php` for seeded coverage.
5. Add an INCI preview test when the behavior changes in output generation.
6. Run `php artisan db:seed --class=RegulatoryRegimeSeeder --no-interaction` locally after code changes.

Avoid changing historical formula output silently unless that is the intended product behavior. Saved versions keep the selected regime, but generated output always uses the current active rules for that regime.

## Source Notes

- EU: Commission Regulation (EU) 2023/1545 amending Regulation (EC) No 1223/2009 for fragrance allergen labelling.
- Canada: Health Canada Industry Guide for the labelling of cosmetics, section 8.3.8 and Appendix 1.
- US: FDA MoCRA framework requires fragrance allergen labelling rulemaking, but the platform keeps the US regime as preview until final mappings are clear.
