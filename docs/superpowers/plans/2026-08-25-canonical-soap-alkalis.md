# Canonical Soap Alkalis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make NaOH (`CH1`) and KOH (`CH3`) protected canonical soap materials, keep KOH purity as formula context, and show the selected purity consistently in Workbench costing and Production Bench.

**Architecture:** Introduce one shared resolver for canonical alkali identity and remove all category/order-based selection. Workspace ingredient authoring rejects the Soapmaking alkalis category at both UI and service boundaries. Workbench costing keeps one price identity per canonical material while its client-side row label reacts to live purity. Production planning builds the same calculated alkali lines as production creation, then freezes KOH purity and its operator-facing label in the existing JSON and name snapshots.

**Tech Stack:** PHP 8.5, Laravel 13.26, Filament 5.7, Livewire, Blade, Alpine.js, Vite 8, Tailwind CSS 4, Pest 4.7, BCMath, and spatie/laravel-translation-loader 2.8.

**Design authority:** [Canonical Soap Alkalis Design](../specs/2026-08-25-canonical-soap-alkalis-design.md).

**Data safety:** Production has no user-authored alkalis or alkali production history to migrate. Do not add a cleanup migration, backfill command, seeder-driven production update, or automatic deletion. The owner will remove development duplicates manually after verification.

---

## Product invariants

- `CH1` is the sole calculated NaOH ingredient identity.
- `CH3` is the sole calculated KOH ingredient identity.
- Workspace users cannot create, reclassify, or duplicate soapmaking alkalis.
- Admin catalogue authoring remains unchanged.
- KOH 90% and KOH 100% are formula settings, not ingredient records.
- The existing `koh_to_weigh` result is already purity-adjusted; costing and production must use it unchanged.
- A workspace keeps one current NaOH price and one current KOH price.
- Costing displays the live Workbench purity without changing the `lye_alkali` row signature.
- KOH/dual production snapshots persist `koh_purity_percentage` and an explicit KOH purity label.
- NaOH-only and cosmetic production context remains unchanged and does not receive an irrelevant KOH key.
- Production preview, formula, requirements, stock preparation, and actual-use screens consume the same calculated identity and name.
- Missing canonical material fails explicitly; no duplicate is a fallback.

## File map

- Create `app/Services/CanonicalSoapAlkaliResolver.php` as the only `naoh`/`koh` to `CH1`/`CH3` mapping.
- Delete `app/Services/Production/ProductionLyeMaterialResolver.php` after Production Bench uses the shared resolver.
- Modify `app/Enums/IngredientCategory.php`, `app/Livewire/Dashboard/IngredientEditor.php`, `resources/views/livewire/dashboard/partials/ingredient-composition-rows.blade.php`, and `app/Services/UserIngredientAuthoringService.php` to enforce the workspace authoring boundary.
- Modify `app/Services/RecipeVersionCostingSynchronizer.php` and `resources/js/recipe-workbench/sections/costing-section.js` to use canonical identities and a live KOH purity label.
- Modify `app/Services/Production/ProductionFormulaSnapshotBuilder.php` to freeze purity and the KOH label.
- Modify `app/Services/Production/ProductionAvailabilityPreview.php` to include the same calculated alkali requirements before production creation.
- Modify `lang/en/ingredients.php`, `lang/en/workbench.php`, and `database/seeders/data/interface-translations.json` for validation and purity-label copy.
- Modify `CONTEXT.md` only to record the stable domain meaning, not implementation details.
- Update focused Pest and Node-backed Workbench tests; no migration or schema test is needed.

## Pre-implementation gate

- [ ] **Step 1: Re-read repository instructions before touching code**

Open `.ai/rules/index.md`, then every matching rule for the files in the current task. At minimum this plan touches rules for app/services, dashboard Livewire, recipe-workbench JavaScript, views, language files, seed data, and tests.

- [ ] **Step 2: Search installed-version documentation**

Use Laravel Boost `search-docs` before code changes with scoped, broad queries such as:

```text
packages: ["laravel/framework", "filament/filament", "livewire/livewire"]
queries: [
  "validation exception service validation messages",
  "select options enum livewire form testing",
  "dependency injection service container"
]
```

