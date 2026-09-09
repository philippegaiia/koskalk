---
paths:
  - 'app/Http/Controllers/RecipeController.php'
  - 'resources/views/recipes/**'
  - 'resources/js/product-creation-selector.js'
  - 'resources/js/sticky-table-header.js'
---

# Js

## Keep quick and guided product creation paths
The default new-product entry is the searchable Product Type selector. Keep the existing guided family → grouped Product Type flow available at recipes.start.guided. PRODUCT_QUICK_CREATION_ENABLED=false must restore the guided flow globally without changing stored data.

## Use a fixed overlay for page-sticky table headers
Do not move the live thead vertically on each scroll frame; compositor-driven wheel scrolling will outrun that transform and visibly shake. Keep the original header in table layout and show a fixed, aria-hidden clone while its table crosses the viewport top. Synchronize column widths on resize and horizontal position on the table wrapper's own scroll event.
