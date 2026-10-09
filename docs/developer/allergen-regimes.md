# Allergen Regimes

Last updated: 2026-10-09

This document explains how fragrance allergen declaration works in the formula workbench, and where to update it when a market rule changes.

## Core idea

The system separates factual ingredient composition from regulatory declaration rules.

- `allergen_catalog` stores the stable allergen reference names.
- `ingredient_allergen_entries` stores what an ingredient contains, for example Lavender Essential Oil contains Linalool at a declared percentage.
- `regulatory_regimes` stores selectable market rule sets, for example EU, Canada 2026, or US MoCRA preview.
- `regulatory_regime_allergens` maps which allergens are declarable in a specific regime, with thresholds and optional printed labels.
- `recipe_versions.regulatory_regime_id` stores the selected regime relationship for saved formulas.
- `recipe_versions.regulatory_regime` keeps the code string. It survives when the regime row is deleted (the foreign key is nulled, the string is not), which is how a formula can still name a retired market.
- `workspaces.default_regulatory_regime` is the market new formulas start on. It defaults to `none`.

Do not duplicate ingredient allergen composition per country. The ingredient remains factual; the selected regime decides which of those factual allergens are declared.

Allergen rules are label-declaration rules only. Substance restrictions use `substance_catalog`, `ingredient_substance_entries`, and `regulatory_regime_substance_rules`. When a molecule appears in both systems, such as Linalool, the substance row may link back to the allergen row through `allergen_id`; that link is optional and is the exception, not the default.

## Market selection fails closed

Label market checks are opt-in per formula. `RegulatoryRegime::NONE_CODE` (`none`) means the formula is not checked against any market.

- A blank, missing, unknown, retired or out-of-date market code never falls back to EU. `RegulatoryRegime::normalizeCode()` returns `none` for anything outside the `selectable` scope (active or preview, and inside its effective dates), and no payload default is `eu`.
- EU is not built in. It is checked against its `regulatory_regimes` row like any other market. Tests that expect EU behaviour must create an `eu` regime with the allergen rules they rely on.
- A saved formula whose regime was retired or deleted keeps its stored code. The workbench, the recipe page and documents report it as **unavailable**, with a warning, rather than as unchecked. An explicit save of that formula stores `none`.
- With `none` or an unavailable market, names follow canonical INCI, no allergen threshold is applied, and restrictions report `not_checked`. Nothing renders as a pass.
- `none` is reserved: the admin regime form rejects it as a code.

## Runtime flow

When the INCI preview is generated:

1. The workbench payload sends `regulatory_regime`: `none` or a code such as `eu` or `canada_2026`.
2. `none` stops here: the state is `not_selected`.
3. `InciGenerationService` loads the regime when its status is `active` or `preview` and today is inside its effective dates. If none is found, the state is `unavailable`.
4. The service loads active allergen rules for that regime.
5. Each ingredient's `ingredient_allergen_entries` are screened against those rules.
6. If the selected regime has a rule for an allergen, the allergen is declared when it crosses the exposure threshold.
7. If the selected regime does not have a rule for an allergen, that allergen is ignored for that regime.
8. If the selected regime exists but has no active mappings, no allergens are declared.

Only one regime is selected for a formula. Regimes are not combined.

### Soap threshold basis

For soap, allergen percentages are compared with the cured soap (non-water mass ÷ 0.89), not the wet batch. This matches the "Soap %" column. The basis is part of the saved-list hash (`declaration_threshold_basis`), so every soap formula with a saved final list is flagged once for review; cosmetic lists are unaffected.

### Substance restriction statuses

`SubstanceComplianceService` summarises restrictions as:

- `fail` / `warning`: a rule matched and needs correction or review.
- `unverified`: shown as "Includes starter rules". At least one matched rule is a Koskalk starter placeholder (`RegulatoryRegimeSubstanceRule::hasSpecificComplianceData()` is false).
- `no_rules`: the market has no active substance rules, so nothing was evaluated.
- `not_checked`: no market, or the market is unavailable.
- `pass`: rules exist and none matched the formula. Only this status renders as a pass.

## Seeded regimes

`RegulatoryRegimeSeeder` creates three regimes:

- `eu`: active, `is_default`, mapped to the current full platform allergen catalog. "Default" only orders the market list; new formulas start on the workspace default, which is `none` unless the company changes it.
- `canada_2026`: active, mapped to the full platform allergen catalog (Health Canada adopted the EU expanded list, SOR/DORS-63). New cosmetics disclose from August 1, 2026; existing products from August 1, 2028. The former `canada_expanded_preview` regime is removed and its saved versions are re-pointed to `canada_2026`.
- `us_mocra_preview`: preview shell only, with no allergen or substance rules until the FDA final rule is available. Its restrictions report `no_rules`.

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