Confirm installed package versions with `composer show --direct` and `package.json`; do not add or update dependencies.

- [ ] **Step 3: Confirm the working tree and baseline**

```bash
git status --short --branch
php artisan test --compact tests/Feature/RecipeWorkbenchCostingAlkaliTest.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/ProductionFormulaSnapshotTest.php
```

Expected: the branch state is understood and the current focused tests pass before RED tests are introduced. Preserve unrelated user changes and stage files path-by-path.

---

### Task 1: Establish one canonical alkali resolver

**Files:**

- Create: `app/Services/CanonicalSoapAlkaliResolver.php`
- Create: `tests/Feature/CanonicalSoapAlkaliResolverTest.php`
- Modify: `lang/en/ingredients.php`

- [ ] **Step 1: Generate the service and failing feature test**

Use the application generator:

```bash
php artisan make:class Services/CanonicalSoapAlkaliResolver --no-interaction
php artisan make:test --pest CanonicalSoapAlkaliResolverTest --no-interaction
```

In `tests/Feature/CanonicalSoapAlkaliResolverTest.php`, use `uses(RefreshDatabase::class);` and factories to cover:

1. `resolve('naoh')` returns the active platform record with `catalog_key = CH1`.
2. `resolve('koh')` returns the active platform record with `catalog_key = CH3`.
3. Accessible workspace/user-owned duplicates with matching category and subcategory never affect either result.
4. A missing or inactive canonical record throws `ValidationException` even if a duplicate exists.
5. An unsupported internal lye type throws `InvalidArgumentException` rather than querying broadly.

The decisive assertion should resemble:

```php
expect($resolver->resolve('naoh')->is($canonicalNaoh))->toBeTrue()
    ->and($resolver->resolve('koh')->is($canonicalKoh))->toBeTrue();
```

- [ ] **Step 2: Run the resolver test and verify RED**

```bash
php artisan test --compact tests/Feature/CanonicalSoapAlkaliResolverTest.php
```

Expected: FAIL because the resolver contract and translation key do not exist.

- [ ] **Step 3: Implement the resolver with stable keys only**

Implement this public contract in `app/Services/CanonicalSoapAlkaliResolver.php`:

```php
final class CanonicalSoapAlkaliResolver
{
    private const array CATALOG_KEY_BY_LYE_TYPE = [
        'naoh' => 'CH1',
        'koh' => 'CH3',
    ];

    public function resolve(string $lyeType): Ingredient;

    /** @param list<string> $lyeTypes */
    public function resolveMany(array $lyeTypes): Collection;
}
```

Implementation rules:

- Reject an unknown `$lyeType` with `InvalidArgumentException`.
- Query with `withoutGlobalScopes()`.
- Require `owner_type IS NULL`, `workspace_id IS NULL`, `is_active = true`, and the exact `catalog_key`.
- Throw `ValidationException::withMessages()` using `ingredients.alkalis.validation.canonical_missing` when the record is absent.
- Return `resolveMany()` keyed by lye type so callers retain stable `naoh`, `koh` semantics.
- Do not query category, subcategory, user accessibility, record order, or maximum ID.

Use collection transformations in `resolveMany()` to match repository convention:

```php
return collect($lyeTypes)
    ->unique()
    ->mapWithKeys(fn (string $lyeType): array => [
        $lyeType => $this->resolve($lyeType),
    ]);
```

- [ ] **Step 4: Add the English missing-canonical validation source**

Add under a short `alkalis.validation` key in `lang/en/ingredients.php`:

```php
'canonical_missing' => 'The required canonical alkali (:key) is missing from the ingredient catalogue.',
```

Do not remove the existing Production Bench validation key yet; it may remain as an unused backwards-compatible translation until a deliberate prune.

