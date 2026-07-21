# Formula Outputs and Product Page Design

## Summary

Replace the current overloaded saved-formula page and fragmented exports with two distinct user experiences:

1. a compact, practical **Formula Sheet** for reviewing, scaling, printing, and reusing formula data; and
2. a polished, photo-led **Product page** for presenting the saved product online.

Both saved formulas and unsaved workbench drafts must feed one shared formula-document structure. This allows the authenticated workbench and the future free soap calculator to produce consistent on-screen, print, CSV, and Excel outputs without duplicating calculation logic or requiring database persistence.

The first delivery covers shared formula outputs, the authenticated Product page, free-soap-calculator output behavior, saved-history retention, and SOP snapshotting. A free cosmetic calculator, advertising placement, affiliate placement, and complete public sharing controls are separate follow-up designs.

## Current-State Findings

The existing implementation mixes several jobs:

- `recipes/version.blade.php` combines formula review, scaling, exports, saved history, production recording, production history, and packaging.
- `recipes/partials/version-sheet.blade.php` places large summary cards before the ingredient tables and splits formula settings from lye and water into oversized panels.
- Print uses one large template with production, technical, and costing modes.
- CSV exports only formula rows and lacks enough identity and basis context for reliable reuse.
- Excel contains six sheets: Summary, Formula, Packaging, Outputs, INCI Declaration, and Costing. This structure mirrors application concerns rather than a useful spreadsheet task.
- Print and export routes require a persisted `Recipe` and `RecipeVersion`, so an unsaved calculator draft cannot use them.
- The public calculator route is disabled at launch, although the shared workbench still contains a public-calculator presentation path.
- Formula snapshot retention is hardcoded. Every product keeps an editable formula, the latest saved snapshot, and three earlier recovery snapshots regardless of plan.
- The product description, featured image, and manufacturing instructions are stored on `recipes`. Manufacturing instructions are not preserved with formula snapshots.

## Terminology

User-facing terminology must use:

- **Product**: the complete saved item, including its formula, presentation, label, packaging, and production records.
- **Current formula**: the editable working state, which may differ from the last save.
- **Saved formula**: the latest stable snapshot used by the Product page, Formula Sheet, print, and export.
- **Saved history**: older automatic save snapshots retained for recovery on eligible plans.
- **Formula Sheet**: the compact, practical formula view.
- **Manufacturing procedure**: English label for the SOP content. The contextual French term is `Mode opératoire de fabrication`.

Do not expose `recipe`, `published version`, `backup`, `recovery snapshot`, or `is_current` as product terminology.

## Goals

- Put the formula and its weighing sequence at the center of the Formula Sheet.
- Remove large result cards and oversized settings panels from the top of the sheet.
- Use one aligned formula table for each soap or cosmetic formula.
- Keep calculated bench quantities below the ingredient table.
- Produce a useful working printout from either an unsaved draft or saved formula.
- Make CSV and Excel concise data-reuse formats rather than visual or database-oriented exports.
- Give saved products an attractive online presentation with photography, qualities, fatty-acid profile, and label preview.
- Preserve the SOP with each saved formula snapshot.
- Make saved-history retention plan-controlled and bounded.
- Let guests complete and print a useful soap formula before registration.
- Transfer only supported Soapkraft ingredient rows when a guest registers to save a formula.
- Keep English copy review ahead of contextual translation.

## Non-Goals

- No free cosmetic calculator in this delivery.
- No advertising or affiliate-layout implementation in this delivery.
- No WordPress documentation or editorial work.
- No Filament localization.
- No configurable document-builder system.
- No inventory, raw-material lot, or ERP expansion.
- No separate guest calculation engine.
- No automatic creation of private ingredients from guest free-text rows.
- No automatic persistence of guest formula or batch fields.
- No attempt to snapshot product photography or presentation copy with every formula save.
- No costing, packaging, or production data in the formula Excel workbook.

## Shared Formula Document

### Principle

One normalized formula-document structure feeds every formula output. Output renderers must not reimplement chemical or quantity calculations.

The structure accepts either:

- the current workbench draft, including an unsaved authenticated or guest calculation; or
- a persisted saved formula snapshot.

The existing calculation and preview services remain the calculation source. The document layer normalizes their results for presentation and export.

### Document Sections

The shared document supports:

- optional product identity;
- formula family and product type;
- scaling basis and weight unit;
- compact technical settings;
- ordered and grouped formula rows;
- calculated bench quantities;
- soap cured-composition output;
- soap qualities and fatty-acid profile;
- optional product description;
- optional manufacturing procedure;
- optional generated INCI text and declaration warnings;
- optional packaging and costing data for consumers that explicitly need them.

