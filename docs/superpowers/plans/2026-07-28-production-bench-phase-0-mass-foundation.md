# Production Bench Phase 0 Mass Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make both existing Formula Benches and their costing/production handoffs preserve one physical mass while users enter and display g, kg, oz, or lb.

**Architecture:** Add precise mass primitives shared by the Laravel domain and a matching dependency-free JavaScript conversion module. Formula versions and costing overrides gain canonical gram columns while their existing quantity/unit columns remain the backwards-compatible display snapshot. Existing Basic production batches are not rewritten; services derive new defaults from canonical formula mass but continue recording the user-visible value and unit in each immutable snapshot.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Blade, Alpine.js, Tailwind CSS 4, Pest 4, BCMath, Vite 8.

---

## Invariants

- Canonical formula and costing mass is stored as `DECIMAL(20, 9)` grams.
- Display units are exactly `g`, `kg`, `oz`, and `lb`.
- Exact factors are `1 kg = 1000 g`, `1 lb = 453.59237 g`, and `1 oz = 28.349523125 g`.
- Unit changes convert the displayed quantity; they never relabel an unchanged number.
- Percentages and the physical formula do not change when the display unit changes.
- The selected formula unit is version-specific.
- The workspace mass system only selects the initial unit for a new formula.
- Existing `recipe_versions.batch_size` / `batch_unit` and costing display columns remain readable.
- Legacy rows without canonical values derive grams from their saved display value and unit.
- Existing `production_batches` and their ingredient rows are never backfilled or reinterpreted.

## Task 1: Precise Mass Domain Primitives

**Files:**

- Create: `app/MassUnit.php`
- Create: `app/MassDisplaySystem.php`
- Create: `app/Services/MassConverter.php`
- Create: `tests/Unit/MassConverterTest.php`

### Step 1: Write the failing unit tests

Create `tests/Unit/MassConverterTest.php` with named Pest datasets covering:

- every supported unit and exact gram factor;
- `1 kg → 2.204622622 lb`;
- `1 lb → 16 oz`;
- `1 oz → 28.349523125 g`;
- `1 kg → lb → kg` returning `1.000000000`;
- metric preferred units (`999 g → g`, `1000 g → kg`);
- US customary preferred units (`452 g → oz`, `453.59237 g → lb`);
- invalid units and negative quantities being rejected.

Run:

```bash
php -d memory_limit=512M vendor/bin/pest --compact tests/Unit/MassConverterTest.php
```

Expected: FAIL because the enum and converter do not exist.

### Step 2: Implement the PHP primitives

`MassUnit` is a string-backed enum with TitleCase cases `Gram`, `Kilogram`, `Ounce`, and `Pound`. It exposes:

```php
public function gramsPerUnit(): string;
public static function fromInput(mixed $value): self;
```

`MassDisplaySystem` is a string-backed enum with `Metric` and `UsCustomary`. It exposes:

```php
public function preferredUnit(string|int|float $grams): MassUnit;
```

`MassConverter` accepts numeric strings as its public boundary and uses BCMath rather than binary floating-point arithmetic:

```php
public function toGrams(string|int|float $quantity, MassUnit|string $unit): string;
public function fromGrams(string|int|float $grams, MassUnit|string $unit): string;
public function convert(
    string|int|float $quantity,
    MassUnit|string $from,
    MassUnit|string $to,
): string;
```

All returned values have nine decimal places. The converter normalizes supported unit strings through `MassUnit::fromInput()`, rejects negative/non-numeric input, performs multiplication/division with guard precision, and rounds half-up to nine places.

### Step 3: Verify and commit

Run the focused test, then:

```bash
vendor/bin/pint --dirty --format agent
git add app/MassUnit.php app/MassDisplaySystem.php app/Services/MassConverter.php tests/Unit/MassConverterTest.php
git commit -m "feat: add precise mass conversion primitives"
```

## Task 2: Canonical Formula, Costing, and Workspace Persistence

**Files:**

- Create: `database/migrations/2026_07_28_000001_add_mass_foundation_columns.php`
- Modify: `app/Models/RecipeVersion.php`
- Modify: `app/Models/RecipeVersionCosting.php`
- Modify: `app/Models/Workspace.php`
- Modify: `database/factories/RecipeVersionFactory.php`
- Modify: `database/factories/WorkspaceFactory.php`
- Create: `tests/Feature/MassFoundationPersistenceTest.php`

### Step 1: Write failing persistence tests

Tests must assert:

- all three canonical/preference columns exist;
- a new formula version can persist `batch_mass_grams` to nine decimal places;
- a costing can persist `oil_mass_grams_for_costing` to nine decimal places;
- workspace `mass_display_system` casts to `MassDisplaySystem`;
- the default workspace preference is metric;
- the migration source contains backfill handling for g, kg, oz, and lb;
- no production-batch table or historical row is mutated by this migration.

Run:

```bash
php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/MassFoundationPersistenceTest.php
```

Expected: FAIL because the columns do not exist.

### Step 2: Generate and implement the migration

Generate with Artisan, then fill the generated file:

```bash
php artisan make:migration add_mass_foundation_columns --no-interaction
```

Add:

- nullable `recipe_versions.batch_mass_grams DECIMAL(20, 9)`;
- nullable `recipe_version_costings.oil_mass_grams_for_costing DECIMAL(20, 9)`;
- `workspaces.mass_display_system VARCHAR(24) DEFAULT 'metric'`.

Backfill canonical columns in ordered chunks from each row's legacy display quantity/unit using the exact factors. Keep canonical columns nullable so rollback/legacy imports and mixed-version deployments remain safe; all normal writes after this checkpoint populate them.

The migration must not alter `production_batches` or `production_batch_ingredients`.

### Step 3: Update models and factories

- Add the new attributes to each model's `#[Fillable]`.
- Cast canonical quantities with `decimal:9`.
- Cast `Workspace::mass_display_system` to `MassDisplaySystem`.
- Give workspace factories a metric default.
- Give recipe-version factories a canonical `1000.000000000` gram default.

### Step 4: Verify and commit

Run the focused test and:

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models database/factories tests/Feature/MassFoundationPersistenceTest.php
git commit -m "feat: persist canonical formula mass"
```

## Task 3: Save and Restore Formula Mass Canonically

**Files:**

- Modify: `app/Services/RecipeWorkbenchPayloadNormalizer.php`
- Modify: `app/Services/RecipeVersionRecordService.php`
- Modify: `app/Services/RecipeWorkbenchVersionPayloadMapper.php`
- Modify: `app/Services/RecipeWorkbenchViewDataBuilder.php`
- Modify: `tests/Feature/RecipeWorkbenchContractTest.php`
- Modify: `tests/Feature/CosmeticRecipeWorkbenchTest.php`
- Modify: `tests/Feature/RecipeWorkbenchViewDataBuilderTest.php`

### Step 1: Add failing soap and cosmetic contract tests

Add tests proving:

- `kg` is accepted for both product families;
- a `1 kg` soap formula saves `batch_mass_grams = 1000.000000000`;
- a `2.204622622 lb` cosmetic formula saves approximately the same canonical grams;
- saved payloads restore the selected display unit;
- restored display quantity is derived from canonical grams, not rounded legacy `batch_size`;
- a legacy `16 oz` version with `batch_mass_grams = null` still restores as `16 oz`;
- a new formula payload exposes the workspace-preferred initial mass unit.

Run the three focused test files. Expected: FAIL on unsupported kg, missing canonical mass, and missing preferred-unit payload.

### Step 2: Normalize and persist canonical mass

Inject `MassConverter` into `RecipeWorkbenchPayloadNormalizer`.

- Normalize `oil_unit` once through `MassUnit`.
- Accept g/kg/oz/lb in soap and cosmetic paths.
- Add `mass_grams` to the normalized return contract.
- Add `mass_grams` to calculation context for auditable compatibility.

Update `RecipeVersionRecordService::fillVersion()` to store `batch_mass_grams` while preserving `batch_size` and `batch_unit` as the selected display snapshot.

### Step 3: Restore from canonical mass with a legacy fallback

Inject `MassConverter` into `RecipeWorkbenchVersionPayloadMapper`.

- Preferred path: convert `batch_mass_grams` to `batch_unit`.
- Fallback path: use legacy calculation-context or batch display fields unchanged.
- Continue returning `oilWeight` and `oilUnit` so existing snapshots, printing, and Alpine state remain compatible.

Update `RecipeWorkbenchViewDataBuilder` to send `preferredMassUnit`, selected from the current workspace's `mass_display_system` and the product-family default basis (`1000 g` soap, `100 g` cosmetic).

### Step 4: Verify and commit

Run focused tests, format, and commit:

```bash
git add app/Services tests/Feature
git commit -m "feat: canonicalize formula bench mass"
```

## Task 4: Convert Units in Both Formula Bench Interfaces

**Files:**

- Create: `resources/js/recipe-workbench/mass.js`
- Modify: `resources/js/recipe-workbench/component.js`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php`
- Create: `tests/Feature/RecipeWorkbenchMassInteractionTest.php`