- [ ] **Step 5: Verify and commit the resolver foundation**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/CanonicalSoapAlkaliResolverTest.php
git add app/Services/CanonicalSoapAlkaliResolver.php lang/en/ingredients.php tests/Feature/CanonicalSoapAlkaliResolverTest.php
git commit -m "refactor: centralize canonical soap alkalis"
```

Expected: focused test PASS.

---

### Task 2: Close workspace alkali authoring at UI and service boundaries

**Files:**

- Modify: `app/Enums/IngredientCategory.php`
- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `resources/views/livewire/dashboard/partials/ingredient-composition-rows.blade.php`
- Modify: `app/Services/UserIngredientAuthoringService.php`
- Modify: `lang/en/ingredients.php`
- Modify: `tests/Feature/UserIngredientAuthoringTest.php`
- Modify: `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`

- [ ] **Step 1: Write failing authoring-boundary tests**

Extend `tests/Feature/UserIngredientAuthoringTest.php` to prove:

- `IngredientCategory::workspaceAuthorableOptions()` omits `SoapmakingAlkalis` but retains ordinary categories.
- A blank workspace `IngredientEditor` does not render the Soapmaking alkalis option in either the main category selector or quick blend-component selector.
- `UserIngredientAuthoringService::create()` rejects a crafted `soapmaking_alkalis` category.
- Updating an editable workspace ingredient into that category is rejected and leaves its previous category unchanged.
- Quick component creation is protected by the same service guard, not only hidden markup.

Extend `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php` with a platform NaOH/KOH fixture and assert `duplicate()` throws `ValidationException` before a workspace copy is created.

Use the new validation message in assertions rather than matching a generic exception:

```php
expect(fn () => $service->create($state, $user))
    ->toThrow(ValidationException::class, __('ingredients.editor.validation.soapmaking_alkalis_platform_only'));
```

- [ ] **Step 2: Run the authoring tests and verify RED**

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php
```

Expected: FAIL because the category is still exposed and accepted.

- [ ] **Step 3: Add an explicit workspace-authorable category API**

In `app/Enums/IngredientCategory.php`, keep `options()` unchanged for Admin and other full-catalogue consumers. Add:

```php
public function isWorkspaceAuthorable(): bool
{
    return $this !== self::SoapmakingAlkalis;
}

/** @return array<string, string> */
public static function workspaceAuthorableOptions(): array
{
    return collect(self::cases())
        ->filter(fn (self $category): bool => $category->isWorkspaceAuthorable())
        ->mapWithKeys(fn (self $category): array => [
            $category->value => (string) $category->getLabel(),
        ])
        ->all();
}
```

Do not change `IngredientCategory::options()` globally; the Admin resource must retain Soapmaking alkalis.

- [ ] **Step 4: Use the bounded options in both workspace selectors**

Replace `IngredientCategory::options()` with `IngredientCategory::workspaceAuthorableOptions()` only in:

- the `category` `Select` in `app/Livewire/Dashboard/IngredientEditor.php`; and
- the quick-component `<select>` loop in `resources/views/livewire/dashboard/partials/ingredient-composition-rows.blade.php`.

Do not modify `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`.

- [ ] **Step 5: Enforce the boundary inside the authoring service**

In `UserIngredientAuthoringService::fillIngredient()`, normalize the submitted category to `IngredientCategory`, then call a private guard before mutating the model:

```php
private function assertWorkspaceAuthorableCategory(IngredientCategory $category): void
{
    if ($category->isWorkspaceAuthorable()) {
        return;
    }

    throw ValidationException::withMessages([
        'category' => __('ingredients.editor.validation.soapmaking_alkalis_platform_only'),
    ]);
}
```

Call the same guard near the beginning of `duplicate()` using `$source->category`. This must occur before quota locking and before the copy is saved.

Add the English source:

```php
'soapmaking_alkalis_platform_only' => 'Soapmaking alkalis are managed by Koskalk and cannot be created or duplicated in a workspace.',
```

