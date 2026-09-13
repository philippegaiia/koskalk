---
paths:
  - 'resources/{js,views}/**/recipe-workbench/**'
---

# Jsviews Recipe Workbench

## Keep cosmetic phase selection single-open and row-targeted
Only one ingredient phase chooser may be open at a time; opening another closes the previous one without changing fixed-position repositioning. After adding an ingredient, retain the destination phase/section highlight but scroll to and highlight the newly inserted row after it renders; do not scroll the phase container before row insertion.

## Focus added amount only on fine-pointer desktop workbenches
After a successful animated ingredient insertion, preserve the existing phase/section highlight and centered row scroll. On fine-pointer desktops at min-width 1024px, focus the newly rendered row amount input selected by editMode with preventScroll and select(); skip autofocus when any pointer is coarse or navigator.maxTouchPoints is positive. Never change focus behavior for default/non-animated, duplicate, limited, unresolved, or failed additions.

## Keep formula row menus globally single-open
Formula row action menus are globally single-open, and closing a previous menu must not steal focus from the newly opened trigger.

## Use restrained recipe addition feedback
Manual ingredient additions highlight the exact destination phase and newly rendered row for 1200 ms with a 55%-strength accent ring and no opacity, translation, or scale motion. Keep the focused percentage or weight input's normal full-strength focus treatment unchanged. Soap Additives and Fragrance highlights must wait for their conditional phase container to render.
