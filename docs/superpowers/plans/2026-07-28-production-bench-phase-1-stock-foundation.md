# Production Bench Phase 1 Stock Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Give entitled workspaces a calm Production Bench shell where makers can enter opening ingredient and packaging lots and trust the resulting physical, quarantined, reserved, available, incoming, and forecast stock positions.

**Architecture:** Add a workspace-level Production Bench entitlement independent of normal plan limits, then model stock as workspace-owned lots plus immutable signed movements. Ingredient lots reference existing `ingredients`; packaging lots reference existing `user_packaging_items`; a database check enforces exactly one subject. Stock positions are derived projections, never editable balances. Full-page customer workflows use the existing app shell, Blade, Livewire, private media library, and small domain actions.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Blade, Alpine.js, Tailwind CSS 4, Pest 4, BCMath, PostgreSQL transactions and constraints.

---

## Invariants

- Production Bench access belongs to one workspace and is independent of plans and team seats.
- Cancelling the add-on preserves records and makes every Production Bench mutation read-only.
- Ingredient and intermediate stock uses canonical grams; packaging stock uses integer count.
- An opening lot references exactly one ingredient or packaging item.
- Every lot has a unique workspace-scoped Soapkraft internal lot code.
- Supplier batch references are optional and non-unique.
- Every physical change is represented by an immutable signed stock movement.
- Posted movements cannot be edited or deleted.
- Physical equals posted movement sum.
- Quarantined equals physical stock in quarantined lots.
- Reserved is zero until reservation records arrive in Checkpoint 3.
- Available equals released physical minus active reservations.
- Incoming and forecast are zero until their source records arrive in later checkpoints.
- Unknown opening provenance is explicit and remains visible.
- Production documents always reference a private media asset in the same workspace.

## Task 1: Workspace Production Bench Entitlement

**Files:**

- Create: `app/ProductionBenchEntitlementStatus.php`
- Create: `app/Models/WorkspaceProductionEntitlement.php`
- Create: `app/Services/ProductionBenchAccess.php`
- Create: `database/migrations/*_create_workspace_production_entitlements_table.php`
- Create: `database/factories/WorkspaceProductionEntitlementFactory.php`
- Modify: `app/Models/Workspace.php`
- Create: `tests/Feature/ProductionBenchEntitlementTest.php`

### Step 1: Write failing entitlement tests

Test active, cancelled, and resumed access; cancellation timestamps and 48-month archive eligibility; mutation rejection while cancelled; and independence from plan limits and workspace member count.

Run:

```bash
php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/ProductionBenchEntitlementTest.php
```

Expected: FAIL because the entitlement model and access service do not exist.

### Step 2: Generate and implement the model

Generate the model, factory, and migration with Artisan. The table has a unique `workspace_id`, enum-backed `status`, `activated_at`, `cancelled_at`, and `archive_eligible_at`. `ProductionBenchAccess` exposes:

```php
public function activate(User $actor, Workspace $workspace): WorkspaceProductionEntitlement;
public function cancel(User $actor, Workspace $workspace): WorkspaceProductionEntitlement;
public function resume(User $actor, Workspace $workspace): WorkspaceProductionEntitlement;
public function isActive(Workspace $workspace): bool;
public function isReadOnly(Workspace $workspace): bool;
public function assertWritable(User $actor, Workspace $workspace): void;
```

Only owner/admin/editor roles may mutate. Activation/resumption clears cancellation/archive dates; cancellation sets archive eligibility to 48 months later.

### Step 3: Verify and commit

Run the focused test, format, and commit:

```bash
git add app database tests/Feature/ProductionBenchEntitlementTest.php
git commit -m "feat: add workspace production bench entitlement"
```

## Task 2: Lot, Movement, and Document Schema

**Files:**

