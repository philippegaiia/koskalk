# Ingredient List Workflow Design

## Purpose

Make the ingredient-list area read as one clear workflow: choose a generated list, copy it directly, or place it in an editable final field and adjust it.

The interface must distinguish selectors, generated suggestions, editable final lists, and clipboard actions. These elements should not share the same visual treatment when they perform different jobs.

## Scope

This pass covers the recipe workbench ingredient-list preview for soap and cosmetics:

- INCI list variants
- Generated INCI list
- Generated plain-language list
- Editable final INCI list
- Editable final plain-language list
- Copy, use-generated, and clear actions
- Copy success and failure feedback

Declaration details remain below this workflow and retain their current calculation and table behavior.

## Information Architecture

The section header becomes **Ingredient lists** with one short instruction:

> Choose a list, then copy it or edit a final version.

Remove the header badges for the formula basis and active variant. The variant selector already communicates the active choice, so repeating it in the header adds noise.

On wide screens, the content is organized into two equal lanes. On narrow screens, the lanes stack:

1. **INCI ingredient list**
   - Variant selector
   - Generated INCI suggestion
   - Editable final INCI list
2. **Plain-language ingredient list**
   - Generated plain-language suggestion
   - Editable final plain-language list

Generated and final subsections use the same heading size, helper-text size, spacing, and action alignment in both lanes.

## Variant Selector

The selector remains a compact segmented choice because it changes the generated INCI result.

- **Saponified oils + superfat**
- **Ingredients as added**

The active choice uses the existing selected-state colors. It must not be repeated as a separate badge elsewhere.

Each variant may show one concise helper line under the INCI heading:

- Saponified oils + superfat: `Saponified oil names with the estimated unsaponified oils.`
- Ingredients as added: `Ingredients before saponification, with required allergens.`

## Generated Lists

Each generated list has the same structure:

- Subsection title
- Short purpose or basis line
- Generated text
- `Copy list` action
- `Use generated` action

The INCI and plain-language generated lists must have equal visual weight. The plain-language helper text is:

> Common names, highest amount first.

`Copy list` always means copy the text displayed in that generated subsection. Clipboard actions use this exact label everywhere.

`Use generated` places that generated text in its matching editable final field and records the current ingredient-list basis hash. It does not copy text to the clipboard.

## Editable Final Lists

Keep both editable fields because makers are expected to adjust the generated suggestions.

- **Final INCI list**
- **Final plain-language list**

Each final subsection includes:

- An editable textarea
- `Copy list`, which copies the current edited value
- `Clear`, shown only when the field contains text
- `Use generated`, available from the matching generated subsection

The existing outdated-list warning remains. It appears above the relevant textarea when the formula basis changes after the final text was saved.

## Action Vocabulary

Actions and states must use distinct visual treatments:

- Variant selectors may use rounded segmented controls.
- `Copy list` and `Use generated` use the shared compact button vocabulary, not badge styling.
- `Clear` is a quiet tertiary action and must not compete with copy or use-generated actions.
- Copy feedback appears beside the copy action as concise text: `Copied` or `Copy failed`.

There are no generic header-level copy actions because each list has its own copy target.

## Data Flow and Error Handling

The generated INCI text continues to come from the active list variant. The generated plain-language text continues to come from the plain-language labeling output.

Copy actions accept the exact target text:

- Generated INCI
- Generated plain-language
- Final INCI
- Final plain-language

If clipboard access is unavailable or fails, the relevant action shows `Copy failed` without changing either final field. Successful copies show `Copied` briefly beside the action.

Existing persistence, basis-hash tracking, stale-state warnings, print fallbacks, and calculation behavior remain unchanged.

## Responsive and Accessible Behavior

- Stack the two lanes on small and medium screens.
- Use two columns only when both lanes remain comfortably readable.
- Keep action groups next to their subsection title, wrapping below it when needed.
- Preserve visible focus states and keyboard order.
- Buttons must remain distinguishable from non-interactive status text.
- Copy feedback should be exposed as a polite live status message.

## Testing

- Blade contract tests verify removal of the two header badges and the generic header copy action.
- Contract tests verify matching INCI and plain-language subsection hierarchy.
- Interaction tests verify each `Copy list` action copies its own generated or final text.
- Interaction tests verify `Use generated` fills the matching final field without changing the other field.
- Existing tests continue to cover basis-hash tracking, stale warnings, final-list persistence, and generated list variants.
- Run the focused Pest tests, Pint, frontend build, and Graphify update.
