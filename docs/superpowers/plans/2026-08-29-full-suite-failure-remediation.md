# Full-Suite Failure Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore a meaningful green contract for the eight reported Pest failures by aligning stale fixtures and contract tests with intentional recent behavior, changing production source only where the current implementation violates the stated UI contract.

**Architecture:** Start with a reproducible, commit-attributed baseline. Resolve the four failures introduced by the ingredient-enrichment refresh/schema work as enrichment test-contract updates, then update the media and workbench tests that still describe pre-refactor behavior; keep the workspace ingredient-guidance override implementation out of scope. The direct rich-editor upload rejection and Filament inventory action remain the current product behavior.

**Tech Stack:** PHP 8.5, Laravel, Pest, Livewire, Filament Actions, Blade, Tailwind CSS, Vite.

---

## Findings and scope

The pasted run reports eight failures: one trust-dimension assertion, one media-upload assertion, one focus-indicator assertion, one combobox assertion, three data-set cases for intake research, and one workflow-action assertion.

| Failure | Root cause | Relation to the workspace guidance override merge (`763a6034`) | Planned disposition |
| --- | --- | --- | --- |
| `IngredientEnrichmentTrustDimensionsTest` | `trustResult()` hard-codes schema `3`; current config is schema `4`, so current-only provenance semantics are not applied to the fixture. | None; the validator/config change is from `56726688`, before the override merge. | Make the fixture use the configured current schema and keep the validator's explicit legacy-schema compatibility. | 
| `StartIngredientIntakeResearchTest` (1/10/70) | `IngredientEnrichmentBatch::$mode` is now cast to `IngredientEnrichmentBatchMode`; the test still compares it to a string. | None; the enum cast is from `56726688`. | Assert `IngredientEnrichmentBatchMode::Intake`. |
| `MediaStorageTest` rich-content upload | `RecipeRichContentAttachmentProvider` intentionally rejects direct rich-editor uploads since `b603f798`; `RecipeContentMediaContractTest` already covers the new contract. | None. | Replace the obsolete storage expectation with a rejection/no-write expectation. |
| `RecipeWorkbenchDesignPolishTest` focus indicator | The navigation CSS added by `f2c7d38b` uses a 2px outline while the established “slim” contract requires 1px. | Not the guidance merge; this is the later production-navigation work. | Change the navigation rule to the established 1px outline and retain the contract test. |
| `SearchComboboxAdoptionTest` | The opening animation fix in `e37901f4` replaced `isFormulaSettingsOpen` overflow with the delayed `formulaSettingsOverflow` state. | None. | Update the static contract to the intentional animation state and verify its source state exists. |
| `WorkflowActionConsistencyTest` | Inventory stock entry moved from an inline Blade form to the Filament `addStockAction` in `cf8efead`; the old `sk-btn` literal no longer exists in that view. | None. | Assert the action slot and absence of the old rounded markup; rely on `ProductionBenchInventoryModalTest` for Filament action existence and styling. |

No workspace-guidance files are implicated by these failures. Do not revert or alter the workspace override merge while implementing this plan.

### Task 1: Reproduce and freeze attribution

**Files:**
- Read-only: `tests/Feature/IngredientEnrichmentTrustDimensionsTest.php`
- Read-only: `tests/Feature/StartIngredientIntakeResearchTest.php`
- Read-only: `tests/Feature/MediaStorageTest.php`
- Read-only: `tests/Feature/RecipeWorkbenchDesignPolishTest.php`
- Read-only: `tests/Feature/SearchComboboxAdoptionTest.php`
- Read-only: `tests/Feature/WorkflowActionConsistencyTest.php`

- [ ] **Step 1: Re-run only the reported failure set**

  Run:

  ```bash
  php artisan test --compact \
    tests/Feature/IngredientEnrichmentTrustDimensionsTest.php \
    tests/Feature/StartIngredientIntakeResearchTest.php \
    tests/Feature/MediaStorageTest.php \
    tests/Feature/RecipeWorkbenchDesignPolishTest.php \
    tests/Feature/SearchComboboxAdoptionTest.php \
    tests/Feature/WorkflowActionConsistencyTest.php
  ```

  Expected before remediation: the same eight failures, including the three `StartIngredientIntakeResearchTest` data-set cases.

