# Workspace Ingredient Guidance Rich Editor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task by task. Use `superpowers:test-driven-development` for each behavior change and `testing-best-practices` before modifying tests.

**Goal:** Give ordinary workspace users a true rich-text ingredient-guidance editor while keeping platform guidance in Markdown, preserving a user's inactive workspace version, and storing all workspace-authored guidance consistently outside the platform ingredient columns.

**Architecture:** Platform guidance remains canonical Markdown in `ingredients.info_markdown` and `ingredient_translations.info_markdown`. Workspace-authored guidance, whether attached to a platform ingredient or a workspace-owned ingredient, is canonical sanitized HTML in `workspace_ingredient_guidances`. A focused content service owns Markdown-to-HTML conversion, restrictive sanitization, visible-text measurement, and plain-text extraction. `WorkspaceIngredientGuidanceService` owns tenant authorization, active/inactive selection, audit attribution, and effective HTML resolution. The Livewire editor uses Filament `RichEditor`; users never edit Markdown or see Markdown markers.

**Tech Stack:** PHP 8.5, Laravel 13.29, Livewire 4.4, Filament Forms 5.7.6, Symfony HTML Sanitizer 8.1.1, Pest, Laravel Truss.

---

## Settled product decisions

- Keep `ingredients.info_markdown`; platform enrichment, platform translations, the admin ingredient form, and the platform-only exporter still depend on it.
- Stop reading or writing `ingredients.info_markdown` for workspace-owned ingredients.
- Store one language-agnostic workspace guidance value per `(workspace_id, ingredient_id)`.
- Store workspace-authored guidance as sanitized HTML, not Markdown and not Filament JSON.
- Limit guidance to 2,000 visible Unicode characters. HTML tags do not count toward the limit.
- Allow only paragraphs, H2/H3 headings, bold, italic, ordered and unordered lists, and HTTP/HTTPS links. Include undo/redo controls. Do not allow images, attachments, tables, arbitrary font families, font sizes, colors, alignment, code, blockquotes, underline, or strike-through.
- Ingredient images remain in the existing media gallery and are not embedded in guidance.
- On first customization of a platform ingredient, convert the currently localized platform Markdown to sanitized HTML and seed the Rich Editor without persisting it.
- Saving workspace guidance creates or updates the workspace record and activates it.
- “Use platform guidance” sets the workspace record inactive; it does not delete it.
- An inactive workspace version can be edited or reactivated with “Use workspace guidance.”
- A workspace-owned ingredient uses the same Rich Editor in its main form. Empty guidance is permitted; clearing it deletes its guidance row.
- A duplicated platform ingredient receives a workspace guidance row seeded from the platform guidance in the duplicating user's current locale. The duplicate's `info_markdown` remains null.
- Owners, admins, and editors may write guidance. Viewers can only read effective guidance.
- Existing workspace guidance and workspace-owned `info_markdown` data may be discarded during this migration. Production contains only a few disposable records, so no Markdown-to-HTML data migration is required.
- Platform enrichment and localization continue to read and write only platform Markdown. They must never overwrite workspace HTML.

## Files in scope

**Create**

- `app/Services/WorkspaceIngredientGuidanceContent.php`
- `database/migrations/<timestamp>_replace_workspace_ingredient_guidance_markdown_with_html.php`
- `tests/Unit/Services/WorkspaceIngredientGuidanceContentTest.php`

**Modify**

- `app/Models/WorkspaceIngredientGuidance.php`
- `database/factories/WorkspaceIngredientGuidanceFactory.php`
- `app/Services/WorkspaceIngredientGuidanceService.php`
- `app/Services/UserIngredientAuthoringService.php`
- `app/Services/IngredientCatalogConsolidationService.php`
- `app/Livewire/Dashboard/IngredientEditor.php`
- `resources/views/livewire/dashboard/ingredient-editor.blade.php`
- `lang/en/ingredients.php`
- `database/seeders/data/interface-translations.json`
- `tests/Feature/WorkspaceIngredientGuidanceTest.php`
- `tests/Feature/UserIngredientAuthoringTest.php`
- `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`
- `tests/Feature/IngredientEditorLocalizationTest.php`
- `tests/Feature/IngredientCatalogConsolidationTest.php`
- `tests/Feature/IngredientGuidanceBatchReviewTest.php`
- `tests/Feature/PlatformIngredientDeletionTest.php`
- `tests/Feature/InterfaceTranslationCatalogueTest.php`

