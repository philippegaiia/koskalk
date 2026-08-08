---
paths:
  - 'app/**'
  - 'app/*.php'
---

# App

## Route domain flow through direct Action/Service calls
Route domain logic through direct Action/Service calls (Livewire -> Action handle() -> Service); the app deliberately does not use custom domain events. Use queueable Jobs (ClassName::dispatch()) for async work and keep listeners for framework or third-party events. Introduce a domain Event/Listener only when a call chain genuinely needs decoupling.

## Prefer global helpers over facades
Use the global helpers config(), auth(), session(), response(), logger() rather than their facades. Use the Storage and DB facades only because no helper equivalents exist for them.

## Enums live in app/Enums, backed by string
Declare enums in app/Enums/ under namespace App\Enums, matching the framework's make:enum location. Back them with : string and use PascalCase case names with snake_case string values. Non-enum value classes (SoapSap, DecimalStringFormatter) stay at the app/ root.

## Use collection pipelines for array transformation
Prefer Illuminate Collection pipelines (collect()->map()->filter()->values()) for array transformation, and reserve foreach for side-effectful iteration (validation, persistence). array_map/array_filter are acceptable in native-focused code, but collections are the app standard.

## Scope tenant data via global scope or explicit workspace_id filters
Keep tenant data isolated either via the OwnedByCurrentTenantScope global scope (recipe/workspace family) or by explicit workspace_id where-clauses plus a ProductionBenchAccess/assertWritable gate (production, purchasing, inventory family). Use withoutGlobalScopes()->lockForUpdate() to lock cross-tenant rows and re-assert access inside transactions.

## Wrap multi-step writes in DB::transaction closures
Wrap multi-step writes in DB::transaction(fn () => ...) closures; never use beginTransaction/commit/rollBack. Lock affected rows with lockForUpdate() as the first step and pass attempts: 5 to the closure.

## Write idempotently with updateOrCreate/firstOrCreate
Prefer ->updateOrCreate([...], [...]) or ->firstOrCreate([...], [...]) for idempotent writes; the app does not use upsert(). Hand-rolled find-then-save is acceptable but updateOrCreate/firstOrCreate express the intent more clearly.