- [ ] **Step 6: Verify and commit the authoring boundary**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php
git add app/Enums/IngredientCategory.php app/Livewire/Dashboard/IngredientEditor.php app/Services/UserIngredientAuthoringService.php resources/views/livewire/dashboard/partials/ingredient-composition-rows.blade.php lang/en/ingredients.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php
git commit -m "fix: protect canonical soap alkalis from workspace authoring"
```

Expected: both focused files PASS. `vendor/bin/filacheck` is not required because no `app/Filament` file changed.

---

### Task 3: Make Workbench costing canonical and purity-aware

**Files:**

- Modify: `app/Services/RecipeVersionCostingSynchronizer.php`
- Modify: `resources/js/recipe-workbench/sections/costing-section.js`
- Modify: `lang/en/workbench.php`
- Modify: `tests/Feature/RecipeWorkbenchCostingAlkaliTest.php`
- Modify: `tests/Feature/RecipeWorkbenchMassInteractionTest.php`

- [ ] **Step 1: Rewrite the old “user-owned alkali wins” test**

In `tests/Feature/RecipeWorkbenchCostingAlkaliTest.php`:

- update helpers so the platform NaOH/KOH fixtures have exact keys `CH1`/`CH3`, `owner_type = null`, `owner_id = null`, and `workspace_id = null`;
- retain an accessible workspace duplicate fixture with the same category/subcategory;
- replace `prices a user-owned alkali ingredient as the costing identity when present` with a test asserting the canonical key wins;
- assert NaOH, KOH, and dual-lye costing rows keep positions `1`, `1`, and `[1, 2]` as today;
- assert the existing KOH formula price round-trips against `CH3`, not a duplicate; and
- assert missing `CH1`/`CH3` throws instead of yielding an empty or duplicate-backed row.

The core regression assertion should be:

```php
expect($payload['alkali_ingredients']['koh']['ingredient_id'])->toBe($canonicalKoh->id)
    ->and($payload['alkali_ingredients']['koh']['ingredient_id'])->not->toBe($workspaceDuplicate->id);
```

- [ ] **Step 2: Add a failing live-label Node scenario**

Append a Node-backed test in `tests/Feature/RecipeWorkbenchMassInteractionTest.php` using the existing `Symfony\Component\Process\Process` pattern. Load `costing-section.js`, attach its descriptors to a small state, and assert:

```js
state.kohPurity = 90;
assert.equal(state.costingAlkaliRows()[0].name, 'Potassium hydroxide (KOH 90%)');

state.kohPurity = 100;
assert.equal(state.costingAlkaliRows()[0].name, 'Potassium hydroxide (KOH 100%)');
```

Also assert the row keeps the same `ingredient_id`, `phaseKey`, and `position` across the purity change, and its weight follows `selectedAlkaliWeights().koh` rather than being recalculated in the label code.

- [ ] **Step 3: Run costing tests and verify RED**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchCostingAlkaliTest.php tests/Feature/RecipeWorkbenchMassInteractionTest.php
```

Expected: FAIL because costing still resolves accessible subcategories by order and does not format KOH purity.

- [ ] **Step 4: Replace subcategory/order resolution with the canonical service**

Constructor-inject `CanonicalSoapAlkaliResolver` into `RecipeVersionCostingSynchronizer`.

Remove:

- `ALKALI_SUBCATEGORY_BY_LYE_TYPE`;
- `IngredientCategory` and `IngredientSubcategory` imports used only by alkali resolution; and
- `soapAlkaliIngredientsByLyeType(?User $user)` with its accessibility/order query.

Resolve both identities for the frontend payload because the user can change lye type without reloading:

```php
$ingredients = $this->alkaliResolver->resolveMany(['naoh', 'koh']);
```

Resolve only selected types when constructing desired rows. Preserve the existing selection and order contract:

```php
$lyeTypes = match ($lyeType) {
    'dual' => ['naoh', 'koh'],
    'koh' => ['koh'],
    default => ['naoh'],
};
```

Remove now-unnecessary `$user` parameters from the private alkali payload/desired-row helpers and from `syncFormulaItems()` itself, then update each internal call site. Price memory remains handled by `applyItemPrices()` and is not changed by this refactor.

Do not alter:

- `lye_alkali` phase key;
- row positions;
- `RecipeVersionCostingItem` schema;
- current-material-price storage; or
- the `koh_to_weigh` mass source.

- [ ] **Step 5: Format the KOH name from live Workbench state**

Add this English source under `workbench.costing.ingredients`:

```php
'koh_with_purity' => ':name (KOH :purity%)',
```

In `costingAlkaliRows()`, keep the backend payload's canonical localized ingredient name, but derive the visible row name reactively:

