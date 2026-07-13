# Ingredient Catalog Usage and Formula Recovery Design

Date: 2026-07-13

## Objective

Make ingredient limits and deletion constraints understandable, restore access to saved formula versions, restore the missing formula contents on the formula sheet, and correct the authenticated page shell's excessive trailing scroll.

## Ingredient catalog

### Private ingredient allowance

The ownership filter remains compact:

- `All ingredients`
- `Mine (13)`
- `Platform`
- `Priced`

The catalog controls also show `13 of 20 private ingredients`. The used count and plan limit come from `EntitlementService`, so the display follows the same rules that enforce creation. If a future plan has no limit, the allowance copy falls back to `13 private ingredients`.

At the limit, the count receives warning treatment and the add action explains why another private ingredient cannot be created. Server-side entitlement enforcement remains authoritative.

### Ingredient usage and deletion

An unused private ingredient retains the standard delete action and confirmation.

When a private ingredient is referenced by a current or recoverable recipe version, or by a costing record tied to a recipe version, deletion remains unavailable. A disabled delete icon alone is insufficient because disabled controls are not reliably focusable and hover-only explanations are inaccessible.

Instead, the row presents a focusable `Used in N formulas` control in the actions area. Activating it reveals a compact disclosure containing:

- each affected formula once, even if several versions reference the ingredient;
- a link to the active formula;
- an optional saved-version count, such as `Used in 3 saved versions`, when it explains historical usage;
- a clear statement that the ingredient cannot be deleted while those recoverable records depend on it.

Formula names are restricted to recipes accessible by the signed-in user. The server continues to reject forced deletion requests.

## Formula versions

### Default formula sheet

The normal `Formula sheet` action always opens the active saved formula for the recipe.

### Version history

The formula sheet restores a compact, collapsed `Version history` disclosure. Each older saved version shows its name or version label and saved date. The user can:

- view the historical version;
- restore it to the current editable formula.

Restoring requires confirmation when it would replace different current work. The operation creates or refreshes the current editable state without deleting the historical snapshot.

### Historical formula preview

Opening a version from history displays that selected version, not the active version. The page clearly labels it `Previous version` and offers a route back to the active formula. Scaling, printing, and exporting from this page use the selected historical snapshot, allowing the user to verify it before restoration.

The existing historical version route must pass the selected `RecipeVersion` into the view-data builder. It must not resolve the recipe's active version again.

## Formula-sheet contents

The formula sheet restores the saved formula itself immediately after the header and version history, before production and packaging.

Existing formula-sheet presentation is reused rather than introducing a second table vocabulary. It includes:

- saved recipe settings;
- lye and water amounts for soap formulas;
- ingredient rows grouped by phase;
- ingredient name, INCI, percentage, scaled batch weight, and note;
- final INCI and plain-language ingredient lists;
- product description and manufacturing instructions when present.

The existing reusable version-sheet view is decomposed or reused so that summary cards and ingredient-list output are not rendered twice. Cosmetic formulas omit soap-only lye content through the existing view data.

## Authenticated page shell

The authenticated application does not gain a marketing-style footer. The page shell must occupy at least the dynamic viewport height, the sidebar must visually span that height, and the document must end shortly after the page content.

The excessive trailing scroll is treated as a layout defect. Investigation will identify the element increasing document height before changing CSS. Development-only injected tooling is distinguished from application layout so production behavior is not patched around a local widget.

## Data flow

`IngredientsIndex` receives two additional read models:

1. Private ingredient entitlement usage from `EntitlementService`.
2. Accessible recipe usage grouped by ingredient and recipe, traversing recipe items and costing items through recipe versions.

Queries eager-load only the usage needed for ingredients on the current page and deduplicate recipes in the database or collection layer. They must avoid one query per ingredient.

`RecipeController` continues to use `RecipeVersionViewDataBuilder` for both active and historical sheets. The active route resolves the active saved version. The historical route resolves and passes the requested saved version directly.

## Accessibility and responsive behavior

- Usage explanations work with keyboard, pointer, and touch.
- The usage control exposes expanded state and associates itself with its disclosure.
- Formula links have descriptive accessible names.
- Version history uses native disclosure semantics or an equivalent keyboard-accessible control.
- On narrow screens, usage details and version actions stack without forcing horizontal page scrolling.
- Ingredient data tables may scroll horizontally within their own wrapper.

## Error handling

- Forced ingredient deletion remains rejected when any protected relation exists.
- Missing or inaccessible recipes are never exposed in usage details.
- A stale usage disclosure does not authorize deletion; the deletion method rechecks relationships.
- A missing or inaccessible historical version returns `404`.
- Restore confirmation protects unsaved or different current formula content.
- Empty version history is omitted rather than showing an empty panel.

## Testing

Feature and Livewire tests will verify:

- `Mine (N)` and `N of limit private ingredients` use entitlement data;
- unlimited fallback copy remains safe even though current plans are limited;
- ingredient usage is grouped by formula and includes historical-version references;
- costing-only references resolve to their formula;
- users cannot see another user's formula usage;
- unused ingredients remain deletable and referenced ingredients remain protected server-side;
- the active formula-sheet route renders the active saved version;
- the historical route renders the explicitly selected version;
- version history and restore actions are visible only when applicable;
- the formula sheet renders phase ingredient rows and scaled weights;
- soap and cosmetic formula presentations retain their category-specific content;
- the app shell includes the corrected viewport-height contract without introducing a footer.

The focused Pest tests run first, followed by the relevant ingredient and recipe test groups. Frontend assets are built, PHP is formatted with Pint, Filament checks run if any Filament files change, and the graph is refreshed after code changes.

## Out of scope

- Deleting or rewriting historical recipe versions solely to free an ingredient.
- A separate ingredient-usage page.
- A dedicated full-page version-management interface.
- Changes to plan pricing or limit values.
- Adding a footer to the authenticated application.
