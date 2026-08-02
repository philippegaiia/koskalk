# Workflow Action Bars Design

## Purpose

Make form actions predictable across Soapkraft. Long forms must keep every relevant action visible without making the controls resemble badges or filters.

## Scope

The first pass covers:

- Supplier create and edit
- Supplier listing create and edit
- Supplier and supplier-listing browse-page actions
- Ingredient create and edit
- Packaging item create and edit
- Ingredient and packaging library header actions

Other workflow actions can migrate to the same pattern when their views are next changed. Pills remain appropriate for filters, statuses, segmented controls, and compact selectors.

## Action Bar

Long editable forms use one compact sticky action surface near the bottom of the viewport. The surface has:

- A lightly translucent panel background
- A subtle structural border
- A restrained warm shadow
- A compact container radius consistent with app panels
- Enough horizontal and safe-area spacing to remain usable on mobile

The surface must read as one toolbar. It must not stretch its visible controls across an unframed full-width area.

## Action Placement

For edit forms:

- Destructive action on the left
- Cancel and primary save action grouped on the right

For create forms:

- Cancel and primary create or save action grouped on the right
- No empty destructive-action area

On narrow screens, the toolbar may wrap while preserving the destructive-versus-commit grouping. Actions must not overlap form controls or safe-area insets.

## Button Vocabulary

Workflow actions use the shared `sk-btn` primitive with an 8px radius and consistent height and padding.

- Save, Create, Add: primary button
- Cancel, Back, Edit: ghost or outline button according to local emphasis
- Delete, Remove, Deactivate: outlined danger button

Workflow actions must not use `rounded-full`. Pill shapes remain reserved for status badges, filters, segmented controls, and compact selectors.

## Reuse

Create a reusable Blade action-bar component that owns the sticky positioning and visual surface. Views provide left and right action slots. Existing `sk-btn` variants remain the button source of truth; no second button system is introduced.

## Accessibility

- Maintain visible keyboard focus states.
- Preserve a minimum 40px control height.
- Keep destructive actions clearly labelled and confirmed before execution.
- Keep logical DOM and keyboard order: destructive action, cancel, primary action.
- Ensure wrapped mobile actions remain reachable without horizontal scrolling.

## Testing

- Component rendering tests verify the shared action surface and slots.
- Supplier and supplier-listing page tests verify Delete is inside the action bar on edit pages.
- Create-page tests verify no destructive action is rendered.
- Ingredient and packaging editor tests verify use of the shared action bar and `sk-btn` actions.
- A source-level regression check prevents `rounded-full` on workflow actions covered by this pass.
- Run the relevant feature tests, Pint, frontend build, and Graphify update.