### Step 1: Write failing JavaScript behavior tests

The Pest test runs a small Node ESM script importing `mass.js`. It must prove:

- all four units are supported;
- `1 kg → lb` displays `2.204622622`;
- switching back returns `1`;
- row percentages are untouched;
- derived row weights change unit and preserve physical grams;
- invalid/same-unit changes are no-ops.

The same test inspects the shared Blade partial and asserts both cosmetic and soap controls:

- include g/kg/oz/lb;
- call `changeOilUnit('…')`;
- no longer assign `oilUnit = '…'` directly.

Expected: FAIL because `mass.js`, kg buttons, and the conversion action do not exist.

### Step 2: Implement the browser conversion module

`mass.js` exports:

```js
export const MASS_UNITS;
export function convertMass(value, fromUnit, toUnit);
export function preferredMassUnit(grams, displaySystem);
export function roundMass(value);
```

Use the same exact factor literals as PHP and round display state to nine decimal places.

### Step 3: Integrate Alpine state

In `component.js`:

- initialize new formulas from `payload.preferredMassUnit`;
- keep saved formulas authoritative through the existing `applyDraft()` flow;
- add `changeOilUnit(nextUnit)`;
- convert `oilWeight` before assigning `oilUnit`;
- schedule one recalculation preview after the coherent state is installed.

In the shared Blade settings partial, replace direct assignments with `changeOilUnit()` and add kg to both the cosmetic total-batch control and soap total-oils control.

Because ingredient, lye, water, additive, output, and cosmetic row weights are derived from the basis and percentages, they convert automatically with the basis. No row percentage is rewritten.

### Step 4: Verify and commit

Run:

```bash
php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/RecipeWorkbenchMassInteractionTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
npm run build
git add resources/js resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php tests/Feature/RecipeWorkbenchMassInteractionTest.php
git commit -m "fix: convert formula mass when units change"
```

## Task 5: Preserve Physical Mass in Costing and Basic Production Handoffs

**Files:**