Each renderer selects only the sections relevant to its task. The presence of data in the shared structure does not require every output to display it.

### Consistency Rule

Given the same normalized formula inputs and scaling basis, an unsaved draft and its equivalent saved snapshot must produce identical formula rows, lye and water quantities, calculated results, cured-soap output, and soap analysis.

## On-Screen Formula Sheet

### Header

Use a compact header containing:

- product or formula name;
- saved/current state;
- saved date when applicable;
- scaling basis and unit;
- concise task actions.

Do not render large metric cards above the formula.

### Technical Settings

Render one dense, readable strip above the formula table at approximately 11–12 px supporting text. It contains settings, not ingredient quantities.

For soap, include:

- lye system;
- superfat;
- water setting;
- exposure mode;
- regulatory regime;
- other essential calculation settings only when they materially change the formula.

For cosmetics, include:

- total batch basis;
- entry mode;
- exposure mode;
- regulatory regime;
- IFRA product context when selected.

### Unified Formula Table

Soap uses one continuous table with one header:

| Ingredient | % of oils | Weight | Note |
|---|---:|---:|---|

The table contains section-divider rows in this order:

1. Saponified oils
2. Lye and water
3. Formula additions

NaOH, KOH, and water are ingredient rows. Each shows its percentage of oils and calculated weight. There are no separate Calculation or Check columns.

Cosmetics use one continuous table with:

| Ingredient | % of formula | Weight | Note |
|---|---:|---:|---|

Each cosmetic phase is a divider row. The column alignment remains constant across phases.

The table header repeats automatically only when printing across page boundaries; it does not repeat for every section or phase on screen.

### Calculated Results

Place calculated results below the complete ingredient table. Keep this section compact and limited to bench-useful quantities.

Soap examples:

- wet batch weight;
- estimated weight after cure;
- produced glycerine;
- total additions or another directly useful batch total.

Cosmetic examples:

- total batch quantity;
- formula total;
- ingredient total or another directly useful balance result.

Soap qualities, fatty-acid profile, label output, restrictions, and compliance do not belong in this result strip.

### Additional Sections

When present, the Formula Sheet may continue with:

- product/formula description;
- manufacturing procedure;
- current generated INCI list;
- relevant direct warnings.

These sections are omitted cleanly when data is absent.

## Working Printout

### Purpose

The working printout is taken to the bench for a trial or a real batch. It may be generated from an unsaved formula and does not create a production record.

### Page One

Include:

- formula identity when available;
- compact technical settings;
- the aligned formula table;
- calculated bench quantities;
- description when available;
- manufacturing procedure when available;
- generated label text when available;
- blank trial or batch number;
- blank date, made-by, and checked-by fields;
- blank observations and result areas.

These blank fields are for handwriting. Values entered for printing are not persisted as production data.

The manufacturing procedure should render authored rich text cleanly. A later structured checklist may be designed separately; this delivery does not require converting existing rich text into structured steps.

### Soap Analysis Page

The soap analysis page contains compact tables for:

- Soapkraft qualities, with value, suggested range, and concise status;
- fatty-acid profile, with fatty acid, percentage, and concise practical contribution;
- compact calculation assumptions and limitations.

Do not include long interpretive paragraphs.

The free calculator includes this as page two by default. Registered users may choose whether to include it when printing a bench copy.

## Product Page

### Purpose

The Product page is the attractive, permanent online record. It answers what the product is, while the Formula Sheet answers how to make it.

### Overview

Use an e-commerce-inspired, photo-led layout containing:

- featured finished-product image;
- product name;
- family and product type;
- current saved state and saved date;
- product description;
- concise formula basis metadata;
- direct actions such as Edit formula, Print, and Export;
- compact Soapkraft qualities and fatty-acid profile for soap;
- current INCI label preview, including declarable allergens in the generated list.

Do not duplicate the complete formula table on the Overview.

### Focused Navigation

The owner-facing Product page uses focused destinations:

- Overview
- Formula
- Label
- Manufacturing
- Packaging
- Production
- Saved history, when the plan provides history

Formula opens the one canonical Formula Sheet. It is not a second formula implementation.

Manufacturing renders the current SOP sourced from the same content edited in the workbench's Instructions & Media area.

Saved history is hidden for free plans rather than filling the Product page with a locked or empty section.

### Cosmetics

The cosmetic Product page uses the same hero and navigation structure. It omits soap qualities and fatty-acid profile. Cosmetic-specific technical presentation may be designed later from data that the application can support accurately.

