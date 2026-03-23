# Formulation Engine

Last updated: 2026-03-23

## Current services

### `SoapCalculationService`

Current responsibilities:

- calculate theoretical and adjusted KOH
- derive theoretical and adjusted NaOH from KOH
- calculate water for supported water modes
- estimate produced glycerine
- aggregate fatty acid profiles
- derive core soap quality metrics transparently from fatty acids and KOH SAP

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
- NaOH SAP is always derived using the fixed `0.713` ratio
- carrier oils use a fixed core fatty-acid set
- soap qualities are derived outputs, not manually persisted inputs
