# Optional Storage Locations Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task. Follow the shared foundation in [optional locations](2026-09-16-optional-locations.md) first. Steps use checkbox syntax for tracking.

**Goal:** Let users optionally say where an ingredient or packaging lot is stored, prefill its usual location, and reassign the whole lot without a warehouse workflow.

**Architecture:** Add a separate workspace-owned StorageLocation table and nullable FK on StockLot. Store per-material defaults in the existing WorkspaceMaterialSetting, independent of its stock buffer. Integrate with opening stock and both purchase-order/direct receipt posting paths; do not change the stock ledger or allocation rules.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5 forms/actions, PostgreSQL, Pest 4.

---

## Storage contract

- Users explicitly enable Use storage locations in Settings. Production locations are unrelated.
- A location needs only a name and an active/inactive state. Examples: Oil shelf, Cold cupboard, Packaging room. No codes, dimensions, maximum capacity, warehouse hierarchy, or generated locations.
- One lot has one nullable current location. No quantity split between locations, transfer documents, or stock movements for changing it.
- Optional default location belongs to a workspace/material pair (Ingredient or PackagingItem). Never attach a workspace default to a shared platform ingredient.
- At stock-entry initialization, prefill the active default for the chosen material. An explicit clear stays clear. A manual choice overrides the default for that lot only.
- Changing a default never relocates existing stock. Switching off preserves defaults and assignments but stops using them for new lots.
- A lot's location does not imply quarantine/release, availability, reservation, or a cost. It is a finding aid. Production can consume eligible lots in any location under existing rules.
- Archive removes a location from new choices. Existing assignments remain readable while the feature is on. Inactive defaults produce no automatic assignment; never block a receipt because its old default was archived.
- Dedicated location writes require the enabled setting and writable Production Bench access. General stock operations ignore stale location payload while off and preserve existing assignments.
- New auto-produced finished-product stock remains unassigned. Finished-goods locations are outside this ingredient/packaging scope; do not add a completion-form field.

## Task S1: Create the storage location model and optional assignment schema

**Create:**
- `database/migrations/2026_09_16_103000_create_storage_locations_and_assignments.php`
- `app/Models/StorageLocation.php`
- `database/factories/StorageLocationFactory.php`
- `app/Policies/StorageLocationPolicy.php`
- `tests/Feature/StorageLocationSchemaTest.php`

**Modify:**
- `app/Models/StockLot.php`
- `app/Models/WorkspaceMaterialSetting.php`
- `app/Models/Workspace.php`
- `database/factories/WorkspaceMaterialSettingFactory.php`

- [ ] Write failing schema/behavior tests for an ingredient and packaging lot's nullable assignment, distinct workspace ownership, a material setting containing only a location default, and preserved exact-one-material/nonnegative-buffer constraints.
- [ ] Run `php artisan test --compact tests/Feature/StorageLocationSchemaTest.php tests/Feature/WorkspaceMaterialSettingTest.php`.
- [ ] Create storage_locations with id, unique public_id UUID, workspace FK cascadeOnDelete, name/normalized_name varchar(120), is_active boolean default true, timestamps, and unique(workspace_id, normalized_name). Add nullable indexed `storage_location_id` FK to stock_lots and nullable indexed `default_storage_location_id` FK to workspace_material_settings, both nullOnDelete. Add `(workspace_id, storage_location_id)` on stock_lots for the optional lot filter.
- [ ] Change `workspace_material_settings.buffer_quantity` to nullable decimal(20,9). Preserve its precision, existing indexes, exact-one-subject constraint, and nonnegative check/triggers. Verify both PostgreSQL and the project's SQLite test path, since SQLite table rebuilding can affect triggers. Do not use zero as a substitute for an absent buffer.
- [ ] Implement down() in reverse dependency order: drop location columns and their indexes/FKs, delete rows whose buffer is null (these contain only the newly removed optional setting), restore buffer NOT NULL, and drop storage_locations. Preserve all pre-feature buffer values. Test rollback/reapply on a disposable schema; do not run rollback against user data.
- [ ] Add Fillable entries, explicit belongsTo relations and Workspace.hasMany storageLocations. Use HasPublicId and a boolean is_active cast. Keep storage_location_id out of StockLot's immutable acquisition field list: it is a current operational assignment, not receipt evidence.
- [ ] Add a policy following DepartmentPolicy's workspace-role/entitlement rules; authorize through it in write actions. Do not expose hard delete. Run the named tests, format and commit.

## Task S2: Manage locations and independently save material defaults

