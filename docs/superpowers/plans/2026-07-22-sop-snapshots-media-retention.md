# SOP Snapshots and Media Retention Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every saved Formula Sheet use the manufacturing procedure captured with that saved formula, while retaining shared media only as long as current content or retained history still references it.

**Architecture:** Store one nullable SOP HTML snapshot on each `recipe_versions` row. Formula save/publish carries the currently edited procedure into `RecipeVersionRecordService`, and content-only saves synchronize the mutable current version without touching older saves. A centralized reference service combines current recipe content and retained version snapshots before any attachment deletion. Entitlement-controlled pruning keeps the latest save for free accounts and up to three earlier saves for paid accounts.

**Tech Stack:** PHP 8.5, Laravel 13, Eloquent, PostgreSQL, Filament rich content, Pest 4.

---

## Preconditions and boundaries

Implement these plans first:

1. `docs/superpowers/plans/2026-07-22-upload-original-filenames.md`
2. `docs/superpowers/plans/2026-07-22-instructions-media-workflow.md`

Product description and featured image remain current product-level content. Only manufacturing procedure/SOP is snapshotted here. Media binaries are referenced, not copied. This plan does not build the Product page; it prepares correct data for its Manufacturing destination and corrects Formula Sheet output now.

The saved-history model remains the current formula plus saved snapshots. It is not renamed “version history” in user copy.

## File map

- Create `database/migrations/2026_07_22_140000_add_manufacturing_instructions_to_recipe_versions.php`.
- Modify `app/Models/RecipeVersion.php`.
- Create `app/Services/RecipeSopSnapshotService.php`.
- Create `app/Services/RecipeMediaReferenceService.php`.
- Modify `app/Services/RecipeWorkbenchPayloadNormalizer.php`.
- Modify `app/Services/RecipeVersionRecordService.php`.
- Modify `app/Services/RecipeContentUpdater.php`.
- Modify `app/Livewire/Dashboard/RecipeWorkbench.php`.
- Modify `app/Services/RecipeRichContentAttachmentProvider.php`.
- Modify `app/Services/RecipeVersionPublisher.php` and `RecipeVersionDeletionService.php`.
- Modify `app/Services/RecipeVersionViewDataBuilder.php`.
- Modify `app/Services/EntitlementService.php`.
- Modify `database/seeders/PlanSeeder.php` and `database/factories/PlanLimitFactory.php`.
- Create `app/Console/Commands/PruneOrphanedRecipeMedia.php` and modify `routes/console.php`.
- Modify `tests/Feature/RecipeWorkbenchDraftLifecycleTest.php`, `RecipeWorkbenchPersistenceTest.php`, `RecipeVersionPagesTest.php`, `RecipeContentMediaContractTest.php`, `MediaStorageTest.php`, and `EntitlementLimitsTest.php`.
- Create `tests/Unit/RecipeMediaReferenceServiceTest.php`.

### Task 1: Add the SOP snapshot column

**Files:**
- Create: `database/migrations/2026_07_22_140000_add_manufacturing_instructions_to_recipe_versions.php`
- Modify: `app/Models/RecipeVersion.php`
- Test: `tests/Feature/RecipeSopSnapshotSchemaTest.php`

- [ ] **Step 1: Generate the migration and schema test**

```bash
php artisan make:migration add_manufacturing_instructions_to_recipe_versions --no-interaction
php artisan make:test --pest RecipeSopSnapshotSchemaTest --no-interaction
```

Rename the migration to the timestamp in the file map if needed.

- [ ] **Step 2: Write the failing schema/model test**

```php
<?php

use App\Models\RecipeVersion;
use Illuminate\Support\Facades\Schema;

it('stores a nullable manufacturing procedure on each recipe version', function () {
    expect(Schema::hasColumn('recipe_versions', 'manufacturing_instructions'))->toBeTrue();

    $version = RecipeVersion::factory()->create([
        'manufacturing_instructions' => '<p>Heat phase A to 70 °C.</p>',
    ]);

    expect($version->fresh()->manufacturing_instructions)
        ->toBe('<p>Heat phase A to 70 °C.</p>');
});
```

- [ ] **Step 3: Run and confirm failure**

```bash
php artisan test --compact tests/Feature/RecipeSopSnapshotSchemaTest.php
```

- [ ] **Step 4: Implement the migration and model attribute**

Add nullable `longText('manufacturing_instructions')` in `up()` and drop it in `down()`. Do not backfill from current recipes because doing so would falsely claim historical accuracy. Add the field to `RecipeVersion`'s `#[Fillable]` list.

- [ ] **Step 5: Run the test and commit**

