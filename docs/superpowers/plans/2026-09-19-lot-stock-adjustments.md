# Lot Stock Adjustments Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking. No implementation is authorized by this planning document alone.

**Goal:** Let workspace users correct an existing ingredient or packaging lot's physical stock through a small dialog, preserving the existing stock ledger and purchase costs.

**Architecture:** One shared Filament action on the inventory register and material detail calls one transactional inventory action. Each correction appends one immutable `StockCountAdjustment` movement. Existing stock-position and material-activity calculations consume that movement normally.

**Tech Stack:** Laravel 13, installed Filament/Livewire versions, Blade, PostgreSQL, Pest, existing decimal-string quantity converters and translation catalogue.

## Agreed behavior

- The feature is available without enabling storage locations and without a new workspace setting.
- Scope: existing ingredient and packaging lots, including quarantined, exhausted, and negative-balance lots. Finished-product inventory retains its existing production movement workflow.
- Show a quiet **Adjust stock** text action beside Quarantine/Release in the lot register. Show the same action in open lots on material detail. Keep it separate from the location pencil. Do not introduce a filled button or new quantity column.
- Read-only users can inspect history but cannot open or submit an adjustment. Enforce the same active-bench/write entitlement used by other inventory mutations.
- The dialog identifies material, lot code, physical quantity, reserved quantity and unit.
- Modes: `set_counted` (default), `remove`, `add`.
- Default counted quantity input is blank: the user must enter their measurement.
- Reasons: `measurement_difference`, `spillage`, `damaged_discarded`, `entry_error`, `other`. Notes are optional except for Other; limit notes to 1,000 characters. Filter removal-only reasons out of positive adjustments and validate the combination server-side.
- Use existing supported mass units; packaging uses whole units. Localized input and display follow current inventory conventions.
- Show a live preview such as **12 kg → 11.7 kg · decrease of 300 g**. The save action recalculates independently.
- Submit label: **Save adjustment**. Record current server time; no date picker.
- Zero counted stock is valid. A count matching current physical stock creates no movement and reports **No change to save**.
- Add/Remove require a strictly positive quantity. Remove cannot exceed current physical quantity and is unavailable when physical stock is zero or negative. Set counted quantity must be non-negative. Add may improve an existing negative balance without fully resolving it; show the negative resulting balance clearly.
- If the resulting physical stock is below active reservations, show the shortage and require an explicit checkbox acknowledgement. Preserve reservations. Quarantined lots remain quarantined; use raw active reservations for the warning because `StockPositionService` intentionally reports zero usable reservations for quarantined lots.
- A changed stock balance or reservation snapshot invalidates the preview and acknowledgement. Refresh the current values, preserve the user's input and require review before another save.
- Purchase receipt quantities, historical/current unit costs, cost adjustments, prices and completed production costing snapshots are untouched. All modes represent corrections to this lot, not a new delivery.
- Corrections are never edited or deleted. A later adjustment corrects a mistaken one.

## Repository findings

- `app/Models/StockMovement.php` already has signed `quantity_delta`, original quantity/unit, actor, timestamp, note and a workspace-scoped unique idempotency key. Updating/deleting posted movements is forbidden.
- Live schema inspected with `php artisan truss:export --format=llm --focus=stock_movements --depth=0`: quantities are `numeric(20,9)`; there is no adjustment metadata field.
- The ledger rejects zero movements. Keep that constraint.
- `StockPositionService::forLot()` sums movements; quantities must never be written directly on the lot.
- `MaterialActivityService` already groups `StockCountAdjustment` under Adjustments.
- `InventoryIndex` and `InventoryMaterialDetail` already support Filament actions and modal rendering. Follow the shared concern pattern used by `InteractsWithStockLotLocations`.
- Current stock entry locks the workspace before posting. Review all relevant writers' lock ordering before adding adjustment concurrency checks.
- The Herd checkout is `/Users/philippe/Herd/koskalk`, on `main`, with uncommitted UI/location changes from this session. Preserve those changes; never stage them implicitly with this feature.

## Task 1: Store adjustment context in the existing ledger

**Files:**
- Create via Artisan: `database/migrations/<timestamp>_add_adjustment_details_to_stock_movements_table.php`
- Modify: `app/Models/StockMovement.php`
- Create: `tests/Feature/StockLotAdjustmentTest.php`