**Explicitly out of scope**

- `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- `app/Filament/Resources/IngredientEnrichmentBatches/**`
- `app/Services/IngredientEnrichment/**`
- `app/Filament/Exports/IngredientExporter.php`
- `ingredients.info_markdown` and `ingredient_translations.info_markdown` schema
- Rich-editor image uploads or gallery embedding
- Translation of workspace-authored guidance

The Filament exporter is excluded because `IngredientResource::getEloquentQuery()` restricts it to platform ingredients. Its Markdown export remains correct.

---

## Task 1: Establish the baseline and replace the disposable schema

**Files:**

- Create: `database/migrations/<timestamp>_replace_workspace_ingredient_guidance_markdown_with_html.php`
- Modify: `app/Models/WorkspaceIngredientGuidance.php`
- Modify: `database/factories/WorkspaceIngredientGuidanceFactory.php`
- Test: `tests/Feature/WorkspaceIngredientGuidanceTest.php`

- [ ] Confirm that the three existing uncommitted Markdown-editor files are the known interim implementation. Preserve unrelated work, but replace these edits during later tasks rather than committing them as a separate feature.

```bash
git status --short
git diff -- app/Livewire/Dashboard/IngredientEditor.php resources/views/livewire/dashboard/ingredient-editor.blade.php tests/Feature/UserIngredientAuthoringTest.php
```

- [ ] Run the current focused baseline before changing the schema.

```bash
php artisan test --compact tests/Feature/WorkspaceIngredientGuidanceTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/IngredientCatalogConsolidationTest.php tests/Feature/IngredientGuidanceBatchReviewTest.php tests/Feature/PlatformIngredientDeletionTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

- [ ] Add failing schema/model tests asserting that the workspace table has `guidance_html` and `is_active`, no longer has `guidance_markdown`, defaults new records to active, retains the unique workspace/ingredient key, and keeps audit relationships.

- [ ] Generate the migration with Artisan.

```bash
php artisan make:migration replace_workspace_ingredient_guidance_markdown_with_html --table=workspace_ingredient_guidances --no-interaction
```

- [ ] Implement `up()` in this order:

  1. Delete all rows from `workspace_ingredient_guidances`.
  2. Set `ingredients.info_markdown` to null only where `workspace_id` is not null, discarding the obsolete workspace-owned values while preserving all platform Markdown.
  3. Drop `guidance_markdown`.
  4. Add non-null `guidance_html` as `text` and `is_active` as `boolean` defaulting to true.

Do not alter the existing foreign keys, unique constraint, or lookup indexes.

- [ ] Implement a real structural `down()`: delete workspace guidance rows, drop `guidance_html` and `is_active`, and restore non-null `guidance_markdown`. Document in the migration PHPDoc that discarded content cannot be reconstructed in either direction.

- [ ] Update `WorkspaceIngredientGuidance` fillable attributes to `guidance_html` and `is_active`, and cast `is_active` to boolean using the project's existing model-cast convention.

- [ ] Update the factory to emit safe paragraph HTML and `is_active => true`.

- [ ] Run the schema test, migrate the development database, and inspect the actual change.

```bash
php artisan test --compact tests/Feature/WorkspaceIngredientGuidanceTest.php
php artisan migrate --no-interaction
php artisan truss:diff
```

Expected: only the intended workspace-guidance column replacement plus cleared disposable workspace content.

---

## Task 2: Create the single HTML security and conversion boundary

**Files:**

- Create: `app/Services/WorkspaceIngredientGuidanceContent.php`
- Create: `tests/Unit/Services/WorkspaceIngredientGuidanceContentTest.php`

- [ ] Generate the service and Pest unit test.

```bash
php artisan make:class Services/WorkspaceIngredientGuidanceContent --no-interaction
php artisan make:test --pest --unit Services/WorkspaceIngredientGuidanceContentTest --no-interaction
```

- [ ] Write failing tests for:

  - conversion of `##`/`###`, emphasis, lists, and safe links from platform Markdown;
  - stripping script, image, table, style, class, event-handler, and unsupported formatting markup;
  - retaining only `p`, `h2`, `h3`, `strong`, `em`, `ul`, `ol`, `li`, `a`, and `br`;
  - allowing only HTTP/HTTPS links and dropping `javascript:`, `data:`, mail, telephone, and unsafe relative targets;
  - stable, safe canonical HTML output;
  - visible-text extraction and Unicode-aware length counting;
  - treating markup-only/empty editor content as blank.

- [ ] Implement the service with these public methods:

```php
public function fromPlatformMarkdown(?string $markdown): ?string;
public function sanitize(?string $html): ?string;
public function text(?string $html): string;
public function length(?string $html): int;
```

Use `Str::markdown()` with `html_input => strip` and `allow_unsafe_links => false` for the one-way Markdown conversion. Then pass all content through a dedicated Symfony `HtmlSanitizer` configured with the exact element/attribute allow-list above and `allowLinkSchemes(['http', 'https'])`. Do not rely only on the Rich Editor toolbar or Filament's broader default sanitizer; Livewire state is untrusted and can be tampered with.

Use Filament's TipTap PHP renderer/editor to derive text consistently with `RichEditor::maxLength()`, then use `Str::length()` for the 2,000-character server-side check.

- [ ] Run the unit test after each behavior is implemented.

```bash
php artisan test --compact tests/Unit/Services/WorkspaceIngredientGuidanceContentTest.php
```

---

## Task 3: Make the guidance service own both platform customizations and workspace-owned guidance

**Files:**

- Modify: `app/Services/WorkspaceIngredientGuidanceService.php`
- Modify: `tests/Feature/WorkspaceIngredientGuidanceTest.php`

- [ ] Rewrite the service tests first around the new vocabulary and behaviors:

  - effective platform HTML is localized Markdown converted to safe HTML when no active workspace record exists;
  - active workspace HTML wins in every application locale;
  - inactive workspace HTML is retained but effective output falls back live to current localized platform Markdown;
  - first edit seed comes from the current localized platform Markdown;
  - editing an inactive record loads its retained HTML, not a fresh platform copy;
  - save sanitizes, validates nonblank visible text, enforces 2,000 visible characters, updates audit users, and sets active;
  - `usePlatform()` sets inactive without deleting;
  - `useWorkspace()` reactivates the retained row;
  - workspace-owned ingredients in the same workspace can save and clear guidance;
  - platform ingredients and same-workspace private ingredients are accepted, but inactive platform ingredients, private ingredients from another workspace, nonmembers, and viewers are rejected;
  - two workspaces remain isolated;
  - deleting a workspace or ingredient still cascades guidance rows.

- [ ] Replace ambiguous Markdown-oriented methods with an HTML-oriented API:

```php
public function recordFor(Workspace $workspace, Ingredient $ingredient): ?WorkspaceIngredientGuidance;
public function effectiveHtml(Workspace $workspace, Ingredient $ingredient, ?string $locale = null): ?string;
public function editableHtml(Workspace $workspace, Ingredient $ingredient, ?string $locale = null): ?string;
public function save(User $actor, Workspace $workspace, Ingredient $ingredient, ?string $html): WorkspaceIngredientGuidance;
public function clearWorkspaceOwned(User $actor, Workspace $workspace, Ingredient $ingredient): void;
public function usePlatform(User $actor, Workspace $workspace, Ingredient $ingredient): void;
public function useWorkspace(User $actor, Workspace $workspace, Ingredient $ingredient): WorkspaceIngredientGuidance;
```

- [ ] Keep `MAX_LENGTH = 2000`. Sanitize first, validate the sanitized visible text second, and persist only canonical sanitized HTML. Never store the raw submitted HTML.

- [ ] Keep row mutation inside `DB::transaction(..., attempts: 5)` with `lockForUpdate()`. Preserve `created_by_user_id`, update `updated_by_user_id` on every change, and activate on save.

- [ ] Separate authorization rules clearly:

  - platform customization requires an active platform ingredient;
  - workspace-owned guidance requires the ingredient's workspace/owner to match the selected workspace and the ingredient to be accessible;
  - `usePlatform()` and `useWorkspace()` are platform-only operations;
  - `clearWorkspaceOwned()` is workspace-owned-only;
  - all writes require Owner/Admin/Editor.

- [ ] Run the focused service suite.

```bash
php artisan test --compact tests/Feature/WorkspaceIngredientGuidanceTest.php tests/Feature/PlatformIngredientDeletionTest.php
```

---

## Task 4: Remove workspace guidance from the ingredient row and preserve duplication behavior

**Files:**

- Modify: `app/Services/UserIngredientAuthoringService.php`
- Modify: `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`
- Modify: `tests/Feature/IngredientEditorLocalizationTest.php`

- [ ] Update tests first so ordinary create/update state no longer contains `info_markdown`, and workspace ingredient saves never write that column.

- [ ] Update duplication tests to assert:

  - the copied ingredient has `info_markdown === null`;
  - a `workspace_ingredient_guidances` row is created for the duplicate;
  - its HTML is seeded from the source's platform Markdown in the duplicating user's current locale;
  - the row is active and correctly attributed to the user;
  - source platform guidance remains unchanged.

- [ ] Remove `info_markdown` from `blankState()`, `formData()`, `createInlineComponent()`, and `fillIngredient()` in `UserIngredientAuthoringService`.

- [ ] Inject `WorkspaceIngredientGuidanceService` into `UserIngredientAuthoringService`. During `duplicate()`, leave `$copy->info_markdown` null and save the localized source guidance through the workspace service after the copied ingredient exists, within the existing quota/transaction boundary.

- [ ] Replace the old localization assertion `data.info_markdown` with assertions against the platform guidance card's effective localized HTML and the Rich Editor's seeded HTML.

- [ ] Run the focused authoring tests.

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientEditorLocalizationTest.php
```

---

## Task 5: Replace both user-facing text inputs with the restricted Rich Editor

**Files:**

- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `resources/views/livewire/dashboard/ingredient-editor.blade.php`
- Modify: `tests/Feature/UserIngredientAuthoringTest.php`

- [ ] Replace the interim MarkdownEditor assertions with failing RichEditor behavior tests for platform ingredients:

  - first customization shows converted localized HTML with no Markdown markers;
  - the editor exposes only paragraph, H2, H3, bold, italic, link, bullet list, ordered list, undo, and redo controls;
  - link protocols are `http` and `https`;
  - visible-text max length is 2,000;
  - saving displays formatted sanitized HTML;
  - dangerous/unsupported submitted HTML is removed server-side;
  - “Use platform guidance” preserves the row as inactive;
  - inactive state shows platform content plus “Use workspace guidance” and an edit action for the retained workspace content;
  - reactivation restores the exact retained workspace HTML;
  - viewers see the effective formatted content but no mutation controls.

- [ ] Add failing tests for new and existing workspace-owned ingredients:

  - the main form contains the same restricted RichEditor;
  - saving persists guidance in `workspace_ingredient_guidances`, not `ingredients.info_markdown`;
  - reopening loads stored HTML;
  - editing updates the same row;
  - clearing optional guidance deletes that workspace-owned row;
  - the ingredient and guidance write roll back together if either validation or authorization fails.

- [ ] In `IngredientEditor`, use `RichEditor`, not `MarkdownEditor` or `Textarea`, for both surfaces. Configure both through one private component-builder method so the toolbar, protocols, character limit, minimum height, and helper text cannot drift.

```php
->toolbarButtons([
    ['bold', 'italic', 'link'],
    ['paragraph', 'h2', 'h3'],
    ['bulletList', 'orderedList'],
    ['undo', 'redo'],
])
->linkProtocols(['http', 'https'])
->maxLength(WorkspaceIngredientGuidanceService::MAX_LENGTH)
```

Do not configure file attachments or image tools.

- [ ] Rename Livewire state from `workspaceGuidance.markdown` to `workspaceGuidance.html`. For the main workspace-owned ingredient form, use `data.guidance_html`; unset it before calling `UserIngredientAuthoringService`.

- [ ] In the existing outer ingredient save transaction, create/update the ingredient first, then save or clear its guidance through `WorkspaceIngredientGuidanceService`, then synchronize codes and media. This keeps all user-visible ingredient changes atomic.

- [ ] Replace `resetWorkspaceGuidance()` with explicit `usePlatformGuidance()` and `useWorkspaceGuidance()` actions. Editing an inactive record must use `editableHtml()` so the user's retained version is never overwritten by a platform seed.

- [ ] Render effective content as already-sanitized HTML returned by `effectiveHtml()`. Do not run workspace HTML through `Str::markdown()`. Keep the `prose` wrapper for heading/list presentation.

- [ ] Keep the guidance card inside the existing platform-ingredient reference section. Workspace-owned ingredients edit guidance inside their main Documents tab, using the same format and rules.

- [ ] Run the Livewire suite after every red/green slice.

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientEditorLocalizationTest.php
```

---

## Task 6: Preserve guidance through catalogue operations and platform enrichment

**Files:**

- Modify: `app/Services/IngredientCatalogConsolidationService.php`
- Modify: `tests/Feature/IngredientCatalogConsolidationTest.php`
- Modify: `tests/Feature/IngredientGuidanceBatchReviewTest.php`

- [ ] Update catalogue-consolidation fixtures from `guidance_markdown` to `guidance_html` and add `is_active` to the comparison contract.

- [ ] Preserve the existing four merge cases per workspace:

  - source-only guidance moves to the target;
  - target-only guidance remains;
  - equal HTML and equal active status deduplicate;
  - differing HTML or differing active status raises a conflict and rolls back.

- [ ] Update the enrichment-isolation regression to assert that applying new platform Markdown does not change either `guidance_html` or `is_active` on the workspace record.

- [ ] Run both suites.

```bash
php artisan test --compact tests/Feature/IngredientCatalogConsolidationTest.php tests/Feature/IngredientGuidanceBatchReviewTest.php
```

---

## Task 7: Update user-facing copy and translation catalogue ownership

**Files:**

- Modify: `lang/en/ingredients.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php`

- [ ] Replace “override” and destructive “reset/remove” wording with the actual model:

  - Platform guidance
  - Workspace guidance
  - Customize guidance
  - Edit workspace guidance
  - Use platform guidance
  - Use workspace guidance
  - Workspace guidance retained / restored notifications

- [ ] Make the confirmation for “Use platform guidance” explicit that the workspace version is kept and can be restored later.

- [ ] Replace Markdown-specific helper/validation text. Explain that formatting controls are available and the 2,000-character limit counts written content.

- [ ] Remove obsolete keys such as the raw-Markdown HTML rejection message and manual character-count copy if no longer rendered. Add keys for inactive state and reactivation.

- [ ] Update the exact owned-key list in `InterfaceTranslationCatalogueTest`, then add all required locale values to `interface-translations.json` following the existing catalogue structure. Do not translate workspace-authored guidance itself.

- [ ] Run the translation catalogue test.

```bash
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php
```

---

## Task 8: Final verification and handoff

- [ ] Run the complete affected set together.

```bash
php artisan test --compact tests/Unit/Services/WorkspaceIngredientGuidanceContentTest.php tests/Feature/WorkspaceIngredientGuidanceTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/IngredientCatalogConsolidationTest.php tests/Feature/IngredientGuidanceBatchReviewTest.php tests/Feature/PlatformIngredientDeletionTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

- [ ] Format changed PHP and check the diff for accidental platform-guidance changes.

```bash
vendor/bin/pint --dirty --format agent
git diff --check
git diff --stat
git diff -- app/Services/IngredientEnrichment app/Filament/Resources/Ingredients/Schemas/IngredientForm.php app/Filament/Exports/IngredientExporter.php
```

Expected: the final command is empty; platform enrichment/admin/export code is untouched.

- [ ] Refresh the local knowledge graph after code changes.

```bash
graphify update .
```

- [ ] Re-run the affected set after formatting, then ask the user to run the complete suite.

```bash
php artisan test --compact tests/Unit/Services/WorkspaceIngredientGuidanceContentTest.php tests/Feature/WorkspaceIngredientGuidanceTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/IngredientCatalogConsolidationTest.php tests/Feature/IngredientGuidanceBatchReviewTest.php tests/Feature/PlatformIngredientDeletionTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

- [ ] Manual acceptance checks in one workspace:

  1. Open a platform ingredient in French and customize it; the editor shows formatted French without `##` markers.
  2. Save headings, lists, emphasis, and an HTTPS link; reload and confirm formatting persists.
  3. Choose platform guidance, confirm the current localized platform text appears, then restore workspace guidance and confirm the saved version returns unchanged.
  4. Change the application locale and confirm the workspace version remains exactly as authored while platform fallback follows the active locale.
  5. Create and reopen a workspace-owned ingredient; confirm the same Rich Editor experience and that clearing guidance works.
  6. Confirm no image-upload or table controls appear in either editor.

Do not commit or merge until the user has reviewed the diff and the complete suite result. The pre-existing three dirty interim Markdown-editor files are part of this feature and should be committed only once they have been replaced by the final RichEditor implementation.