- Modify: `app/Services/RecipeVersionCostingSynchronizer.php`
- Modify: `app/Services/RecipeVersionCostPreviewBuilder.php`
- Modify: `app/Services/ProductionSnapshotService.php`
- Modify: `resources/js/recipe-workbench/sections/costing-section.js`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php`
- Modify: `tests/Feature/RecipeVersionCostingTest.php`
- Modify: `tests/Feature/ProductionSnapshotsTest.php`
- Modify: `tests/Feature/RecipeWorkbenchMassInteractionTest.php`

### Step 1: Write failing costing and production tests

Prove:

- changing a costing override from `1 kg` to pounds preserves ingredient total;
- saving a costing override records `1000.000000000` canonical grams;
- costing payload restoration derives the precise display quantity from canonical grams;
- new costing defaults use the formula's canonical mass in its selected unit;
- a Basic production preview defaults to the formula's canonical display quantity;
- recording the batch still stores its display quantity/unit and does not mutate old snapshots;
- the existing manually-created legacy `2 lb` production test remains green.

Expected: FAIL because costing unit buttons relabel, canonical costing mass is not written, and services still default from rounded display fields.

### Step 2: Canonicalize costing persistence

Inject `MassConverter` into `RecipeVersionCostingSynchronizer`.

- On save, normalize the unit and store both display quantity/unit and canonical grams.
- On payload, derive the display quantity from canonical grams when present.
- On copy, copy canonical grams.
- On first creation, derive both costing values from the formula canonical mass with legacy fallback.

### Step 3: Convert costing UI state

In the costing section:

- import the shared mass converter;
- add `changeCostingUnit(nextUnit)`;
- convert an explicit costing override before changing its unit;
- if the override is empty, only change its preferred costing unit;
- schedule autosave after the coherent state change;
- replace the duplicated kg factors with the shared converter.

Update all four costing buttons to call this method.

### Step 4: Reuse the PHP converter in costing/production

- Replace `RecipeVersionCostPreviewBuilder::quantityToKilograms()` with `MassConverter`.
- Add focused private helpers in the costing synchronizer and production snapshot service for canonical-first, legacy-fallback display quantities.
- Keep Basic production snapshot output fields unchanged.

### Step 5: Verify and commit

Run focused tests, build, format, and commit:

```bash
git add app/Services resources/js/recipe-workbench/sections/costing-section.js resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php tests/Feature
git commit -m "fix: preserve mass across costing and production"
```

## Task 6: Workspace Mass Display Preference

**Files:**

- Modify: `app/Livewire/Dashboard/SettingsIndex.php`
- Modify: `resources/views/livewire/dashboard/settings-index.blade.php`
- Modify: `lang/en/settings.php`
- Modify: `tests/Feature/SettingsSecurityTest.php`
- Modify: `tests/Feature/SettingsLocalizationTest.php`

### Step 1: Write failing settings tests

Prove:

- workspace settings load the saved mass system;
- only the workspace owner can change it;
- only `metric` and `us_customary` validate;
- saving persists the enum value;
- the settings page describes that the choice is a default and formulas remain convertible.

Expected: FAIL because the Livewire property and control do not exist.

### Step 2: Add the workspace control

Add `workspaceMassDisplaySystem`, initialize it from the workspace, validate with `Rule::enum(MassDisplaySystem::class)`, and persist it on create/update.

Add a compact two-choice control:

- Metric — g / kg
- US customary — oz / lb

Helper text states that this controls defaults for new formulas and stock displays, while any formula or transaction may use another supported mass unit.

Add English translation keys and validation labels following the current settings conventions.

### Step 3: Verify and commit

Run:

```bash
php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/SettingsSecurityTest.php tests/Feature/SettingsLocalizationTest.php tests/Feature/RecipeWorkbenchViewDataBuilderTest.php
vendor/bin/pint --dirty --format agent
git add app/Livewire app/Models/Workspace.php resources/views/livewire/dashboard/settings-index.blade.php lang/en/settings.php tests/Feature
git commit -m "feat: add workspace mass display preference"
```

## Task 7: Print, Restore, and Regression Verification

**Files:**

- Modify as required by failing tests: `app/Services/FormulaDocumentBuilder.php`
- Modify as required by failing tests: `app/Services/RecipeVersionViewDataBuilder.php`
- Modify: `tests/Unit/FormulaDocumentBuilderTest.php`
- Modify: `tests/Feature/RecipeVersionPagesTest.php`
- Modify: `tests/Feature/RecipeWorkbenchDraftLifecycleTest.php`

### Step 1: Add regression assertions

Prove:

- print/document output retains the formula-version display unit;
- document basis and ingredient weights represent the same canonical mass after a unit round trip;
- draft publish/restore copies canonical formula and costing mass;
- existing g/oz/lb fixtures remain numerically equivalent;
- kg formulas print, restore, cost, and create Basic production previews successfully.

### Step 2: Make only compatibility fixes exposed by the tests

Keep the current public snapshot and document shapes. Derive display quantities from canonical values at mapper/service boundaries rather than adding canonical storage fields to user-facing documents.

### Step 3: Complete checkpoint verification

Run:

```bash
php -d memory_limit=512M vendor/bin/pest --compact tests/Unit/MassConverterTest.php tests/Unit/FormulaDocumentBuilderTest.php tests/Feature/MassFoundationPersistenceTest.php tests/Feature/RecipeWorkbenchMassInteractionTest.php tests/Feature/RecipeWorkbenchContractTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/RecipeVersionCostingTest.php tests/Feature/ProductionSnapshotsTest.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php tests/Feature/RecipeVersionPagesTest.php tests/Feature/SettingsSecurityTest.php tests/Feature/SettingsLocalizationTest.php
npm run build
vendor/bin/pint --dirty --format agent
php -d memory_limit=512M vendor/bin/pest --compact
graphify update .
git status --short
```

Expected:

- 0 test failures;
- frontend build succeeds;
- Pint reports no remaining changes after its fixing pass;
- graph refresh succeeds;
- only intentional Checkpoint 0 files are modified.

Commit final compatibility changes:

```bash
git add app database resources tests lang
git commit -m "test: verify formula mass foundation"
```

## Review Gate

Do not begin Checkpoint 1. Present the user with the existing soap and cosmetic Formula Benches and demonstrate:

1. `1 kg → 2.204622622 lb → 1 kg`;
2. ingredient, lye/water, cosmetic row, and costing totals preserving physical mass;
3. formula save/reload retaining the selected unit and canonical quantity;
4. workspace default affecting a new formula without locking unit choice;
5. an existing Basic production snapshot remaining unchanged.