- [ ] Read applicable `.ai/rules`, the Laravel/testing skills, and installed package versions. Run `php artisan truss:doctor` before authoring the migration.
- [ ] Add nullable JSON `adjustment_details` to `stock_movements`, with an array cast and fillable entry. No new table or change to old rows.

```php
Schema::table('stock_movements', function (Blueprint $table): void {
    $table->json('adjustment_details')->nullable();
});
```

- [ ] Implement `down()` by dropping only that column; verify SQLite rebuild behavior preserves the existing nonzero triggers and indexes.
- [ ] Store stable codes and decimal strings, never translated labels or floating-point numbers:

```php
[
    'version' => 1,
    'mode' => 'set_counted',
    'reason' => 'measurement_difference',
    'entered_quantity' => '11.700000000',
    'entered_unit' => 'kg',
    'physical_before' => '12000.000000000',
    'physical_after' => '11700.000000000',
    'reserved_at_posting' => '5000.000000000',
    'shortage_acknowledged' => false,
]
```

- [ ] Preserve existing `original_quantity`/`original_unit` semantics as the signed movement expressed in the selected unit; the absolute counted input lives in details. Store free text in the existing `note` field.
- [ ] Test metadata persistence and immutable movements; run `tests/Feature/StockLedgerSchemaTest.php` to retain nonzero and schema guarantees.

## Task 2: Calculate and post an adjustment safely

**Files:**
- Create: `app/Services/Inventory/StockAdjustmentCalculator.php`
- Create: `app/Actions/Inventory/AdjustStockLot.php`
- Test: `tests/Feature/StockLotAdjustmentTest.php`

- [ ] Add failing tests for each calculation, permission and replay boundary before implementation.
- [ ] The calculator accepts canonical physical quantity, canonical input and validated mode. Return before/after/delta using bcmath at scale 9:

```php
$after = match ($mode) {
    'set_counted' => $quantity,
    'remove' => bcsub($physical, $quantity, 9),
    'add' => bcadd($physical, $quantity, 9),
};
$delta = bcsub($after, $physical, 9);
```

- [ ] Validate decimal syntax, precision, unit compatibility and `numeric(20,9)` overflow before writing; reject scientific notation and subprecision input rather than silently rounding it to zero. Reuse `MassConverter`, `NumberLocale`, and inventory quantity formatting.
- [ ] `AdjustStockLot::handle()` accepts actor, workspace, lot identifier, mode, entered quantity/unit, reason, optional note, preview snapshot, acknowledgement and idempotency key. It returns the posted `StockMovement`; validation failures write nothing.
- [ ] In a transaction with five attempts: lock workspace, recheck write access, resolve and lock the lot within that workspace, verify supported subject and immutable original lot identity, then check replay identity before stale-state checks.
- [ ] Reusing a key with the same lot and request returns the existing movement. Reusing it with different input or another lot fails; it never posts a second movement.
- [ ] Read authoritative physical balance and active reservations under the shared locking protocol. Snapshot includes latest movement ID, exact physical quantity, exact active reserved quantity and lot status. Never trust a client-submitted before/after value as authoritative.
- [ ] Compare with the server-owned preview snapshot, recalculate, enforce reason/mode rules, reject zero delta, require acknowledgement when below reserved, then append `StockMovementType::StockCountAdjustment` with details, note, actor and current time.
- [ ] Do not call opening-stock, receipt, material-price or cost-adjustment services. Keep lot status, location and acquisition information intact.
- [ ] Tests cover set/add/remove, 0 count, no-op, negative balances, whole packaging counts, kg/g/oz/lb conversion, precision/overflow, required reason, Other note, invalid reason/direction, inactive bench, viewer, foreign workspace, unsupported product lot, stale snapshot and idempotent retry.
- [ ] Verify resulting stock positions, material activity totals and unchanged receipt/cost fields. Test reducing below reserved both with and without acknowledgement, including quarantined lots.

## Task 3: Reuse one dialog on both inventory pages

