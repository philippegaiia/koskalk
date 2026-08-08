---
paths:
  - 'app/Actions/**'
---

# Actions

## Actions are verb-named handle() command classes
Create Actions as plain classes in app/Actions/<Domain>/ with one public handle(User $actor, ...) entry point, dependencies injected via the constructor. Assert access inside the Action, then delegate the real work to a Service. Callers resolve them through the container (method injection) and never use new.

## Compute money and mass with bcmath on decimal strings
Compute money and mass with bcmath on canonical decimal strings, never floats. Normalize user input via NumberLocale::normalizeDecimalString/parseDecimalInput, display via NumberLocale/DecimalStringFormatter, and convert units through MassConverter.