```js
name: lyeType === 'koh'
    ? this.t('costing.ingredients.koh_with_purity', {
        name: ingredient.name,
        purity: this.format(this.kohPurity, 0),
    })
    : ingredient.name,
```

Because `costingFormulaRows` is computed from Alpine state, this updates immediately from 90% to 100% without another database record or payload reload.

Do not clear or fork the price when purity changes. This plan intentionally keeps one canonical KOH current price; grade-specific purchasing is a future explicit model.

- [ ] **Step 6: Verify costing and commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/CanonicalSoapAlkaliResolverTest.php tests/Feature/RecipeWorkbenchCostingAlkaliTest.php tests/Feature/RecipeWorkbenchMassInteractionTest.php
npm run build
git add app/Services/RecipeVersionCostingSynchronizer.php resources/js/recipe-workbench/sections/costing-section.js lang/en/workbench.php tests/Feature/RecipeWorkbenchCostingAlkaliTest.php tests/Feature/RecipeWorkbenchMassInteractionTest.php
git commit -m "fix: cost canonical alkalis with visible KOH purity"
```

Expected: tests PASS and Vite builds successfully.

---

### Task 4: Freeze KOH purity throughout Production Bench

**Files:**

- Delete: `app/Services/Production/ProductionLyeMaterialResolver.php`
- Modify: `app/Services/Production/ProductionFormulaSnapshotBuilder.php`
- Modify: `app/Services/Production/ProductionAvailabilityPreview.php`
- Modify: `lang/en/ingredients.php`
- Modify: `tests/Feature/ProductionFormulaSnapshotTest.php`
- Modify: `tests/Feature/ProductionCalculatedMaterialsTest.php`
- Modify: `tests/Feature/ProductionBenchProductionCreateTest.php`
- Modify: `tests/Feature/ProductionDetailPresenterTest.php`

- [ ] **Step 1: Add failing formula-snapshot purity assertions**

In `tests/Feature/ProductionFormulaSnapshotTest.php`, extend the KOH test to assert:

```php
expect($kohLine['ingredient_id'])->toBe($canonicalKoh->id)
    ->and($kohLine['subject_name_snapshot'])->toBe('Potassium hydroxide (KOH 90%)')
    ->and($snapshot['context']['koh_purity_percentage'])->toBe(90.0);
```

Add a KOH 100% case by changing the version's `calculation_context.koh_purity_percentage` and assert:

- the line label becomes `Potassium hydroxide (KOH 100%)`;
- the context becomes `100.0`;
- the ingredient remains `CH3`; and
- the 100% planned mass is lower than the otherwise equivalent 90% planned mass, proving the existing calculation controls mass.

Extend the dual-lye test with the same KOH purity context/name assertions. Keep the exact NaOH-only context test unchanged to prove the new key is conditional.

- [ ] **Step 2: Add failing requirement and presenter propagation assertions**

In `tests/Feature/ProductionCalculatedMaterialsTest.php`, assert a KOH production's formula line and calculated requirement both carry `Potassium hydroxide (KOH 90%)`.

In `tests/Feature/ProductionDetailPresenterTest.php`, add or adapt a formula-line fixture with that snapshotted name and assert the presenter returns it unchanged as `material_name`. This proves the Production Bench detail/actual-use view does not rebuild a live label.

- [ ] **Step 3: Add a failing planning-preview test**

Use the KOH fixture in `tests/Feature/ProductionCalculatedMaterialsTest.php` or a focused fixture in `tests/Feature/ProductionBenchProductionCreateTest.php` to call/render the Production Bench preview before saving. Assert it contains:

```text
Potassium hydroxide (KOH 90%)
```

and that `ProductionRun::query()->count()` remains zero. This closes the current gap where calculated alkalis are added only during production creation and can be absent from the availability preview.

- [ ] **Step 4: Run Production Bench tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionFormulaSnapshotTest.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionDetailPresenterTest.php
```

Expected: FAIL because the builder stores a bare KOH label/context and the planning preview does not append calculated lye requirements.

- [ ] **Step 5: Replace the production-specific resolver with the shared resolver**