```bash
php artisan test --compact tests/Feature/RecipeSopSnapshotSchemaTest.php
git add database/migrations/2026_07_22_140000_add_manufacturing_instructions_to_recipe_versions.php app/Models/RecipeVersion.php tests/Feature/RecipeSopSnapshotSchemaTest.php
git commit -m "feat: store formula SOP snapshots"
```

### Task 2: Capture the on-screen procedure with formula saves

**Files:**
- Create: `app/Services/RecipeSopSnapshotService.php`
- Modify: `app/Services/RecipeWorkbenchPayloadNormalizer.php`
- Modify: `app/Services/RecipeVersionRecordService.php`
- Modify: `app/Services/RecipeContentUpdater.php`
- Modify: `app/Livewire/Dashboard/RecipeWorkbench.php`
- Test: `tests/Feature/RecipeWorkbenchDraftLifecycleTest.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`

- [ ] **Step 1: Write failing lifecycle tests**

Cover all four paths:

1. first formula save with pending SOP stores it on the newly created current version;
2. content-only Save changes updates the current version's SOP;
3. publishing copies the visible SOP to the published snapshot and the new current version;
4. changing and saving current SOP later does not mutate the older published snapshot.

The final assertion must be equivalent to:

```php
expect($published->fresh()->manufacturing_instructions)->toBe('<p>Original procedure</p>')
    ->and($current->fresh()->manufacturing_instructions)->toBe('<p>Revised procedure</p>');
```

- [ ] **Step 2: Run tests and confirm failure**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchDraftLifecycleTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

- [ ] **Step 3: Preserve SOP in the normalized formula payload**

Add a nullable trimmed `manufacturing_instructions` key to both soap and cosmetic normalizer return arrays. Rich HTML is not translated or stripped; blank content becomes `null` using the existing nullable helper.

Before `RecipeWorkbench` calls formula `save`, `publish`, `duplicateFormula`, or restore-related persistence, merge the current pending content form value into the draft payload:

```php
$draft['manufacturing_instructions'] = $this->pendingRichContentValue('manufacturing_instructions');
```

This is required for a brand-new formula because no recipe row exists until the formula save transaction runs.

- [ ] **Step 4: Store the snapshot through the version record service**

In `RecipeVersionRecordService::fillVersion()`, assign:

```php
$recipeVersion->manufacturing_instructions = $normalizedPayload['manufacturing_instructions'] ?? null;
```

Both the published snapshot and new current version receive the same visible procedure at publish time.

- [ ] **Step 5: Synchronize content-only saves to the current version**

`RecipeSopSnapshotService::syncCurrentVersion(Recipe $recipe, ?string $instructions): void` updates only the `is_current = true` row for that recipe. It must not create a version and must never update non-current rows.

Call it inside the same transaction as `RecipeContentUpdater` after saving the recipe. The current recipe and mutable current version then agree even when the user saves only Instructions & media.

- [ ] **Step 6: Run lifecycle tests and commit**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchDraftLifecycleTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
git add app/Services/RecipeSopSnapshotService.php app/Services/RecipeWorkbenchPayloadNormalizer.php app/Services/RecipeVersionRecordService.php app/Services/RecipeContentUpdater.php app/Livewire/Dashboard/RecipeWorkbench.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
git commit -m "feat: capture SOP with formula saves"
```

### Task 3: Make Formula Sheets read the selected snapshot

**Files:**
- Modify: `app/Services/RecipeVersionViewDataBuilder.php`
- Test: `tests/Feature/RecipeVersionPagesTest.php`

- [ ] **Step 1: Write a failing historical Formula Sheet test**

Create one recipe with current instructions `Revised procedure` and one older non-current version with `Original procedure`. Request the older saved formula page and print route. Assert both show `Original procedure` and do not show `Revised procedure`. Request the current sheet and assert it shows the revised procedure.

Add a legacy-null test: when a historical row has `manufacturing_instructions = null`, show no procedure rather than falling forward to the recipe's newer content.

- [ ] **Step 2: Run and confirm failure**

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php
```

Expected: older pages currently use `$recipe->manufacturing_instructions`.

- [ ] **Step 3: Switch the document adapter to the selected version**

In `RecipeVersionViewDataBuilder`, change only:

```php
'manufacturing_procedure' => $version->manufacturing_instructions,
```

Keep product description current through `$recipe->description`; it is intentionally not versioned.

