# Soap Workbench Summary Polish Design

## Objective

Make the lower soap-workbench summaries calmer, more consistent, and easier to scan without changing the established ingredient-rail layout or moving chemistry information between panels.

The requested surfaces are soap-specific. The cosmetic bench remains unchanged unless implementation reveals a genuinely shared presentation primitive that can be adjusted without changing its appearance.

## Product Decisions

- Keep the Soapkraft qualities grid at four cards per row on wide desktop displays.
- Use two meaningful quality tabs: **Bar & cure** and **Lather & feel**.
- Keep fatty-acid groups, iodine, INS, and the saturated/unsaturated summary in the existing fatty-acid panel.
- Make the complete Soapkraft qualities section collapsible, open by default.
- Remember the collapsed or expanded choice locally in the browser, scoped to the authenticated user. The preference does not follow the user to another browser or device.
- Keep the current workbench width constraints and ingredient-rail structure unchanged.

## Batch Totals

The Batch totals row keeps its current four-column wide-screen layout and responsive two-column layout.

- Remove the artificial minimum card height that creates excessive empty space below the values.
- Use content-driven height with balanced top and bottom padding.
- Keep the numeric values at 20 px.
- Preserve consistent spacing between each eyebrow and its value.
- At the two-column breakpoint, cards in the same row must remain aligned when one eyebrow wraps to two lines. The value spacing is measured from a consistent label area rather than from the last text baseline, preventing uneven card heights or vertically drifting values.
- Existing borders, responsive stacking, and semantic content remain unchanged.

## Soapkraft Qualities

### Section disclosure

Add a compact disclosure control to the section header.

- The section is expanded for a user with no saved preference.
- Activating the control collapses or expands the complete qualities content while leaving the section heading visible.
- The control exposes its expanded state to assistive technology and has a clear text or accessible label.
- The preference is stored in browser storage under a key that includes the authenticated user identifier, preventing two accounts using the same browser from sharing the choice.
- The control introduces no database column, server request, or cross-device synchronization.
- The transition is restrained and respects reduced-motion preferences.

### Tabs and grouping

Replace the current Qualities and Advanced split with two usage-oriented groups.

**Bar & cure** contains:

- Unmolding firmness
- Cured hardness
- Longevity
- Cure speed
- DOS risk

**Lather & feel** contains:

- Cleansing strength
- Mildness
- Bubble volume
- Creamy lather
- Lather stability
- Conditioning feel
- Slime tendency

The underlying calculation keys and values remain unchanged. This is a presentation regrouping only.

### Cards

- Keep four cards per row at the existing wide-screen breakpoint.
- Keep two cards per row at the existing smaller desktop/tablet breakpoint and one card per row where the current responsive layout requires it.
- Render the individual quality names in the existing eyebrow style at 11 px.
- Render quality numbers at 20 px rather than 24 px.
- Preserve the progress indicator, target copy, semantic state colors, and accessible meaning.
- Allow long eyebrow labels to wrap naturally without clipping. Consistent label space keeps values aligned across a row.
- Keep the compact formula observations below the cards visually discreet.

## Fatty-Acid Profile

Keep the panel's information architecture intact.

- Do not move fatty-acid groups, iodine, INS, or saturated/unsaturated values into Soapkraft qualities.
- Keep the current full-width mobile presentation, where group names already fit.
- On constrained desktop rows, retain the compact single-line label and add the full group name through the native `title` attribute. This avoids adding a tooltip library or new JavaScript behavior.
- The visible label may truncate only when space is genuinely constrained; hovering it reveals the complete wording.

Replace the uniformly dark group treatment with five distinct, restrained color families:

- Quick-cleansing saturated fats: warm amber
- Hard saturated fats: muted clay
- Monounsaturated fats: leaf green
- Polyunsaturated fats: quiet blue
- Special lather fats: muted violet

Use the stronger color for the marker and a pale tint with dark matching text for the abbreviation badge. Colors must remain distinguishable against the panel surface, preserve readable contrast, and not carry warning or success semantics.

## Formula Table Information Control

Reduce the ingredient information control to a 36 px square while preserving:

- its current icon and action;
- a clear focus indicator;
- an accessible name;
- adequate separation from adjacent controls;
- the existing responsive table behavior.

The change applies to the relevant formula-table information control. Other unrelated information icons are not resized globally.

## Accessibility and Responsive Behavior

- Disclosure and tabs remain keyboard operable.
- The disclosure communicates expanded state.
- The active quality tab remains visually and programmatically identifiable.
- Native fatty-acid titles supplement, rather than replace, visible labels.
- Eyebrows remain 11 px and retain sufficient contrast and letter spacing.
- Two-line Batch totals labels do not cause uneven vertical rhythm at reduced widths.
- Four quality cards are used only where the existing wide workbench has enough room; current responsive fallbacks remain intact.

## Testing and Verification

Automated coverage should prove:

- quality values use the 20 px presentation contract;
- quality labels use the 11 px eyebrow contract;
- the wide quality grid retains four columns;
- the two new tabs contain the agreed quality rows;
- the qualities disclosure defaults open and saves a user-scoped browser preference;
- Batch totals use the compact, wrapping-safe layout contract;
- fatty-acid group labels expose their full names through native titles;
- the formula-table information control uses the 36 px sizing contract.

Run the focused Pest tests for the recipe workbench, the frontend build, and `graphify update .`. Check the soap bench manually at wide desktop, the two-column totals breakpoint, and mobile width. Verify keyboard interaction and the saved disclosure state in the browser.

## Out of Scope

- Moving the fatty-acid summary into Soapkraft qualities.
- Changing iodine, INS, or quality calculations.
- Persisting the disclosure preference in the database or across devices.
- Redesigning the cosmetic bench.
- Changing the established ingredient rail or workbench width limits.
- Introducing a JavaScript tooltip library.