- [ ] **Step 2: Verify the commit boundaries before changing code**

  Run:

  ```bash
  git show -s --format='%h %ad %s' --date=iso-strict \
    56726688 763a6034 e5387850 f2c7d38b e37901f4 b603f798 cf8efead
  git diff --name-only 763a6034..HEAD -- \
    tests/Feature/IngredientEnrichmentTrustDimensionsTest.php \
    tests/Feature/StartIngredientIntakeResearchTest.php \
    tests/Feature/MediaStorageTest.php \
    tests/Feature/RecipeWorkbenchDesignPolishTest.php \
    tests/Feature/SearchComboboxAdoptionTest.php \
    tests/Feature/WorkflowActionConsistencyTest.php
  git status --short --branch
  ```

  Expected evidence: `56726688` owns the schema/enum behavior, `f2c7d38b` owns the 2px navigation rule, the other stale contracts predate the override merge, and the working tree contains no unrelated changes.

### Task 2: Align the trust fixture with the current enrichment schema

**Files:**
- Modify: `tests/Feature/IngredientEnrichmentTrustDimensionsTest.php:131`
- Test: `tests/Feature/IngredientEnrichmentTrustDimensionsTest.php`

- [ ] **Step 1: Keep the existing unresolved-identity test as the red regression**

  The failing assertion at line 65 is already the minimal reproduction: it supplies an unresolved `proposal.inci_name` plus an AI-proposed soap salt and expects `valid` to be false. Do not weaken that assertion.

- [ ] **Step 2: Stop hard-coding the retired schema version in `trustResult()`**

  Replace the fixture field:

  ```php
  'schema_version' => 3,
  ```

  with:

  ```php
  'schema_version' => (int) config('ingredient-enrichment.schema_version'),
  ```

  This makes all three trust tests exercise the current-schema provenance rules while preserving the validator's explicit support for the prior two schema versions.

- [ ] **Step 3: Run the focused trust tests**

  Run:

  ```bash
  php artisan test --compact tests/Feature/IngredientEnrichmentTrustDimensionsTest.php
  ```

  Expected: all trust-dimension tests pass, including the unresolved-identity rejection and the incomplete-intake acceptance.

- [ ] **Step 4: Commit the isolated fixture correction**

  ```bash
  git add tests/Feature/IngredientEnrichmentTrustDimensionsTest.php
  git commit -m "test: use current schema in enrichment trust fixtures"
  ```

### Task 3: Assert the typed intake batch mode

**Files:**
- Modify: `tests/Feature/StartIngredientIntakeResearchTest.php:4,44`
- Test: `tests/Feature/StartIngredientIntakeResearchTest.php`

- [ ] **Step 1: Keep the three parameterized cases as the red regression**

  The existing `[1, 10, 70]` data set proves the queue workflow at each supported size; only the mode type assertion is stale.

- [ ] **Step 2: Import the enum and compare the model cast to its enum case**

  Add the import:

  ```php
  use App\Enums\IngredientEnrichmentBatchMode;
  ```

  Replace:

  ```php
  ->and($enrichment->mode)->toBe('intake')
  ```

  with:

  ```php
  ->and($enrichment->mode)->toBe(IngredientEnrichmentBatchMode::Intake)
  ```

  Keep the model cast in `app/Models/IngredientEnrichmentBatch.php`; downstream guidance-mode code relies on enum methods such as `isGuidance()`.

- [ ] **Step 3: Run all parameterized intake cases**

  Run:

  ```bash
  php artisan test --compact tests/Feature/StartIngredientIntakeResearchTest.php --filter="accepts one ten and seventy"
  ```

  Expected: 3 passing cases for counts 1, 10, and 70.

- [ ] **Step 4: Commit the typed assertion**

  ```bash
  git add tests/Feature/StartIngredientIntakeResearchTest.php
  git commit -m "test: assert typed intake enrichment mode"
  ```

### Task 4: Update the rich-content media contract test

**Files:**
- Modify: `tests/Feature/MediaStorageTest.php:11,125-151`
- Read-only reference: `app/Services/RecipeRichContentAttachmentProvider.php:129-142`
- Read-only reference: `tests/Feature/RecipeContentMediaContractTest.php:197-220`
- Test: `tests/Feature/MediaStorageTest.php`

