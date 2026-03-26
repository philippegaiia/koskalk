# Formulation Workbench Spec

Last updated: 2026-03-25

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
- compact default Koskalk qualities (`unmolding_firmness`, `cured_hardness`, `longevity`, `cleansing_strength`, `mildness`)
- lather shown as a compact summary first, with advanced lather metrics behind progressive disclosure
- advanced soap chemistry metrics behind progressive disclosure

## INCI requirement

Finished-soap INCI generation must account for produced glycerine.

When compliance review is run for aromatic materials, the selected recipe version also needs the applicable IFRA product category so certificate limits can be checked in the right product context.

## Recipe media

Recipes will later need media support for:

- one featured image
- a gallery of additional images

This can be added after the main formulation workflow is stable.
