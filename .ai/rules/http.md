---
paths:
  - 'app/Http/**'
---

# Http

## Enforce via authorize() calls, soft checks via can()
Enforce with the throwing authorize() call — $this->authorize('update', $recipe) in controllers and Livewire, Gate::forUser($user)->authorize('update', $recipe) in services (services receive the User as a parameter). Reserve $user->can(...) for non-throwing checks and @can for Blade buttons.

## Reference URLs via named routes with route()
Generate URLs and redirects from named routes with route('name', $params), redirect()->route(...), and {{ route('name') }} in Blade — the app standard. url('/path') is acceptable for truly static paths, but named routes are used throughout.
