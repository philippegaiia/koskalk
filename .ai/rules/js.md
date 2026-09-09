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

## Preserve fractional sticky-header offsets
The shared page-scroll header must apply the exact fractional offset returned by getBoundingClientRect(). Wheel and trackpad scrolling commonly uses subpixel positions; rounding the transform makes every table header visibly jump around half-pixel boundaries. Keep the correction composited and unrounded.
