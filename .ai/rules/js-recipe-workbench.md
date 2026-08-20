---
paths:
  - 'resources/js/recipe-workbench/**'
---

# Js Recipe Workbench

## Clear shared content dirty state after workbench saves
A successful workbench save persists both formula and recipe content. Before following the returned redirect, set the shared `recipe-content` dirty-state registry entry to `saved` for both new and existing recipes; otherwise the global Livewire navigation guard shows a false unsaved-changes prompt. Do not clear it when persistence fails.