**Create:**
- `app/Actions/Inventory/SaveStorageLocation.php`
- `app/Actions/Inventory/SaveMaterialStorageLocation.php`
- `app/Services/Inventory/StorageLocationSelection.php`
- `app/Livewire/ProductionBench/StorageLocations.php`
- `resources/views/livewire/production-bench/storage-locations.blade.php`
- `tests/Feature/StorageLocationSettingsTest.php`
- `tests/Feature/MaterialStorageLocationTest.php`

**Modify:**
- `app/Services/Inventory/WorkspaceMaterialSettings.php`
- `app/Livewire/ProductionBench/InventoryMaterialDetail.php`
- `resources/views/livewire/production-bench/inventory-material-detail.blade.php`
- `resources/views/livewire/production-bench/production/planning-preferences.blade.php`
- `tests/Feature/WorkspaceMaterialSettingTest.php`
- `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`
- `lang/en/production_bench.php`

- [ ] Add the regression below to WorkspaceMaterialSettingTest.php. It must fail before changing the deletion behavior. Create equivalent packaging/default-clear coverage in MaterialStorageLocationTest.php.

```php
it('keeps the usual storage location when the buffer is cleared', function (): void {
    $owner = \App\Models\User::factory()->create();
    $workspace = \App\Models\Workspace::factory()->for($owner, 'owner')->create([
        'uses_storage_locations' => true,
    ]);
    \App\Models\WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $ingredient = \App\Models\Ingredient::factory()->create();
    $location = \App\Models\StorageLocation::factory()->for($workspace)->create();
    $setting = \App\Models\WorkspaceMaterialSetting::factory()
        ->for($workspace)->for($ingredient)->create([
            'buffer_quantity' => '1000.000000000',
            'default_storage_location_id' => $location->id,
        ]);

    app(\App\Actions\Inventory\SaveMaterialBuffer::class)->handle(
        $owner, $workspace, $ingredient, null,
    );

    expect($setting->fresh()->buffer_quantity)->toBeNull();
    expect($setting->fresh()->default_storage_location_id)->toBe($location->id);
});
```

- [ ] Run `php artisan test --compact tests/Feature/WorkspaceMaterialSettingTest.php tests/Feature/MaterialStorageLocationTest.php tests/Feature/StorageLocationSettingsTest.php`.
- [ ] Implement SaveStorageLocation with `handle(User $actor, Workspace $workspace, string $name, bool $isActive = true, ?StorageLocation $location = null): StorageLocation`. Normalize names as SaveDepartment does; reject blank/overlong/duplicate normalized names within the workspace. Lock workspace before location, assert access and enabled flag inside the transaction, and invoke create/update policy. Support archive/reactivate, not delete.
- [ ] Implement SaveMaterialStorageLocation with `handle(User $actor, Workspace $workspace, Ingredient|PackagingItem $subject, ?int $locationId): ?WorkspaceMaterialSetting`. Apply the same material accessibility rules as SaveMaterialBuffer (including platform/legacy ingredients and workspace-owned packaging), validate active same-workspace location or null, and require the enabled flag.
- [ ] Extend WorkspaceMaterialSettings with `synchronizeStorageLocation(User $actor, Workspace $workspace, Ingredient|PackagingItem $subject, ?int $locationId): ?WorkspaceMaterialSetting`. Keep synchronize's current buffer API. Both methods lock workspace then the material-setting row, preserve the unrelated column, and use updateOrCreate. Delete only when **both** buffer_quantity and default_storage_location_id are null. Clearing one setting must work while the other feature is disabled, without erasing its stored default. Reassert access after the lock.
- [ ] Implement StorageLocationSelection with `defaultFor(Workspace $workspace, Ingredient|PackagingItem $subject): ?int`, `resolve(Workspace $workspace, Ingredient|PackagingItem $subject, array $input): ?int`, and active options scoped to workspace. resolve returns null while disabled; while enabled it checks `array_key_exists('storage_location_id', $input)`: absent uses the current active default, explicit null/empty means unassigned, positive ID must be active and same workspace. Resolve material ownership separately in the calling write action. Never trust a supplied workspace_id.
- [ ] Nest the StorageLocations manager on the settings page only when uses_storage_locations is true. Reuse Filament actions/forms and the SaveStorageLocation action. Do not show an empty-state nudge elsewhere.
- [ ] Add an optional **Usual storage location** action in InventoryMaterialDetail next to existing material settings. Show the active selection and allow clear. Preserve an inactive saved default when changing an unrelated setting; show it as inactive when this action is opened, and allow replacement/clear. Save invokes SaveMaterialStorageLocation, not a direct model write.
- [ ] Test all four flag combinations, default-only setting rows, clearing/default changes leaving existing lots alone, archived/foreign targets, viewer/read-only rejection, malicious direct Livewire calls when hidden, and escaped location names. Run the named files plus ProductionBenchInventoryMaterialDetailTest.php, format and commit.

