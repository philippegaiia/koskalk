# Production Bench Operational Copy Design

## Goal

Make every Production Bench view read like an operating tool, not a product page.

## Copy rule

Visible text is limited to:

- page and section identity;
- field and table labels;
- actions and statuses;
- consequences of read-only or inactive states;
- short help where the user could otherwise enter the wrong data.

Remove introductions, reassurance, feature explanations, repeated headings, future-feature references, and sentences that explain an obvious control.

## View treatment

- **Home:** show `Production Bench`, the current state, the state-changing action, and factual counters. Use `Quarantined` and `Incoming`; remove explanatory subtitles.
- **Inventory:** retain the short physical/reserved/available/negative-stock distinction because it prevents stock mistakes. Use `Opening stock`, `Stock positions`, and `No lots.` Remove conversational headings and instructional empty states.
- **Suppliers:** use `Suppliers`, `Search`, `Status`, and `Sort`. Keep only operational table labels and actions.
- **Supplier detail:** keep supplier data, contacts, address, notes, listings, and actions. Avoid duplicated context labels where the supplier name or section already establishes context.
- **Supplier listings:** use `Supplier listings`, `Search`, `Supplier`, `Type`, and `Status`. Keep purchase and price data.
- **Forms:** retain the supplier-code format constraint, purchase format example, minimum-order meaning, and calculated price. Remove redundant descriptions and explanatory sentences.
- **Inactive/read-only:** use `Inactive` and `Read-only. Resume to edit.` Link back with `Production Bench`.
- **Empty states:** use `No suppliers.`, `No listings.`, and `No lots.`

## Non-goals

- No changes to layout, fields, calculations, purchasing behavior, inventory behavior, or access control.
- No new tooltips, onboarding, promotional copy, or future-feature placeholders.

## Verification

Rendered-page tests will assert the required operational labels and reject known promotional, explanatory, and future-facing phrases across all Production Bench routes.