Constructor-inject `CanonicalSoapAlkaliResolver` into `ProductionFormulaSnapshotBuilder` and delete `ProductionLyeMaterialResolver`.

Add a small private mapping from `ProductionFormulaComponent::Naoh`/`Koh` to `naoh`/`koh`; Water and Ingredient return `null`. Resolve only alkali components and leave Water's `ingredient_id` behavior unchanged.

Do not duplicate `CH1`/`CH3` constants in the production namespace.

- [ ] **Step 6: Freeze purity and explicit KOH names in the snapshot builder**

Read purity from the persisted/recomputed draft:

```php
$kohPurityPercentage = (float) ($draft['kohPurity'] ?? 90);
```

Add a shared server-side translation under `ingredients.alkalis`:

```php
'koh_with_purity' => ':name (KOH :purity%)',
```

Build the KOH line name from the resolved canonical ingredient's localized name and an integer purity display. NaOH uses its canonical localized ingredient name; Water keeps the Production Bench translation.

Refactor `componentLabel()` to receive the resolved ingredient and KOH purity or introduce a tightly scoped private `alkaliLabel()` helper. Do not read a category display name or hard-code “Potash”.

In `context()`, conditionally add the stable fact only for KOH-bearing formulas:

```php
...($isSoap && in_array($lyeType, ['koh', 'dual'], true) ? [
    'koh_purity_percentage' => (float) ($draft['kohPurity'] ?? 90),
] : []),
```

This preserves existing exact NaOH and cosmetic context arrays while freezing the fact where it matters.

- [ ] **Step 7: Make production availability preview mirror creation**

Inject `ProductionFormulaSnapshotBuilder` and `ProductionCalculatedRequirementBuilder` into `ProductionAvailabilityPreview`.

After `ProductionRequirementBuilder::build()` returns explicit ingredient/packaging requirements:

1. build the formula snapshot with the selected recipe, published version, basis grams, and explicit requirements;
2. create calculated NaOH/KOH requirements using `ProductionCalculatedRequirementBuilder`;
3. concatenate them to the preview requirements with the same starting sort-order rule as `CreateProductionDraft`; and
4. continue through the existing subject and stock-position formatting.

Extract a small shared private composition method only if it reduces exact duplication without widening scope. The preview remains read-only and must not create `ProductionRun`, formula-line, requirement, reservation, or stock-movement records.

The calculated requirement already copies `subject_name_snapshot`, so no downstream Blade or presenter-specific label reconstruction is needed.

- [ ] **Step 8: Verify Production Bench and commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/CanonicalSoapAlkaliResolverTest.php tests/Feature/ProductionFormulaSnapshotTest.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionPlanningTest.php
git add app/Services/CanonicalSoapAlkaliResolver.php app/Services/Production/ProductionFormulaSnapshotBuilder.php app/Services/Production/ProductionAvailabilityPreview.php app/Services/Production/ProductionLyeMaterialResolver.php lang/en/ingredients.php tests/Feature/ProductionFormulaSnapshotTest.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionPlanningTest.php
git commit -m "fix: freeze KOH purity in production workflows"
```

Expected: focused files PASS. The deleted resolver is staged explicitly.

---

### Task 5: Complete localization and record the domain boundary

**Files:**

- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php`
- Modify: `tests/Feature/SoapWorkbenchLocalizationTest.php`
- Modify: `CONTEXT.md`

- [ ] **Step 1: Add focused catalogue assertions for the new keys**

In the relevant translation tests, assert every configured catalogue locale has a non-blank translation for:

```text
ingredients.alkalis.validation.canonical_missing
ingredients.alkalis.koh_with_purity
ingredients.editor.validation.soapmaking_alkalis_platform_only
workbench.costing.ingredients.koh_with_purity
```

Assert the two KOH label translations preserve both `:name` and `:purity`. The general catalogue placeholder test remains the final authority.

- [ ] **Step 2: Synchronize, review, and export the catalogue**

Run the additive synchronizer:

```bash
php artisan translations:sync --no-interaction
```

