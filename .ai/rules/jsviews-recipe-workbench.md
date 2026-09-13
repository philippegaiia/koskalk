---
paths:
  - 'resources/{js,views}/**/recipe-workbench/**'
---

# Jsviews Recipe Workbench

## Keep cosmetic phase selection single-open and row-targeted
Only one ingredient phase chooser may be open at a time; opening another closes the previous one without changing fixed-position repositioning. After adding an ingredient, retain the destination phase/section highlight but scroll to and highlight the newly inserted row after it renders; do not scroll the phase container before row insertion.