## Task S3: Apply defaults and explicit selections on every stock-entry path

**Create:**
- `tests/Feature/StockLotStorageAssignmentTest.php`

**Modify:**
- `app/Actions/Inventory/CreateOpeningStockLot.php`
- `app/Actions/Purchasing/GoodsReceiptInputValidator.php`
- `app/Actions/Purchasing/ReceiveDirectGoodsReceipt.php`
- `app/Actions/Purchasing/ReceivePurchaseOrder.php`
- `app/Actions/Purchasing/PostGoodsReceiptLine.php`
- `app/Livewire/ProductionBench/InventoryIndex.php`
- `app/Livewire/ProductionBench/Purchasing/ReceiptCreate.php`
- `resources/views/livewire/production-bench/purchasing/receipt-create.blade.php`
- `tests/Feature/OpeningStockLedgerTest.php`
- `tests/Feature/DirectGoodsReceiptPostingTest.php`
- `tests/Feature/ProductionBenchGoodsReceiptPagesTest.php`
- `lang/en/production_bench.php`

- [ ] Write a dataset over opening stock, direct receipt, and purchase-order receipt. For each, cover default prefill, explicit alternate location, explicit clear, no default, feature off, inactive default, invalid explicit foreign location, and ingredients/packaging. Reuse the existing receipt fixtures for financial and listing setup.
- [ ] Run `php artisan test --compact tests/Feature/StockLotStorageAssignmentTest.php` and confirm the new behavior is missing.
- [ ] Add optional trailing `array $storageLocationInput = []` to CreateOpeningStockLot.handle. Receipt line input keeps its existing array shape plus optional storage_location_id; preserve presence/absence through validators and normalization. A hidden field must be omitted, not converted to null before default resolution.
- [ ] In InventoryIndex's existing manual-stock action, selecting a supplier listing prefills its material's active default. Add a nullable Filament Select only when enabled, and pass explicit null if the user clears it. For ReceiptCreate, initialize a location value for each selected order/direct line, render it only while enabled, and preserve the value across quantity/price edits. Switching materials loads the new material's default.
- [ ] Pass the optional line input through both ReceiveDirectGoodsReceipt and ReceivePurchaseOrder into PostGoodsReceiptLine. Resolve the final selected location server-side under the existing workspace transaction immediately before creating each StockLot. Opening stock uses the same resolver inside its transaction. Recheck flags, ownership and location activity using locked records; no partial receipt if any explicitly selected location is invalid.
- [ ] Preserve explicit selection semantics if a default changes between initialization and posting: the displayed selection wins. If the option was disabled meanwhile, ignore the stale input and create unassigned stock. If an explicitly selected location was archived meanwhile, return a field error so the user can clear/reselect; do not silently change the choice.
- [ ] Keep existing receipt idempotency. Replaying a posted receipt returns its existing lots and must not reapply a changed default or overwrite later reassignment. Location changes never change price snapshots, currencies, quantity units, posted receipt lines, or stock movements.
- [ ] Test a multi-line receipt with a bad location on its last line: no receipt/lot/movement survives. Run the new test and the four modified test files; format and commit.

## Task S4: Reassign lots and show where materials can be found

**Create:**
- `app/Actions/Inventory/AssignStockLotLocation.php`
- `app/Livewire/Concerns/InteractsWithStockLotLocations.php`
- `tests/Feature/StockLotLocationReassignmentTest.php`

**Modify:**
- `app/Livewire/ProductionBench/InventoryIndex.php`
- `app/Livewire/ProductionBench/InventoryMaterialDetail.php`
- `app/Services/Inventory/WorkspaceMaterialInventoryQuery.php`
- `app/Services/Production/StockReservationProposalService.php`
- `app/Livewire/ProductionBench/Production/StockPreparation.php`
- `resources/views/livewire/production-bench/inventory-index.blade.php`
- `resources/views/livewire/production-bench/inventory-material-detail.blade.php`
- `resources/views/livewire/production-bench/production/stock-preparation.blade.php`
- `tests/Feature/ProductionBenchInventoryModalTest.php`
- `tests/Feature/ProductionBenchStockPreparationTest.php`
- `tests/Feature/WorkspaceMaterialInventoryQueryTest.php`
- `lang/en/production_bench.php`