Fill reviewed `de`, `es`, `fr`, `it`, `nl`, and `pt_BR` values in the database-backed workflow. Chemical label values may keep the invariant form `:name (KOH :purity%)`; validation prose must be natural in each locale and preserve `:key` where present.

Export deterministically:

```bash
php artisan translations:catalogue:export --no-interaction
```

Do not edit locale PHP files, prune unrelated translations, or rerun ingredient seeders against production.

- [ ] **Step 3: Record the durable domain term**

Add a concise glossary entry to `CONTEXT.md`:

```markdown
**Soapmaking alkali**:
A Koskalk-curated canonical sodium hydroxide or potassium hydroxide material used by the soap calculation. Workspaces set formula purity and costing, but do not author the material identity.
```

Do not add catalog keys or class names to `CONTEXT.md`; those remain implementation details in this plan/design.

- [ ] **Step 4: Verify localization and commit**

```bash
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/SoapWorkbenchLocalizationTest.php
git add CONTEXT.md database/seeders/data/interface-translations.json tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/SoapWorkbenchLocalizationTest.php
git commit -m "docs: define canonical soap alkali terminology"
```

Expected: translation catalogue is complete, deterministically ordered, and placeholder-safe.

---

### Task 6: Run regression and quality gates

**Files:**

- No planned source changes; fix only failures caused by this implementation.

- [ ] **Step 1: Run the complete focused regression set**

```bash
php artisan test --compact tests/Feature/CanonicalSoapAlkaliResolverTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/RecipeWorkbenchCostingAlkaliTest.php tests/Feature/RecipeWorkbenchMassInteractionTest.php tests/Feature/ProductionFormulaSnapshotTest.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionPlanningTest.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/SoapWorkbenchLocalizationTest.php
```

Expected: all focused tests PASS.

- [ ] **Step 2: Run adjacent production and costing regressions**

```bash
php artisan test --compact tests/Feature/RecipeVersionCostingTest.php tests/Feature/RecipeWorkbenchCostingContentTest.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionFormulaSnapshotBackfillTest.php tests/Feature/ProductionStockPreparationTest.php
```

Expected: all adjacent tests PASS, including legacy backfill and actual-use flows.

- [ ] **Step 3: Run format/build/static repository gates**

```bash
vendor/bin/pint --dirty --format agent
npm run build
graphify update .
git diff --check
git status --short
```

No `vendor/bin/filacheck --fix` run is required unless an implementation unexpectedly changes `app/Filament`; if it does, run Filacheck and resolve every unfixable report before continuing.

- [ ] **Step 4: Inspect the final diff for forbidden scope**

Confirm the diff contains none of the following:

- a database migration;
- an alkali cleanup or deletion command;
- separate KOH 90%/100% ingredients;
- a new costing variant/grade column;
- a changed soap calculation formula;
- Admin alkali-authoring removal;
- automatic mutation of completed production snapshots; or
- unrelated worktree changes.

- [ ] **Step 5: Final commit if verification required fixes**

Stage only the concrete files changed to resolve implementation-caused failures, list those paths explicitly in `git add`, then commit with:

```bash
git commit -m "test: cover canonical alkali workflows"
```

Skip this commit if verification made no changes.

## Manual acceptance checklist

- [ ] Open a workspace ingredient creation screen; Soapmaking alkalis is not an option.
- [ ] Open quick blend-component creation; Soapmaking alkalis is not an option there either.
- [ ] Open a KOH 90% formula's Costing tab; the row says `Potassium hydroxide (KOH 90%)`.
- [ ] Switch to KOH 100%; the label changes to `Potassium hydroxide (KOH 100%)` and no ingredient is created.
- [ ] Confirm the costing row still references `CH3` and its mass matches `koh_to_weigh`.
- [ ] Plan a KOH 90% production; the requirements preview names the material with 90% purity.
- [ ] Save the production; formula detail, stock preparation, and actual-use entry retain the same explicit label.
- [ ] Change or delete the source formula where allowed; the saved production label remains unchanged.
- [ ] Confirm missing `CH3` produces a clear validation failure rather than choosing a duplicate.
- [ ] Remove development-only duplicate alkalis manually only after all automated and manual checks pass.
