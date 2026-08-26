---
paths:
  - 'app/Services/**'
  - app/Services/IngredientIdentitySynchronizer.php
  - 'app/Services/{IngredientDeclarationNameResolver,InciGenerationService}.php'
  - app/Services/IngredientCatalogConsolidationService.php
---

# Services

## Enforce via authorize() calls, soft checks via can()
Enforce with the throwing authorize() call — $this->authorize('update', $recipe) in controllers and Livewire, Gate::forUser($user)->authorize('update', $recipe) in services (services receive the User as a parameter). Reserve $user->can(...) for non-throwing checks and @can for Blade buttons.

## Services are container-resolved capability objects
Implement Services as plain classes in app/Services with constructor-injected readonly dependencies and instance methods named for the capability. Resolve them from the container (method injection into Livewire/controllers, constructor injection into Actions) — never instantiate with new.

## Compute money and mass with bcmath on decimal strings
Compute money and mass with bcmath on canonical decimal strings, never floats. Normalize user input via NumberLocale::normalizeDecimalString/parseDecimalInput, display via NumberLocale/DecimalStringFormatter, and convert units through MassConverter.

## Preserve identifier evidence during reconciliation
Reconcile ingredient identifiers by scheme plus normalized value so unchanged identifier rows keep their IDs and source evidence. Delete only identifiers removed from the submitted state. Replace evidence only when explicit identifier evidence is supplied; an Admin form save without evidence state must preserve existing provenance.

## Live previews tolerate missing INCI
Workbench EU previews may explicitly fall back to an ingredient display name when canonical INCI is missing, without showing a user warning, so calculation panels stay available. Strict declaration resolution without the preview fallback must continue to reject missing market declarations.

## Legacy output fields must round-trip invisibly
readyDelayDays, productReference, and nominalContentValue are no longer editable in the workbench UI but MUST stay in RecipeWorkbenchVersionPayloadMapper (emit), resources/js/recipe-workbench/snapshot.js (hydrate), and RecipeWorkbenchDraftPayloadMapper (save) so existing DB values survive edits. Do not drop them until a deliberate cleanup ships a replacement product-details experience. Keep the browser serialization and hydration path intact when changing these fields; existing persistence coverage protects the PHP round-trip, while browser draft coverage asserts productReference and nominalContentValue.

## Reconcile workspace material codes during catalogue merges
When merging platform ingredients, transfer a source-only workspace material code to the target. Preserve a target-only code, delete an identical duplicate assignment, and abort transactionally when one workspace assigned different codes to source and target. Ingredient deletion may still cascade because production history uses frozen snapshots.
