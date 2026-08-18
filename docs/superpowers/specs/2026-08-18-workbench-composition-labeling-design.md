# Workbench Composition and Labeling Design

**Date:** 2026-08-18

## Purpose

Restore a clear separation between formula-derived composition and labeling output, product identity, and Production Bench configuration.

The Workbench must remain useful to people who never activate or use Production Bench. It should not require them to understand readiness delays, production release, internal references, or other production-administration concepts.

## Problem

The current **Product output** tab combines two unrelated directions of information:

- calculated results flowing out of the formula, including composition, ingredient declarations, allergens, restrictions, and labeling guidance; and
- editable product and production configuration, including output type, a receiving ingredient, readiness delay, product reference, nominal content, and format duplication.

This overloads the meaning of “output” and places production concerns before the composition and labeling results that originally defined the tab.

## Approved Terminology

Rename the Workbench tab:

- English: **Composition & labeling**
- French: **Composition et étiquetage**

Ask the identity question in plain language:

> **This formula produces:**

- **Product**
- **Ingredient**

Do not describe an ingredient by whether it is reused in another formula or sold. An ingredient may be consumed internally, sold, or both.

The existing internal enum values `finished_product` and `manufactured_ingredient` remain unchanged. The design changes user-facing terminology, not the domain storage contract.

## Workbench Information Architecture

The authenticated Workbench keeps these primary destinations:

1. Formula
2. Packaging
3. Costing
4. Composition & labeling
5. Instructions & media

### Formula settings

Place the Product/Ingredient decision at the top of the existing Formula settings panel.

New products already open Formula settings by default, so the user encounters the decision at the beginning of formulation. Saved products keep Formula settings collapsed, and the compact settings summary displays the current result as `Produces · Product` or `Produces · Ingredient`.

The public calculator does not display this decision because it does not create a product record.

When **Product** is selected:

- clear any selected receiving ingredient;
- show no additional fields in this identity section.

When **Ingredient** is selected:

- show the existing workspace ingredient selector;
- allow the user to create the ingredient inline;
- keep the current requirement that the selected ingredient belongs to the workspace, is active, and is marked as manufactured internally.

Use concise labels such as **Ingredient produced** and **Create an ingredient**. Do not mention stock release, another formula, or a separate product.

### Packaging

Packaging remains a dedicated tab. The Product/Ingredient decision does not absorb packaging configuration or add another packaging workflow.

### Composition & labeling

The renamed tab contains formula-derived information only:

- full formula or cured-soap composition;
- formula quantities and percentage bases;
- generated and finalized ingredient-list variants;
- INCI and plain-language labeling output;
- declared allergens;
- restrictions, warnings, assumptions, and labeling limitations.

Remove all editable product identity and production configuration from this tab.

## Removed Workbench Controls

Do not show these controls in the general Workbench:

- product reference or internal SKU;
- nominal content;
- product-specific ready-date delay;
- duplicate-for-another-format action inside the output section.

Product duplication remains available through the existing product-level action in the Workbench header. Packaging continues to be managed in the Packaging tab.

This change does not delete database columns or backend round-trip support for product reference, nominal content, or a saved ready-delay override. Existing stored values remain intact when a product is edited and saved. Removing those persistence fields or relocating them to a future product-details experience is outside this change.

## Production Bench Boundary

Do not alter Production Bench behavior.

Production Bench already stores workspace-level readiness defaults for soap and cosmetics. A product-specific ready-delay override, when already present, continues to take precedence internally even though the general Workbench no longer exposes it.

When a production run is created, it continues to freeze:

- the published formula and scaled formula lines;
- output type;
- receiving ingredient when the output is an ingredient;
- effective readiness delay;
- estimated ready date and other run context.

The product/formula remains editable afterward. Existing production runs retain their snapshots, while future runs use the product’s current configuration. Do not add a lock, migration warning, or restriction when the user changes Product to Ingredient or Ingredient to Product.

## Persistence

Keep the current persistence model:

- Product/Ingredient identity remains current product-level configuration on `recipes`.
- Formula versions continue to snapshot formula, labeling, instructions, and packaging.
- Production runs continue to snapshot output identity and effective readiness configuration when created.

Loading a historical formula version may continue to combine that version’s formula data with the product’s current output identity. Changing this relationship is outside the scope of this design.

## Accessibility and Interaction

- Keep the output-type control as a labeled radio group.
- Continue to expose the selected state through `aria-checked`, not color alone.
- Keep Product selected by default for a new authenticated product.
- Clear the receiving ingredient immediately when Product is selected.
- Preserve keyboard-operable buttons and native select behavior.
- Keep inline ingredient-creation status and errors announced in the existing status area.

## Translation

English remains the source of truth in `lang/en/workbench.php`. Update the DB-backed interface-translation seed data for all existing locales, including the approved French labels.

Remove production-oriented help copy from the visible Workbench identity selector. Persistence and validation messages may retain their existing internal terminology where they are not normally displayed.

## Testing

Update focused Workbench tests to prove:

- authenticated soap and cosmetic Workbenches show the Product/Ingredient selector in Formula settings;
- the selector is absent from the public calculator;
- Composition & labeling replaces Product output in navigation and localized overrides;
- the Composition & labeling tab no longer includes production-output settings;
- saved Product/Ingredient configuration still round-trips through save, reload, and duplication;
- selecting Ingredient still requires a valid workspace manufactured ingredient;
- the existing production-run snapshot tests remain unchanged and passing.

## Non-Goals

- No database migration or column removal.
- No Production Bench workflow or snapshot change.
- No new lock on output-type changes.
- No redesign of the Packaging tab.
- No redesign of formula composition, label lists, allergens, or restriction panels.
- No new product-variant model.
- No relocation of product reference or nominal content into another screen in this change.
