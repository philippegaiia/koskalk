# Formulation Engine

Last updated: 2026-09-13

## Current services

### `SoapCalculationService`

Current responsibilities:

- calculate theoretical and adjusted KOH
- derive theoretical and adjusted NaOH from KOH
- support NaOH-only, KOH-only, and dual-lye selection
- adjust KOH-to-weigh output for 90% purity when requested
- calculate water for supported water modes
- estimate produced glycerine
- aggregate fatty acid profiles
- prefer normalized table-driven fatty-acid entries when available, with legacy SAP-column fallback
- derive grouped fatty-acid buckets (`vs`, `hs`, `mu`, `pu`, `sp`, `sat`, `unsat`)
- expose superfat behavior outputs (`base_cleansing_potential`, `superfat_buffer`, `effective_cleansing`, `dos_risk_modifier`)
- keep legacy SoapCalc-style outputs during transition
- expose the first parallel Koskalk quality metrics alongside legacy keys
- support a compact frontend presentation based on default quality cards plus advanced disclosure
- expose a backend preview payload so the workbench consumes server-side fatty-acid profiles, lye outputs, and quality metrics as the live source of truth
- keep compact Koskalk quality indices normalized to a 0-100 display range even when underlying chemistry helpers exceed that range
- benchmark quality behavior against archetypes like castile, high-coconut, balanced palm/olive/coconut, and high-shea profiles before further UI expansion
- model lather as separate behaviors: soluble lauric/myristic fats create quick bubbles, hard palmitic/stearic fats give body and persistent foam, and ricinoleic/castor improves lather quality/stability in a capped useful range around 4-10%

### `RecipeNormalizationService`

Current responsibilities:

- normalize phase-based soap drafts
- convert percent to weight
- convert weight to percent
- keep totals expressed on an oil-weight basis

## Current soap assumptions

- soap has a distinct reaction core made of saponified oils and lye water
- post-reaction phases such as additives, fragrance, essential oils, and colors come after that core
- soap percentages are edited primarily on initial oil weight
- soap also needs derived total-formula percentages for end-of-formula understanding
- live editing should remain browser-local during formulation work

## Product-family basis rule

The formulation engine should not treat every recipe family the same.

- soap uses initial-oils percentages as the working basis
- non-soap formulas use total-formula percentages as the working basis

That means normalization has to be product-family aware, not just recipe-phase aware.

## Important domain rules already agreed

- produced glycerine must be part of finished-soap INCI generation
- phases are first-class and must support soap and cosmetic workflows
- initial soap calculation only shows carrier oils that can saponify
- professional SAP input like `245` should be normalized automatically to `0.245`

## Planned UI direction

The target is SoapCalc-level speed with better structure.

The formulation page should favor:

- click-to-add ingredient interaction
- dense table-first layout
- inline editing
- visible totals
- visible unsaved state
- local recalculation without server round-trips

For settings with a small fixed number of options, prefer tick-style controls or toggle buttons over plain selects. This is especially relevant for:

- lye type
- water mode
- percent vs weight editing mode
- unit or oil-weight entry modes when the option set is small

Recipe media is a later concern. The current domain target is to support one featured image and a gallery per recipe after the main formulation workflow is in place.

## Current chemistry strategy

- KOH SAP is the only persisted SAP source value
- KOH SAP can be entered in professional format (`245`) or decimal format (`0.245`)
- NaOH SAP is always derived using the fixed `0.713` ratio
- the legacy SAP profile still exists, but the future direction is a normalized fatty-acid catalog plus per-ingredient-version fatty-acid rows
- the workbench now prefers normalized fatty-acid rows when they exist
- soap qualities are derived outputs, not manually persisted inputs
- superfat is moving toward a practical behavior model rather than a guessed unsaponified-fatty-acid model
- soap molecule density remains a future research idea, not a v1 dependency

## Soap quality calibration: first pass

Implemented model version: `2026-09-13`, returned as `properties.quality_model_version`.

The current engine estimates formulation behavior from fatty acids, superfat, selected alkali, and total dilution liquid. These are empirical indices, not measured properties or validated skin-tolerance predictions. SAP, lye, glycerine, and recipe quantities retain their existing calculation rules.

- Cleansing is normalized against a documented quick-fatty-acid reference before applying the superfat response. Approximately 24–25% lauric/myristic at 5% superfat reaches 40; the reference coconut profile scores 100 at 0% and approximately 42 at 20% superfat. Oil names do not affect scoring.
- Palmitic/stearic contributions to unmolding and cured hardness are stronger. A smooth high-oleic structure term allows very hard olive/high-oleic bars without assigning them the longevity of palmitic/stearic-rich bars.
- Cured hardness refers to approximately four weeks. Total dilution liquid affects early firmness, cure speed, and more modestly four-week hardness. `shrinkage_risk` indicates visible shrinkage tendency, not a percentage of dimensional or weight loss.
- The existing nonlinear DOS formula and its superfat penalty are preserved. No protection bonus is inferred from additive names or categories.
- Custom scores remain bounded from 0 to 100. Cleansing above the advisable ceiling of 40 remains visible. High mildness is no longer styled as excessive.

`soap_context` and `properties.quality_applicability` are implemented. Context labels are `bar` (up to 20% KOH), `hybrid` (over 20% through 40%), `soft_or_liquid` (over 40% through 60%), and `liquid` (over 60%). NaOH and KOH settings resolve to 0% and 100% respectively.

KOH progressively reduces modeled bar structure/longevity and supports lather, with provisional coefficients. Any KOH custom metric is presented as a process-dependent tendency. Above 40% KOH, bar-only metrics are omitted from the cards while supported lather/feel tendencies remain visible. This cutoff is an applicability guard, not a physical phase boundary. Negative superfat remains available in the existing high-KOH workflow; final cleansing, mildness, and conditioning estimates are withheld until a future neutralization model exists.

See [Soap quality calibration](soap-quality-calibration.md) for formulas, benchmarks, evidence, limitations, and deferred work. Regression coverage lives in `SoapQualityCalibrationTest`, the existing soap calculation/benchmark tests, the workbench preview test, and `soap-quality-presentation.test.mjs`.