- Create: `app/StockUnitKind.php`
- Create: `app/StockLotStatus.php`
- Create: `app/StockLotOrigin.php`
- Create: `app/StockMovementType.php`
- Create: `app/ProductionDocumentType.php`
- Create: `app/Models/StockLot.php`
- Create: `app/Models/StockMovement.php`
- Create: `app/Models/ProductionDocument.php`
- Create: `database/migrations/*_create_stock_lots_table.php`
- Create: `database/migrations/*_create_stock_movements_table.php`
- Create: `database/migrations/*_create_production_documents_table.php`
- Create: `database/factories/StockLotFactory.php`
- Create: `database/factories/StockMovementFactory.php`
- Create: `database/factories/ProductionDocumentFactory.php`
- Create: `tests/Feature/StockLedgerSchemaTest.php`

### Step 1: Write failing schema and model tests

Prove:

- stock lots are workspace-owned;
- exactly one of `ingredient_id` and `user_packaging_item_id` is required;
- internal lot code is unique inside a workspace;
- supplier batch is nullable and reusable;
- mass quantities use `DECIMAL(20, 9)`;
- count quantities reject fractional values;
- movements carry canonical and original entered quantities;
- movement updates and deletes throw;
- documents reference workspace, private media asset, documentable record, type, and actor.

Expected: FAIL because the tables and models do not exist.

### Step 2: Generate and implement enums, models, factories, and migrations

Use direct nullable foreign keys for stock subjects with a PostgreSQL-compatible check constraint enforcing one subject. Lots store unit kind, origin, dates, release state, provenance completeness, historical unit cost, currency, and notes.

Movements store signed canonical quantity, original quantity/unit, type, occurrence time, actor, optional source morph, optional reversal link, and a workspace-scoped idempotency key.

Documents store a same-workspace media asset, typed role, actor, optional note, and a morph to lot/order/receipt/supplier records.

### Step 3: Verify and commit

Run schema tests, format, and commit:

```bash
git add app database tests/Feature/StockLedgerSchemaTest.php
git commit -m "feat: add stock ledger schema"
```

## Task 3: Opening Lots and Stock Truth

**Files:**

- Create: `app/Actions/Inventory/CreateOpeningStockLot.php`
- Create: `app/Actions/Inventory/ReleaseStockLot.php`
- Create: `app/Actions/Inventory/QuarantineStockLot.php`
- Create: `app/Actions/Inventory/AttachProductionDocument.php`
- Create: `app/Services/InternalLotCodeGenerator.php`
- Create: `app/Services/StockPositionService.php`
- Create: `tests/Feature/OpeningStockLedgerTest.php`
- Create: `tests/Unit/StockPositionServiceTest.php`

### Step 1: Write failing action and projection tests

Cover:

- g/kg/oz/lb opening ingredient input becomes exact canonical grams;
- packaging input requires a positive integer;
- opening action atomically creates one lot and one movement;
- retrying one idempotency key returns the same lot;
- generated lot codes are unique and readable;
- released opening stock is physical and available;
- quarantined opening stock is physical and quarantined but unavailable;
- releasing/quarantining changes availability without rewriting movements;
- unknown provenance is retained;
- attaching a cross-workspace media asset is rejected.

Expected: FAIL because the actions and projection do not exist.

### Step 2: Implement transactional actions

All write actions call `ProductionBenchAccess::assertWritable()`. `CreateOpeningStockLot` validates the subject/unit-kind combination, converts mass through `MassConverter`, uses a database transaction and workspace lock, creates the lot, then posts one opening movement. `ReleaseStockLot` and `QuarantineStockLot` lock the lot and update only its release state. `AttachProductionDocument` validates workspace ownership before creating the attachment.

`StockPositionService` returns:

```php
/**
 * @return array{
 *   physical: string,
 *   quarantined: string,
 *   reserved: string,
 *   available: string,
 *   incoming: string,
 *   forecast: string
 * }
 */
public function forLot(StockLot $lot): array;
public function forWorkspaceSubject(Workspace $workspace, Ingredient|UserPackagingItem $subject): array;
```

All arithmetic uses decimal strings and BCMath.

### Step 3: Verify and commit

Run focused tests, format, and commit:

```bash
git add app/Actions app/Services tests
git commit -m "feat: post opening stock movements"
```