## CSV Export

CSV is a clean ingredient-batch table intended for import or manipulation in another tool.

Columns:

- section or phase;
- ingredient;
- percentage basis;
- percentage;
- scaled weight;
- unit;
- note.

Do not include internal `Platform` or `User` source values. Do not include presentation, packaging, costing, history, or compliance prose.

## Excel Export

Excel exists only to reuse structured formula data. The current six-sheet workbook is replaced.

### Ingredient Batch Sheet

Both soap and cosmetic workbooks contain one **Ingredient batch** sheet.

For soap it contains:

- saponified oils;
- lye and water;
- formula additions.

For cosmetics it contains ingredients grouped by phase.

The sheet includes compact identity and scaling context plus the same aligned formula columns used by CSV. It does not include photography, presentation copy, manufacturing procedure, packaging, costing, declarations, qualities, or fatty-acid profile.

### Soap Output Sheet

Soap workbooks add one **Soap output** sheet containing reusable post-saponification data:

- cured-soap composition rows;
- component role;
- percentage of cured-soap basis;
- calculated weight;
- cured-basis and residual-water assumptions;
- final INCI ingredient list, including declarable allergens.

Do not add separate allergen, restriction, soap-quality, fatty-acid, packaging, costing, Summary, or generic Outputs sheets.

Cosmetic workbooks contain only the Ingredient batch sheet because the cosmetic formula already represents the finished composition.

## Free Soap Calculator

### Guest Ingredient Rules

Saponified oils are restricted to active Soapkraft carrier oils with the validated SAP data required for the calculation. A guest cannot create or free-type a saponified oil.

Formula additions accept either:

- a Soapkraft catalog ingredient; or
- a one-off custom ingredient used only in the current guest calculation and printout.

Custom additions do not receive invented chemistry, fatty-acid, INCI, allergen, restriction, or compliance data.

### Guest Outputs

Guests may:

- calculate live;
- review lye, water, batch quantities, cured-soap output, qualities, fatty-acid profile, and label limitations;
- print the working Formula Sheet and soap-analysis page without registration.

Printing calculated lye values requires a valid saponification core, including a complete 100% oil blend and valid SAP data.

Ordinary calculator use creates no product, formula, saved history, or batch record.

### Registration Handoff

`Save this formula` begins registration. Only Soapkraft ingredient rows and supported formula settings transfer.

One-off guest additions:

- carry a visible **Temporary** marker from creation;
- state that they are used only in the current calculation and printout;
- appear in the guest printout;
- do not transfer into the registered account;
- do not become private ingredients automatically.

Before registration, show two explicit lists:

- rows that will be saved; and
- temporary rows that will be removed.

Warn that the registered formula will recalculate without temporary rows. Offer the user an immediate way to print the complete guest trial before continuing.

After registration, the user may create private ingredients and add them to the saved formula manually.

The transfer draft is created only when the guest chooses to save. Passive calculator use is not persisted.

### Monetization Boundary

Advertising and clearly disclosed affiliate placements must remain outside:

- formula controls;
- safety warnings;
- calculated quantities;
- output tables;
- registration-transfer confirmation;
- printed documents.

Exact advertising, affiliate, analytics, and conversion placement requires a separate design.

## Saved Formula and Saved History

### Core State

Keep the existing conceptual split between:

- one mutable current formula; and
- one immutable latest saved formula snapshot.

Immediately after a save, these records contain equivalent formula data. They may then diverge as the user edits the current formula. The stable saved snapshot continues to feed the Product page, printing, and exports until the next save.

### Free Retention

Free registered plans retain at most two formula-version records per product:

- one current editable formula;
- one latest saved formula snapshot.

Saving again replaces the previous saved snapshot. No earlier saved history accumulates.

### Paid Retention

Eligible paid plans retain at most five formula-version records per product:

- one current editable formula;
- one latest saved formula snapshot;
- three earlier saved-history snapshots.

When another save occurs, the oldest unprotected history snapshot is removed.

History capacity must come from plan entitlement data rather than a hardcoded constant.

### Production Safety

History pruning must never invalidate a recorded production batch or its frozen data. Existing production snapshots remain authoritative historical records. Implementation must confirm foreign-key and deletion behavior before pruning any formula snapshot referenced by production.

### Plan Changes

Upgrading begins retaining history on subsequent saves. Downgrade cleanup policy is not part of this delivery and must not silently destroy production-linked data.

## SOP Snapshotting

The current manufacturing procedure remains editable at product level for the current Product page and workbench.