- [ ] **Step 4: Run and commit**

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php
git add app/Services/RecipeVersionViewDataBuilder.php tests/Feature/RecipeVersionPagesTest.php
git commit -m "fix: render saved SOP snapshots"
```

### Task 4: Protect media referenced by current or historical SOP content

**Files:**
- Create: `app/Services/RecipeMediaReferenceService.php`
- Modify: `app/Services/RecipeContentUpdater.php`
- Modify: `app/Services/RecipeRichContentAttachmentProvider.php`
- Modify: `app/Services/RecipeVersionDeletionService.php`
- Modify: `app/Services/RecipeVersionPublisher.php`
- Test: `tests/Unit/RecipeMediaReferenceServiceTest.php`
- Test: `tests/Feature/RecipeContentMediaContractTest.php`
- Test: `tests/Feature/MediaStorageTest.php`

- [ ] **Step 1: Generate the unit test and write failing reference cases**

```bash
php artisan make:test --pest --unit RecipeMediaReferenceServiceTest --no-interaction
```

Test that the service returns the union of:

- current recipe description attachments;
- current recipe manufacturing attachments;
- current-version SOP attachments;
- every retained non-current SOP attachment.

Repeated paths are unique. Featured images remain current product media and are not inferred from SOP HTML.

- [ ] **Step 2: Add failing cleanup integration tests**

Required cases:

- removing an image from current SOP keeps the object when an older saved SOP references it;
- deleting the last saved snapshot that references the object deletes it after commit;
- pruning an expired snapshot deletes only newly orphaned objects;
- a path shared by description and SOP survives removal from either one;
- moving an image between description and SOP does not delete or copy it;
- a rolled-back version deletion never deletes the object.

- [ ] **Step 3: Run and confirm failure**

```bash
php artisan test --compact tests/Unit/RecipeMediaReferenceServiceTest.php tests/Feature/RecipeContentMediaContractTest.php tests/Feature/MediaStorageTest.php
```

- [ ] **Step 4: Implement centralized reference queries**

`RecipeMediaReferenceService` uses `RichContentAttachmentPaths` from plan 2. Provide:

```php
public function allReferencedPaths(Recipe $recipe): Collection;
public function historicalSopPaths(Recipe $recipe): Collection;
public function deleteIfUnreferenced(Recipe $recipe, Collection $candidatePaths): void;
```

Query versions with `withoutGlobalScopes()`, select only `manufacturing_instructions`, and include current plus non-current retained rows. `deleteIfUnreferenced()` runs only after the owning database transaction commits and calls `MediaStorage::deleteRecipePath()` only for candidates absent from the fresh reference union.

- [ ] **Step 5: Route every rich-media deletion through the service**

- `RecipeContentUpdater` submits removed rich paths as candidates instead of deleting directly.
- `RecipeRichContentAttachmentProvider::cleanUpFileAttachments()` merges historical SOP paths into its preserved IDs. It must not delete a historical reference before the updater transaction can evaluate it.
- `RecipeVersionDeletionService` captures the deleted version's SOP paths, deletes the row transactionally, and schedules orphan evaluation with `DB::afterCommit()`.
- `RecipeVersionPublisher` must prune through a service path that performs the same capture-and-after-commit cleanup; it must not call Eloquent `delete()` directly on pruned rows.

Recipe deletion may continue deleting the entire recipe media directory after the recipe itself is gone.

- [ ] **Step 6: Run cleanup tests and commit**

```bash
php artisan test --compact tests/Unit/RecipeMediaReferenceServiceTest.php tests/Feature/RecipeContentMediaContractTest.php tests/Feature/MediaStorageTest.php
git add app/Services/RecipeMediaReferenceService.php app/Services/RecipeContentUpdater.php app/Services/RecipeRichContentAttachmentProvider.php app/Services/RecipeVersionDeletionService.php app/Services/RecipeVersionPublisher.php tests/Unit/RecipeMediaReferenceServiceTest.php tests/Feature/RecipeContentMediaContractTest.php tests/Feature/MediaStorageTest.php
git commit -m "feat: retain media used by saved SOPs"
```

### Task 5: Apply free and paid saved-history retention

**Files:**
- Modify: `app/Services/EntitlementService.php`
- Modify: `app/Services/RecipeVersionPublisher.php`
- Modify: `database/seeders/PlanSeeder.php`
- Modify: `database/factories/PlanLimitFactory.php`
- Modify: `tests/Feature/EntitlementLimitsTest.php`
- Modify: `tests/Feature/RecipeWorkbenchDraftLifecycleTest.php`
- Modify: `tests/Feature/RecipeVersionPagesTest.php`

- [ ] **Step 1: Write failing entitlement and pruning tests**

Define `saved_formula_history` as the number of **earlier** saves retained in addition to the latest saved snapshot.

Test:

- default free plan value is `0`;
- a free user keeps one non-current saved snapshot plus the current draft;
- a paid plan with value `3` keeps the latest saved snapshot, three earlier snapshots, and the current draft;
- the oldest snapshot is pruned on the next publish;
- saved-history navigation never exposes a pruned row;
- media cleanup from Task 4 runs for the pruned row.

- [ ] **Step 2: Run and confirm failure**

```bash
php artisan test --compact tests/Feature/EntitlementLimitsTest.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php tests/Feature/RecipeVersionPagesTest.php
```

- [ ] **Step 3: Add the plan limit**

In `PlanSeeder`, add:

```php
'saved_formula_history' => 0,
```

Update the plan-limit factory's allowed key list. Existing paid plans must receive an explicit reviewed value before launch; tests use `Plan::factory()->hasLimit('saved_formula_history', 3)`.

- [ ] **Step 4: Expose a retention count through entitlements**

Add:

```php
public function savedFormulaHistoryLimitFor(User $user): int
```

Resolve the workspace owner exactly as other plan limits do. Return `max(0, (int) ($limits['saved_formula_history'] ?? 0))`. This returns earlier-history count, not total published rows.

- [ ] **Step 5: Prune according to the active plan**

Pass the acting user into `RecipeVersionPublisher::pruneHiddenRecoverySnapshots()`. Keep:

```php
$publishedRowsToKeep = 1 + $this->entitlementService->savedFormulaHistoryLimitFor($user);
```

Delete all older non-current rows through the history-aware deletion path from Task 4. Remove the hard-coded `MAX_HIDDEN_RECOVERY_SNAPSHOTS` constant.

- [ ] **Step 6: Run tests and commit**

```bash
php artisan test --compact tests/Feature/EntitlementLimitsTest.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php tests/Feature/RecipeVersionPagesTest.php
git add app/Services/EntitlementService.php app/Services/RecipeVersionPublisher.php database/seeders/PlanSeeder.php database/factories/PlanLimitFactory.php tests/Feature/EntitlementLimitsTest.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php tests/Feature/RecipeVersionPagesTest.php
git commit -m "feat: limit saved formula history by plan"
```

### Task 6: Verify the complete snapshot lifecycle

**Files:**
- Create: `app/Console/Commands/PruneOrphanedRecipeMedia.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/Console/PruneOrphanedRecipeMediaTest.php`

- [ ] **Step 1: Add safe cleanup for abandoned recipe uploads**

Generate the command and test:

```bash
php artisan make:command PruneOrphanedRecipeMedia --no-interaction
php artisan make:test --pest Console/PruneOrphanedRecipeMediaTest --no-interaction
```

The command signature is:

```php
protected $signature = 'media:prune-orphaned-recipe {--age=24 : Minimum object age in hours}';
```

List only `recipes/*/featured-images/*` and `recipes/*/rich-content/*` on `MediaStorage::recipeDisk()`. Reject an `--age` below 1. For each object older than the cutoff, resolve the recipe by the public ID in its path and preserve it when `RecipeMediaReferenceService::allReferencedPaths()` contains the path. Delete only old, unreferenced objects. A missing recipe makes an old object eligible; a recent object always survives. Log counts without logging user-authored filenames or URLs.

The Pest test uses a fake recipe disk and frozen time to prove referenced, unreferenced-old, unreferenced-recent, and missing-recipe behavior. It also proves the command never scans or deletes ingredient and packaging namespaces.

Schedule it in `routes/console.php`:

```php
Schedule::command('media:prune-orphaned-recipe --age=24')
    ->dailyAt('03:30')
    ->withoutOverlapping();
```

Run:

```bash
php artisan test --compact tests/Feature/Console/PruneOrphanedRecipeMediaTest.php
```

Expected: pass.

- [ ] **Step 2: Run focused regression tests**

```bash
php artisan test --compact tests/Feature/RecipeSopSnapshotSchemaTest.php tests/Unit/RecipeMediaReferenceServiceTest.php tests/Feature/Console/PruneOrphanedRecipeMediaTest.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeVersionPagesTest.php tests/Feature/RecipeContentMediaContractTest.php tests/Feature/MediaStorageTest.php tests/Feature/EntitlementLimitsTest.php
vendor/bin/pint --dirty --format agent
graphify update .
```

Expected: all tests pass, formatting is clean, and graph generation succeeds.

- [ ] **Step 3: Perform an end-to-end smoke test**

With a paid test account:

1. save SOP A with image A;
2. save/publish the formula;
3. change to SOP B with image B and save/publish again;
4. open both saved Formula Sheets and confirm each shows its own SOP;
5. remove image A from current content and confirm the older sheet still renders it;
6. delete the older saved snapshot and confirm image A is removed only if no other reference remains.

With a free test account, publish repeatedly and confirm only the latest saved snapshot plus current draft remain.

- [ ] **Step 4: Commit cleanup and final corrections**

```bash
git add app database routes tests
git commit -m "feat: prune abandoned recipe media"
```

Skip the commit when no tracked changes remain.
