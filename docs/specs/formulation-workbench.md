# Formulation Workbench Spec

Last updated: 2026-04-27

## Goal

The formulation workbench should match SoapCalc for speed while improving structure, clarity, traceability, and the broader feature set of this app.

The target is not a glossy marketing interface. It is a dense professional formulation tool.

## Design reference

The current modern SoapCalc calculator page remains a useful reference for speed and field grouping, but it is not the final ceiling for this product.

We should improve on it by adding:

- phase-aware formulas
- versioned ingredients and recipes
- produced glycerine for INCI generation
- separate compliance review
- richer catalog and admin stewardship

## Accessibility requirements

The workbench is a dense professional tool with many interactive elements. Accessibility must be built in systematically — not audited after the fact.

Rules that apply to every workbench Blade partial:

- **Form labels**: Every input and select needs `aria-label` or `aria-labelledby`. For repeated rows inside Alpine `x-for` loops, use `:aria-label` with context (e.g., `'Percentage for ' + row.name`).
- **Tab navigation**: The main workbench tabs (Formula, Packaging, Costing, Output, Instructions) and any sub-tabs (Qualities, Advanced) must use `role="tablist"`, `role="tab"`, `role="tabpanel"`, and `aria-selected`.
- **Toggle pill groups**: Lye type, water mode, exposure, entry mode, batch weight units, KOH purity, costing units — all behave as radio groups and must use `role="radiogroup"` on the container and `role="radio" :aria-checked` on each button.
- **Modals**: The save-as-official modal and packaging catalog modal must have `role="dialog" aria-modal="true" aria-labelledby`.
- **Inspector popovers**: The info ("i") buttons on ingredient rows must have `aria-label="Show ingredient details"`.
- **Sections**: Each major section (reaction core, post-reaction, formula settings, ingredient browser) needs `aria-labelledby` pointing to its heading.
- **Dynamic messages**: Save confirmations and status counts use `role="status"`. Imbalance warnings and error banners use `role="alert"`.
- **Touch targets**: Toggle pills use `py-2.5` minimum. Modal buttons use `py-2.5` minimum.
- **Font size**: Never below `text-xs` (12px).

These rules are also documented in CLAUDE.md and `docs/developer/public-ui.md`.

## Core interaction rules

- the formulation page is the main workbench
- live draft state stays local in the browser while editing
- field edits trigger local recalculation only
- database writes happen only on explicit actions

Explicit actions:

- save draft
- save as new version
- duplicate
- run compliance
- export

## Layout direction

The page should stay dense and efficient.

Preferred structure:

- top bar for formula identity and save state
- left panel for ingredient search and category filtering
- right panel for the formula table, lye block, and live properties

## Interaction details

For small option sets, prefer tick-style controls, toggle buttons, or radios over plain dropdown selects.

This preference applies especially to:

- lye type
- dual-lye split
- KOH purity
- water mode
- edit mode
- other two-to-four-option settings such as oil weight entry modes

## Ingredient picking

The workbench should separate ingredient picking by role.

Examples:

- initial soap phase: carrier oils only
- essential oil phase: essential oils
- color phase: colorants
- additive phase: additives and other non-saponifying inputs

The ingredient list in the public formulation workspace should use filtering as the primary UX, so users can narrow the list by ingredient role instead of searching one undifferentiated catalog.

Recommended formulation filters include:

- carrier oils
- essential oils
- botanical extracts
- CO2 extracts
- colorants
- preservatives
- additives

Fragrance oils remain a later user-authored path, but the picker architecture should still be ready for that category.

## Phases

Phases are required from the start.

Soap should support defaults such as:

- saponified oils
- lye water
- additives
- fragrance

The model must also support cosmetic-style phases such as:

- aqueous phase
- oil phase
- phase A
- phase B
- phase C

For soap specifically, the formula has two conceptual parts:

- the reaction core: saponified oils + lye water
- the post-reaction additions: additives, fragrance, essential oils, colors, and later phases