- [ ] Write reassignment tests recording the lot quantity/available stock, movements, reservations, receipt data, cost and status before and after. Changing location must alter none of those. Cover clear, inactive source to active target, cross-workspace source/target, read-only access, disabled flag and ingredient/packaging subjects.
- [ ] Run `php artisan test --compact tests/Feature/StockLotLocationReassignmentTest.php`.
- [ ] Implement `AssignStockLotLocation::handle(User $actor, StockLot $lot, ?int $locationId): StockLot`. Lock the workspace first, then the current lot and target; reassert writable access and workspace ownership. Require uses_storage_locations and an ingredient/packaging lot; target is nullable active same-workspace storage location. Update only storage_location_id. Do not create StockMovement records or change the material default.
- [ ] Add a **Change storage location** Filament row action to Lot Register and the open-lots table on material detail. These tables currently do not have a general lot-detail modal; use a small action modal for the optional selector rather than inventing a detail page. Put the shared action definition in `app/Livewire/Concerns/InteractsWithStockLotLocations.php`, use the concern in both components, resolve action arguments through a workspace-scoped lot query, and invoke AssignStockLotLocation. Clearing is available, with no extra confirmation for a reversible reassignment.
- [ ] Eager-load storageLocation for the displayed lot page and stock preparation choices when enabled. Add a location column on Lot Register and location text in the reassignment action and stock preparation. Display an unassigned value only in these enabled views; disabled views must not contain even a placeholder column.
- [ ] Add an optional Lot Register filter for all locations, unassigned, and a same-workspace location. Permit archived locations in filters so existing stock can still be found. Ignore stale filter values and restore unfiltered results when disabled; reset pagination on changes. Do not filter overall inventory totals or reservation eligibility by the selected display filter.
- [ ] Audit WorkspaceMaterialInventoryQuery's material-settings union/join for location-only rows. Preserve null buffer semantics: no false zero buffer or below-buffer alert. Update any query/presenter path that currently assumes a non-null buffer, including `app/Livewire/ProductionBench/InventoryIndex.php` and `InventoryMaterialDetail.php`. A default-only row must not invent stock, demand, or incoming quantity. Keep settings with locations from creating new visible material rows while the feature is off; preserve material visibility driven by existing stock, listings, demand or an actual buffer.
- [ ] Render names escaped. Avoid one query per lot. Location data must remain outside formula snapshots, cost calculations, demand, and stock-allocation policies.
- [ ] Run the new file and the three modified test files; format and commit.

## Task S5: Verify opt-out across the whole workflow

**Create:**
- `tests/Feature/ProductionBenchLocationVisibilityTest.php`

**Modify:**
- Existing page tests only where they supply the relevant feature-on/off fixtures.

- [ ] Create workspaces with saved locations, product/material defaults, assigned productions, assigned ingredient/packaging lots, and entitlements. Use a four-case flag dataset from the shared plan.
- [ ] Assert form controls, location columns, filters, badges and placeholder text are absent on the disabled side across Flash, production create/detail/index/calendar, manual stock, receipt creation, lot register, material detail and stock preparation. The Settings switches are the only discovery surface while off.
- [ ] Assert enabled empty lists never block ordinary creation; nullable assignments work without making a location first. Assignments already stored reappear after off/on.
- [ ] Submit stale ordinary forms after toggling off: creation succeeds unassigned, edits preserve existing assignments, no hidden location validation appears. Submit a dedicated location-changing action while off: it is rejected and nothing changes. Stale filters do not hide records.
- [ ] Check settings edits stay isolated: clearing a buffer while storage is off preserves its default; changing the overall daily limit while production locations are off preserves their capacities and assignments.
- [ ] Run `php artisan test --compact tests/Feature/ProductionBenchLocationVisibilityTest.php tests/Feature/ProductionBenchLocalizationTest.php tests/Feature/ProductionBenchLayoutTest.php tests/Feature/StockReservationProposalTest.php tests/Feature/ProductionStockPreparationTest.php`.
- [ ] Review actual desktop/tablet pages using Herd and Boost-resolved URLs. Confirm that the off-state layout contains no empty spaces reserved for optional controls. Perform no real stock or production writes in a shared workspace merely to verify the UI.
- [ ] Follow the shared plan's formatter, build, Truss, graph refresh and full-suite handoff steps. Commit only this task's files.

## Explicit exclusions

No split-location lot balances, warehouse transfers, storage capacity limits, location-based consumption restrictions, automatic quarantine areas, finished-product storage workflow, location hierarchy, barcode system, mandatory assignments, or prompts to activate the feature.
