---
paths:
  - 'resources/js/recipe-workbench/**'
---

# Js Recipe Workbench

## Clear shared content dirty state after workbench saves
A successful workbench save persists both formula and recipe content. Before following the returned redirect, set the shared `recipe-content` dirty-state registry entry to `saved` for both new and existing recipes; otherwise the global Livewire navigation guard shows a false unsaved-changes prompt. Do not clear it when persistence fails.

## Default only new soap formulas to 30% lye concentration
The client-side initial state for a brand-new soap formula uses waterMode lye_concentration and waterValue 30. Keep cosmetic initial values unchanged, and let explicit saved drafts or recipe snapshots override the initial state. Do not migrate recipes or change backend legacy fallbacks for this product default.
