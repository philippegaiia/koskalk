---
paths:
  - 'app/Services/**'
---

# Services

## Enforce via authorize() calls, soft checks via can()
Enforce with the throwing authorize() call — $this->authorize('update', $recipe) in controllers and Livewire, Gate::forUser($user)->authorize('update', $recipe) in services (services receive the User as a parameter). Reserve $user->can(...) for non-throwing checks and @can for Blade buttons.

## Services are container-resolved capability objects
Implement Services as plain classes in app/Services with constructor-injected readonly dependencies and instance methods named for the capability. Resolve them from the container (method injection into Livewire/controllers, constructor injection into Actions) — never instantiate with new.

## Compute money and mass with bcmath on decimal strings
Compute money and mass with bcmath on canonical decimal strings, never floats. Normalize user input via NumberLocale::normalizeDecimalString/parseDecimalInput, display via NumberLocale/DecimalStringFormatter, and convert units through MassConverter.
