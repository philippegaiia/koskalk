# Ingredient Composition Quick Create and Review Remediation

## Objective

Make blend composition editing reliable and keyboard accessible while allowing users to create a missing component without leaving the ingredient editor. Quick-created ingredients are private user records that can be enriched later.

## Product Decisions

- A quick-created component requires only a name and category.
- INCI and supplier details remain optional for user-owned ingredients.
- The current search text becomes an editable suggested name only after the user chooses to create an ingredient.
- New ingredients are active by default in quick creation, normal user creation, and admin creation.
- Legacy per-row source notes are preserved when a resync omits the `source_notes` key. An explicitly submitted blank value clears the legacy row note.
- The migration's first-non-empty-source backfill remains unchanged.
- The unrelated `.impeccable/critique/` file remains untouched.

## Composition Search and Quick Creation

The composition search remains a combobox. It lists active ingredients accessible to the current user and excludes the ingredient currently being edited.

When the user cannot find a suitable ingredient, a **Create ingredient** action expands a compact form directly beneath the search. The form contains:

- **Name**, initialized from the current search text and fully editable.
- **Category**, required and selected by the user.
- **Create and add** and **Cancel** actions.

Nothing is created from search text alone. The user must open the creator, confirm or edit the name, choose a category, and submit.

Before creating a record, the Livewire component checks that the composition has room for another row. On success it uses `UserIngredientAuthoringService::createInlineComponent()` to create an active private ingredient, appends that ingredient to the composition state, clears the search and quick-create state, and rerenders. The rerender queries ingredient options again, so the new record is immediately available without a page refresh.

The newly added composition row starts with an empty percentage. The user completes the share as part of the existing composition workflow. The full ingredient can be edited later through the normal ingredient editor.

## Combobox Accessibility

Keyboard focus remains in the search input while navigating options:

- **Down Arrow** opens the list and advances the active option.
- **Up Arrow** opens the list and moves to the previous option.
- **Enter** selects the active option. If no option is active, it selects the first filtered option.
- **Escape** closes the list and clears the active option.

The input exposes `aria-controls`, `aria-expanded`, `aria-activedescendant`, and an appropriate autocomplete mode. Each option has a stable DOM identifier and reflects its selected or active state. Focus remains visibly distinguishable for the search input, toggle, options, and quick-create controls.

## Persistence and Validation

Livewire public state is untrusted. Both `IngredientEditor::addComponent()` and `UserIngredientAuthoringService::validateBlendComponents()` enforce that component ingredients are active and accessible to the current user. Server-side validation remains authoritative even if an ingredient is deactivated after it was added to the form.

The custom composition view owns `components` and `composition_source_notes` in Livewire state rather than Filament schema fields. `IngredientEditor::save()` will obtain persistence state through a descriptively named helper that merges these custom values into the dehydrated Filament form state. This keeps the workaround explicit and centralized without adding a fragile explanatory inline comment.

The displayed composition total is calculated by PHP with `NumberLocale::parseDecimalInput()`. Livewire updates after percentage edits, making the server result authoritative and removing the separate Alpine decimal parser.

For legacy child-row sources in `IngredientDataEntryService`:

- Missing `source_notes` key: retain the existing value for the same child record.
- Present non-blank `source_notes`: save the submitted value.
- Present blank or null `source_notes`: clear the existing value.

This distinction protects curation evidence when the new UI omits per-row fields while preserving an intentional clearing mechanism for service callers and future tooling.

## Error Handling

- Name and category errors render inside the expanded quick creator and preserve its entered state.
- Creation is not attempted when the composition already contains 20 rows.
- An inaccessible or inactive selected ingredient produces the existing composition-level validation error.
- Duplicate component selection remains rejected before state is changed.
- A successful quick creation always creates an active ingredient and immediately adds it to the local composition.

## Test Strategy

Pest feature tests will cover:

1. Normal user creation produces an active ingredient.
2. Quick creation with only name and category produces an active private ingredient.
3. Quick creation immediately appends the new ingredient to the composition state.
4. Quick-create validation preserves entered values and creates no record when required data is missing.
5. A component deactivated after selection is rejected by server-side persistence validation.
6. Omitted legacy per-row source notes are preserved.
7. Explicitly blank legacy per-row source notes are cleared for fatty-acid, allergen, and composition rows.
8. The composition view renders the expected combobox accessibility attributes and stable option identifiers.
9. Locale-aware percentage input is reflected in the server-calculated total.

The affected ingredient tests will be run with `php artisan test --compact` using focused files or filters. Because Filament and PHP files are modified, final verification also includes `vendor/bin/filacheck --fix`, `vendor/bin/pint --dirty --format agent`, the focused ingredient test suite, and `graphify update .`.

## Out of Scope

- Embedding the complete ingredient editor inside the composition workflow.
- Requiring INCI, supplier, chemistry, or compliance data for quick-created user ingredients.
- Cross-tab synchronization for the separate full ingredient editor.
- Removing legacy child-row source columns or changing seeder generation.
- Deleting or ignoring unrelated critique artifacts.