## Task 4: Production Bench Route and Navigation Shell

**Files:**

- Create: `app/Livewire/ProductionBench/HomeIndex.php`
- Create: `app/Livewire/ProductionBench/InventoryIndex.php`
- Create: `resources/views/production-bench/home.blade.php`
- Create: `resources/views/production-bench/inventory.blade.php`
- Create: `resources/views/livewire/production-bench/home-index.blade.php`
- Create: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Create: `resources/views/components/production-bench/navigation.blade.php`
- Modify: `resources/views/layouts/app-shell.blade.php`
- Modify: `routes/web.php`
- Modify: `lang/en/navigation.php`
- Create: `lang/en/production_bench.php`
- Create: `tests/Feature/ProductionBenchNavigationTest.php`

### Step 1: Write failing route and shell tests

Assert authenticated routes, workspace isolation, active navigation states, visible Production Bench entry as a separate option, inactive activation state, active sub-navigation, and cancelled read-only banner.

Expected: FAIL because routes and components do not exist.

### Step 2: Implement the shell

Add `/dashboard/production-bench` routes named `production-bench.home` and `production-bench.inventory`. Keep the existing app shell and introduce a compact Production Bench sub-navigation for Home, Purchasing, Inventory, Production, Traceability, and Flash Planner. Only Home and Inventory are enabled in this checkpoint; disabled destinations are clearly labelled without pretending to be available.

The home component activates, cancels, and resumes the add-on through `ProductionBenchAccess`. It shows operational attention and honest empty states, not decorative KPI cards.

### Step 3: Verify and commit

Run route tests, build, format, and commit:

```bash
git add app/Livewire resources/views routes lang tests/Feature/ProductionBenchNavigationTest.php
git commit -m "feat: add production bench shell"
```

## Task 5: Opening Stock and Inventory Experience

**Files:**

- Modify: `app/Livewire/ProductionBench/InventoryIndex.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Modify: `lang/en/production_bench.php`
- Create: `tests/Feature/ProductionBenchInventoryTest.php`

### Step 1: Write failing Livewire tests

Prove a maker can:

- choose an existing ingredient or packaging item;
- enter opening quantity in a permitted unit;
- mark the lot released or quarantined;
- record supplier batch, dates, and unknown provenance;
- see the resulting internal lot code;
- see Physical, Quarantined, Reserved, Available, Incoming, and Forecast values;
- release/quarantine a lot;
- attach an existing private media asset with a document type;
- not mutate any of these records after cancellation.

Expected: FAIL because InventoryIndex has no workflow.

### Step 2: Implement the Livewire workflow

Use progressive inline sections rather than modal chains. The opening form changes fields based on ingredient mass versus packaging count. Stock rows keep subject, internal lot, supplier batch, expiry, provenance, status, and six stock-truth figures scannable. Numeric values use the existing locale and mass-display services.

Document attachment selects a ready or processing private media asset from the current workspace and records its explicit type.

### Step 3: Verify and commit

Run focused tests, build, format, and commit:

```bash
git add app/Livewire resources/views lang tests/Feature/ProductionBenchInventoryTest.php
git commit -m "feat: add opening stock experience"
```

## Task 6: Checkpoint 1 Regression and Review Evidence

**Files:**

- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php` only if new catalogue keys require validation coverage
- Modify: `database/seeders/data/interface-translations.json`

### Step 1: Complete translation catalogue entries

Add reviewed placeholders for the new English production keys and keep all supported locale catalogues structurally valid.

### Step 2: Run checkpoint verification

```bash
php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/ProductionBenchEntitlementTest.php tests/Feature/StockLedgerSchemaTest.php tests/Feature/OpeningStockLedgerTest.php tests/Unit/StockPositionServiceTest.php tests/Feature/ProductionBenchNavigationTest.php tests/Feature/ProductionBenchInventoryTest.php
vendor/bin/pint --dirty --format agent
npm run build
git diff --check
graphify update .
```

Then run the complete Pest suite. Expected: all tests pass and the worktree is clean after the checkpoint commit.