The reaction core is the soap calculation itself and should stay visually distinct from the later additions.

## Percentage basis

Soap formulas need two percentage views:

- working percentages based on initial oils for the soap calculation
- derived percentages based on total finished formula for summary, compliance, and final recipe understanding

The oil-based percentages are the primary editing mode for soap.

Non-soap formulas follow a different rule:

- percentages are based on total formula only

This basis must be driven by product family and not hard-coded into one generic recipe table.

## Soap outputs

Live soap outputs must include:

- NaOH and KOH requirements
- dual lye support
- KOH 90% purity option
- water calculation
- superfat effect
- iodine
- INS
- fatty acid profile (show only acids actually present by default)
- grouped fatty-acid buckets available to the frontend
- backend preview payload drives live fatty-acid profile, lye summary, and Koskalk quality rendering
- compact default Koskalk qualities (`unmolding_firmness`, `cured_hardness`, `longevity`, `cleansing_strength`, `mildness`) rendered as normalized 0-100 indices
- totals appear as a horizontal summary row above the quality and fatty-acid panels, including produced glycerine
- lather shown as a compact summary first, with advanced lather metrics behind progressive disclosure
- main quality cards and warning flags include short human-readable interpretation text, not only scores
- quality bars use quiet full-length 0-100 outlined tracks with only the actual score portion filled, leaving the remaining space visibly empty
- target-sensitive qualities show subtle target-zone markers instead of implying every metric should simply be maximized
- risk metrics use the same scale but communicate clearly that higher means more risk
- high-KOH/liquid contexts hide or mark bar-only metrics as not applicable instead of showing misleading zero bars
- saved versions are selectable from the workbench for comparison or for reopening into the editor
- compare the current live formula against the loaded saved baseline with delta rows and a compact what-changed summary for key totals and quality metrics
- advanced soap chemistry metrics behind progressive disclosure

## INCI requirement

Finished-soap INCI generation must account for produced glycerine.

When compliance review is run for aromatic materials, the selected recipe version also needs the applicable IFRA product category so certificate limits can be checked in the right product context.

## Recipe media

Recipes will later need media support for:

- one featured image
- a gallery of additional images

This can be added after the main formulation workflow is stable.

## Soap quality presentation rules

Koskalk practical outputs should be grouped separately from classic calculator references.

Recommended groups:

- physical bar qualities: unmolding firmness, cured hardness, longevity, cure speed
- skin and use feel: cleansing strength, mildness, conditioning feel
- lather: bubble volume, creamy lather, lather stability
- risk and context: DOS/oxidation risk, slime risk, liquid clarity/separation warnings where applicable
- classic references: iodine, INS, and any transitional SoapCalc-style values

For normal bar soap, show the practical quality scores as compact bars with short explanations. The bars should be calm and readable, not dashboard gauges.

Lather metrics should not collapse all foam behavior into one SoapCalc-style number:

- bubble volume should mostly represent quick soluble lather from lauric/myristic-rich oils such as coconut, palm kernel, and babassu
- hard saturated fats such as palmitic/stearic should support creamy body and persistent foam, not heavily erase bubble volume; high HS can mildly slow immediate bubbling because the soap is less soluble
- ricinoleic/castor should improve lather quality and stability in the useful 4-10% range, then saturate rather than add linearly
- formulas with about 20% coconut/palm-kernel-type oil should generally land near the lower edge of the bubble-volume target band, while palm/butters should shift the lather toward creaminess and stability rather than near-zero bubbles

For high-KOH or liquid soap, the workbench should not show bar-only metrics as if they were meaningful. It should instead show:

- KOH / NaOH lye summary
- KOH purity correction
- superfat or lye-excess warning
- fatty-acid tendencies
- liquid-soap caveat that final behavior depends on process, dilution, neutralization, final pH, thickeners/solvents, preservatives, and packaging

Not-applicable metrics should render as a muted explanation such as “Not applicable for liquid/high-KOH soap”, not as an empty 0 score.