**Files:**
- Create: `app/Livewire/Concerns/InteractsWithStockAdjustments.php`
- Modify: `app/Livewire/ProductionBench/InventoryIndex.php`
- Modify: `app/Livewire/ProductionBench/InventoryMaterialDetail.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Modify: `resources/views/livewire/production-bench/inventory-material-detail.blade.php`
- Create: `tests/Feature/StockLotAdjustmentUiTest.php`

- [ ] Add a shared `adjustStockAction()` Filament modal. Use native=false selection controls, existing public input styling, and the calculator for the preview. Confirm installed Filament APIs with package documentation before coding.
- [ ] Capture immutable lot identity, snapshot and request key when mounting in server-owned/locked state. Refresh or clear that state on close and lot changes. Form state contains only user-editable fields.
- [ ] Add a quiet text trigger in the existing action cell of the stock register. On open lots, add one narrow action cell at the right and update header/empty-state colspan; do not use the optional Location column as its host.
- [ ] Preserve first-column minimum widths, horizontal scrolling, sticky-header markers and current pagination. Do not make clicking an adjustment trigger navigate the row.
- [ ] On success close the dialog, notify **Stock adjusted**, and refresh stock totals, open lots, filters and activity. If the page becomes empty after stock reaches zero, retain the existing clear empty state and route to all lots.
- [ ] On stale state refresh displayed quantities and snapshot, reset shortage acknowledgement and retain entered mode/quantity/reason/note. Never automatically retry an absolute count against a new balance.
- [ ] UI tests mount and save all modes from both pages, verify no location feature is required, reject forged lot IDs including another material on material detail, and verify readonly users have no usable action.

## Task 4: Show a useful adjustment history

**Files:**
- Modify: `app/Services/Inventory/MaterialActivityService.php`
- Modify: `resources/views/livewire/production-bench/inventory-material-detail.blade.php`
- Modify: `tests/Feature/MaterialActivityServiceTest.php`
- Modify: `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`

- [ ] Eager-load the movement actor alongside existing source relationships. Retain existing pagination and adjustment grouping.
- [ ] For new adjustments, show the translated reason beneath the movement type. In the existing Source cell show the lot reference and user, with a compact disclosure for before/after quantities and optional note. Avoid additional permanent columns.
- [ ] Translate reason/mode codes at render time. Escape notes and actor names. Old movements with null details keep their current rendering; do not infer historical before/after balances.
- [ ] Verify increases and decreases reconcile with opening/closing physical stock and that details do not introduce a per-row query.

## Task 5: Translate and verify end to end

**Files:**
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Test: `tests/Feature/StockLotAdjustmentUiTest.php`

- [ ] Add `inventory.adjustment.*` keys for actions, modes, reasons, input labels, preview, acknowledgement, stale/no-change messages and success; put validation messages under `inventory.validation.*`.
- [ ] Supply reviewed translations in de, es, fr, it, nl and pt_BR. Import only these new keys locally with preserve-existing mode; never overwrite unrelated user translation overrides.
- [ ] Run the feature checks:

```sh
php artisan test --compact tests/Feature/StockLotAdjustmentTest.php tests/Feature/StockLotAdjustmentUiTest.php tests/Feature/StockLedgerSchemaTest.php tests/Feature/MaterialActivityServiceTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php tests/Feature/StorageLocationUiTest.php
vendor/bin/pint --dirty --format agent
npm run build
php artisan view:cache
git diff --check
graphify update .
```

- [ ] Exercise real PostgreSQL concurrency: two dialogs at the same snapshot; adjustment racing with production consumption; adjustment racing with reservation changes; duplicate submit/retry. The result must be one acknowledged movement, or a stale-state response, never silent overwriting. SQLite passing does not establish row-lock behavior.
- [ ] Verify migration up/down on a disposable test database; confirm schema after application with `php artisan truss:diff`.
- [ ] Browser checks: both entry points, narrow viewport, keyboard flow, visible focus, localized decimals, location setting off/on, long material names, zeroing a lot, negative balance, shortage acknowledgement, stale refresh and readable history. If browser access is unavailable, report it explicitly.
- [ ] Review the diff against the agreed behavior, stage only feature-owned changes, and commit after verification. Keep deployment/migration execution on the deployed app separate from local implementation.

## Completion criteria

A user can correct the counted balance or add/remove a known quantity on an existing lot in one dialog. Exactly one immutable, attributable movement is posted; stock and history update correctly; prices and receipts remain unchanged. Invalid, stale, duplicate and unauthorized submissions cannot corrupt stock. Both table layouts remain compact and all user-facing text is translated.
