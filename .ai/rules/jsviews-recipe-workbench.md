---
paths:
  - 'resources/{js,views}/**/recipe-workbench/**'
---

# Jsviews Recipe Workbench

## Keep cosmetic phase selection single-open and row-targeted
Only one ingredient phase chooser may be open at a time; opening another chooser closes the previous one without changing the original fixed-position repositioning behavior. After adding a cosmetic ingredient, scroll to and highlight the newly inserted ingredient row itself, not its phase container.