When the formula is saved, snapshot the manufacturing procedure with the saved formula. Therefore:

- current Product page Manufacturing shows the current product SOP;
- the current saved Formula Sheet uses the SOP captured with that save;
- older paid saved-history snapshots retain their corresponding SOP;
- free users retain only the SOP attached to the latest saved formula;
- historical and production-linked documents do not silently display a newer SOP.

Product photography and presentation description remain product-level content and are not formula-versioned in this delivery.

## Sharing Boundary

The Product page should be structurally ready for controlled sharing. If sharing is enabled, the default shared projection may include:

- product photograph;
- product name, type, and presentation description;
- soap qualities and fatty-acid profile;
- label preview.

The following remain private unless a future explicit owner-controlled sharing design allows them:

- exact formula and weights;
- SOP;
- costing;
- packaging details;
- production history;
- saved history.

This delivery does not require implementing public or shared-link controls.

## Error and Empty States

- Missing featured image: render a deliberate neutral media area without a broken image.
- Missing description: omit the description block without leaving an empty card.
- Missing SOP: show a concise owner-facing empty state in Manufacturing and omit it from print.
- Missing generated label: identify that the label has not been generated; do not show stale fallback content as final.
- Incomplete soap calculation: do not print lye quantities; explain what must be corrected.
- Missing SAP data: prevent the ingredient from entering the saponification section and explain why.
- Temporary guest additions: identify their limitations at creation, in relevant output, and before registration transfer.
- Output generation errors: remain read-only and leave the formula unchanged.
- Excel and CSV generation: never mutate formula, costing, packaging, or batch state.

## Localization and Content Ownership

Review and approve the complete English interface copy before translation.

After approval:

- interface labels, actions, statuses, warnings, and empty states use Laravel/Spatie keys;
- French, Spanish, German, Italian, and Dutch receive contextual translations;
- user-authored product names, descriptions, SOP content, notes, and temporary ingredient names are not translated;
- ingredient, product-type, regulatory, and other catalog names remain platform data;
- generated INCI remains scientific/label data rather than ordinary interface copy;
- Filament remains English-only;
- detailed methodology remains future WordPress documentation, not long embedded interface copy.

## Verification

Automated coverage must prove:

- equivalent draft and saved inputs produce identical normalized formula-document values;
- guest calculation and printing create no product, formula, batch, or history records;
- invalid saponification inputs cannot generate working lye printouts;
- Soapkraft and temporary guest rows behave differently and visibly;
- registration transfers only supported Soapkraft rows;
- the handoff recalculates after excluding temporary rows;
- free retention keeps only current plus latest saved formula;
- paid retention keeps current, latest saved, and three earlier history snapshots;
- pruning does not damage production records;
- saved formula snapshots retain the correct SOP;
- historical Formula Sheets use historical SOP content;
- Product Overview does not duplicate the complete Formula Sheet;
- sharing authorization never exposes formula, SOP, costing, packaging, production, or history by default;
- soap and cosmetic formula tables use the approved percentage basis and aligned structure;
- CSV uses the approved columns and excludes internal source fields;
- cosmetic Excel contains exactly the Ingredient batch sheet;
- soap Excel contains exactly Ingredient batch and Soap output;
- soap qualities and fatty-acid profile do not appear in Excel;
- the Soap output INCI text includes qualifying declarable allergens;
- all guest and authenticated output routes enforce authorization and input limits.

Manual checks must cover:

- desktop and mobile Product page layout;
- desktop and mobile Formula Sheet density;
- multi-page printing with repeated table headers;
- soap and cosmetic formulas with long ingredient and phase names;
- formulas using NaOH, KOH, and dual lye;
- missing image, description, SOP, and label states;
- browser printing from the free calculator;
- registration handoff with both supported and temporary ingredients;
- spreadsheet usability in Excel-compatible software.

## Delivery Sequence

1. Introduce and test the shared formula-document data structure without changing visible output.
2. Rebuild the on-screen Formula Sheet from that structure.
3. Rebuild the working printout and soap-analysis page.
4. Replace CSV and Excel with the approved compact formats.
5. Snapshot SOP content with saved formulas.
6. Make saved-history retention entitlement-driven.
7. Build the photo-led Product page and focused navigation.
8. Restore the public soap calculator using the shared output and temporary-ingredient rules.
9. Add registration handoff for supported Soapkraft rows.
10. Complete English review, contextual translation, automated verification, browser checks, and deployment documentation.

The free cosmetic calculator and monetization layout begin only after this delivery is stable and each receives its own design review.
