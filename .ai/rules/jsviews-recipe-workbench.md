---
paths:
  - 'resources/{js,views}/**/recipe-workbench/**'
---

# Jsviews Recipe Workbench

## Keep cosmetic phase selection single-open and row-targeted
Only one ingredient phase chooser may be open at a time; opening another closes the previous one without changing fixed-position repositioning. After adding an ingredient, retain the destination phase/section highlight but scroll to and highlight the newly inserted row after it renders; do not scroll the phase container before row insertion.

## Focus added amount only on fine-pointer desktop workbenches
After a successful animated ingredient insertion, preserve the existing phase/section highlight and centered row scroll. On fine-pointer desktops at min-width 1024px, focus the newly rendered row amount input selected by editMode with preventScroll and select(); skip autofocus when any pointer is coarse or navigator.maxTouchPoints is positive. Never change focus behavior for default/non-animated, duplicate, limited, unresolved, or failed additions.