- [ ] **Step 1: Preserve the existing image-conversion coverage**

  Leave the featured-image, ingredient-icon, catalog-image, and other `MediaStorage` conversion tests unchanged. They test storage behavior that is still supported.

- [ ] **Step 2: Replace the obsolete direct-upload success test**

  Import `Illuminate\Validation\ValidationException` and replace the `stores rich content images as bounded webp attachments without cropping` test with:

  ```php
  it('rejects direct rich-content uploads because descriptions are text-only', function (): void {
      Storage::fake('local');

      config(['media.recipe_disk' => 'local']);

      $recipe = Recipe::factory()->create();
      $provider = app(RecipeRichContentAttachmentProvider::class)
          ->attribute($recipe->getRichContentAttribute('description'));

      expect(fn () => $provider->saveUploadedFileAttachment(
          UploadedFile::fake()->image('inline.jpg', 1200, 600),
      ))
          ->toThrow(ValidationException::class, __('media_library.validation.description_text_only'))
          ->and(Storage::disk('local')->allFiles())->toBeEmpty();
  });
  ```

  Do not re-enable direct uploads; the product contract now requires Media Library assets for rich content.

- [ ] **Step 3: Run the media tests**

  Run:

  ```bash
  php artisan test --compact tests/Feature/MediaStorageTest.php tests/Feature/RecipeContentMediaContractTest.php
  ```

  Expected: both the updated rejection assertion and the existing two-attribute media contract pass, with no file written to the fake disk.

- [ ] **Step 4: Commit the media contract correction**

  ```bash
  git add tests/Feature/MediaStorageTest.php
  git commit -m "test: align rich content media upload contract"
  ```

### Task 5: Restore the slim navigation focus rule

**Files:**
- Modify: `resources/css/app.css:486-489`
- Test: `tests/Feature/RecipeWorkbenchDesignPolishTest.php:870-884`

- [ ] **Step 1: Keep the existing 1px contract test as the red regression**

  The test intentionally rejects any 2px accent outline in application CSS and separately verifies the shared and Filament focus treatments. Do not broaden or delete it.

- [ ] **Step 2: Make the navigation rule match the established focus thickness**

  Change the navigation rule from:

  ```css
  .sk-nav-item:focus-visible {
      outline: 2px solid var(--color-accent);
      outline-offset: 2px;
  }
  ```

  to:

  ```css
  .sk-nav-item:focus-visible {
      outline: 1px solid var(--color-accent);
      outline-offset: 2px;
  }
  ```

  This keeps the new navigation hierarchy and focus offset while honoring the existing application-wide slim indicator contract.

- [ ] **Step 3: Run the design-polish test**

  Run:

  ```bash
  php artisan test --compact tests/Feature/RecipeWorkbenchDesignPolishTest.php --filter="slim application focus indicators"
  ```

  Expected: the focus-indicator test passes.

- [ ] **Step 4: Commit the CSS correction**

  ```bash
  git add resources/css/app.css
  git commit -m "fix: keep production navigation focus indicators slim"
  ```

### Task 6: Update the formula-settings overflow contract

