---
paths:
  - 'resources/views/livewire/dashboard/partials/recipe-workbench/**'
---

# Partials Recipe Workbench

## Keep the ingredient selector independently sticky
At the desktop workbench breakpoint, apply sticky positioning to the ingredient-browser rail itself. Do not make the shared wrapper sticky: the soap-only fatty-acid panel makes that wrapper too tall and prevents reliable selector sticking. The fatty-acid panel scrolls normally below the selector.
