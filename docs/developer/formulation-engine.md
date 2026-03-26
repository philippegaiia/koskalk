# Formulation Engine

Last updated: 2026-03-25

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