**Files:**
- Modify: `tests/Feature/SearchComboboxAdoptionTest.php:31-43`
- Read-only reference: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php:51-53`
- Read-only reference: `resources/js/recipe-workbench/component.js:207-208,366-384`
- Test: `tests/Feature/SearchComboboxAdoptionTest.php`

- [ ] **Step 1: Keep the existing clipping test as the red regression**

  The test still covers the product-category combobox layout, shared focus ring, and four-column responsive grid. Only the overflow state name is obsolete.

- [ ] **Step 2: Assert the delayed overflow state introduced by the animation fix**

  Add the component source alongside the existing reads:

  ```php
  $component = file_get_contents(resource_path('js/recipe-workbench/component.js'));
  ```

  Replace:

  ```php
  ->toContain("isFormulaSettingsOpen ? 'overflow-visible' : 'overflow-hidden'")
  ```

  with:

  ```php
  ->toContain("formulaSettingsOverflow ? 'overflow-visible' : 'overflow-hidden'")
  ->and($component)
  ->toContain('formulaSettingsOverflow: initialDraft === null')
  ->toContain('this.formulaSettingsOverflowTimer = setTimeout')
  ```

  This tests the actual clipping contract and guards the delayed state that prevents the opening animation from clipping the combobox.

- [ ] **Step 3: Run the search-combobox tests**

  Run:

  ```bash
  php artisan test --compact tests/Feature/SearchComboboxAdoptionTest.php
  ```

  Expected: all search-combobox adoption tests pass without changing the animation implementation.

- [ ] **Step 4: Commit the updated contract**

  ```bash
  git add tests/Feature/SearchComboboxAdoptionTest.php
  git commit -m "test: track animated formula settings overflow state"
  ```

### Task 7: Align the inventory workflow test with the Filament action boundary

**Files:**
- Modify: `tests/Feature/WorkflowActionConsistencyTest.php:38-49`
- Read-only reference: `resources/views/livewire/production-bench/inventory-index.blade.php:118-126`
- Read-only reference: `app/Livewire/ProductionBench/InventoryIndex.php:134-194`
- Read-only reference: `tests/Feature/ProductionBenchInventoryModalTest.php:42-64`
- Test: `tests/Feature/WorkflowActionConsistencyTest.php`, `tests/Feature/ProductionBenchInventoryModalTest.php`

- [ ] **Step 1: Keep the old rounded-markup rejection**

  The consistency test should continue to reject the retired inline button markup; that protects the visual migration without assuming the action is rendered as a Blade `<button>`.

- [ ] **Step 2: Replace the obsolete class-literal assertion**

  In the inventory expectation, replace:

  ```php
  ->toContain('class="sk-btn sk-btn-primary"')
  ```

  with:

  ```php
  ->toContain('{{ $this->addStockAction }}')
  ```

  Keep:

  ```php
  ->not->toContain('class="rounded-full bg-[var(--color-accent)] px-5 py-2.5')
  ```

  The action-existence and user-shell Filament color assertions remain in `ProductionBenchInventoryModalTest`; do not add a second implementation of the Filament renderer to this static contract test.

- [ ] **Step 3: Run the workflow contracts**

  Run:

  ```bash
  php artisan test --compact \
    tests/Feature/WorkflowActionConsistencyTest.php \
    tests/Feature/ProductionBenchInventoryModalTest.php
  ```

  Expected: the static consistency checks and the live Filament action tests pass.

- [ ] **Step 4: Commit the contract correction**

  ```bash
  git add tests/Feature/WorkflowActionConsistencyTest.php
  git commit -m "test: align inventory action contract with Filament"
  ```

### Task 8: Run the affected suite and final verification

**Files:**
- Read-only verification across the files above.

- [ ] **Step 1: Run all affected tests together**

  ```bash
  php artisan test --compact \
    tests/Feature/IngredientEnrichmentTrustDimensionsTest.php \
    tests/Feature/StartIngredientIntakeResearchTest.php \
    tests/Feature/MediaStorageTest.php \
    tests/Feature/RecipeWorkbenchDesignPolishTest.php \
    tests/Feature/SearchComboboxAdoptionTest.php \
    tests/Feature/WorkflowActionConsistencyTest.php \
    tests/Feature/RecipeContentMediaContractTest.php \
    tests/Feature/ProductionBenchInventoryModalTest.php
  ```

  Expected: zero failures in the affected set and all three intake data-set cases green.

- [ ] **Step 2: Format changed PHP files**

  ```bash
  vendor/bin/pint --dirty --format agent
  ```

  Expected: Pint either reports no changes or formats only the updated test files; rerun the affected tests if it changed PHP.

- [ ] **Step 3: Build the frontend after the CSS contract change**

  ```bash
  npm run build
  ```

  Expected: Vite completes successfully and emits the updated CSS bundle.

- [ ] **Step 4: Refresh the code graph and check the diff**

  ```bash
  graphify update .
  git diff --check
  git status --short
  ```

  Expected: no whitespace errors, only the planned test/CSS changes, and refreshed graph output.

- [ ] **Step 5: Run the complete suite**

  ```bash
  php artisan test --compact
  ```

  Expected: the eight reported failures are gone. If other failures remain, classify them separately instead of expanding this plan or weakening unrelated contracts.

