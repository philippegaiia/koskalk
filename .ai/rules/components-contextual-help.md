---
paths:
  - 'resources/views/components/contextual-help/**'
---

# Components Contextual Help

## Keep page help in the app top bar
Render the page-level Help entry through the shared index-button component, teleported to #contextual-help-topbar in app-shell. Keep inline field/topic triggers beside their controls. Use a visible labelled button with a finite two-second introduction pulse and disable motion for prefers-reduced-motion. Workbench help must retain the active tab context; opening help after a Livewire update must load the latest page scope.
