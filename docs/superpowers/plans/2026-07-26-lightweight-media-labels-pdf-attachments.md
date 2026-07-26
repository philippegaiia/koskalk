# Lightweight Media Labels and PDF Attachments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add optional workspace labels and secure PDF documents to the existing media library, while keeping the default image workflow visually quiet and preserving workspace authorization, plan limits, translations, and queued processing.

**Architecture:** Extend `MediaAsset` with an explicit type and retain Spatie Media Library as the durable storage layer. Images continue using the `master` WebP collection; PDFs retain an authenticated original in `document` and optionally receive a first-page WebP `master` preview through a small Poppler-backed service. Labels are workspace-owned records attached through a constrained pivot and managed by one domain service. Existing media pickers remain image-only by default and opt into PDF documents for ingredient and SOP attachments.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, Spatie Media Library, Pest 4, Tailwind CSS 4, Symfony Process, PostgreSQL.

---

## Task 1: Persist media types and workspace labels

- [ ] Add failing persistence tests to `tests/Feature/MediaAssetFoundationTest.php` covering:
  - `MediaAssetType::Image` as the default;
  - the `pdf` factory state;
  - workspace-scoped label uniqueness;
  - the many-to-many asset/label relationship;
  - cascading pivot cleanup.
- [ ] Generate migrations with `php artisan make:migration --no-interaction`:
  - add `type` to `media_assets`, indexed with `workspace_id`;
  - create `media_labels` with `workspace_id`, `created_by_user_id`, `name`, `normalized_name`, timestamps, and a unique `(workspace_id, normalized_name)` index;
  - create `media_asset_label` with cascading foreign keys and a unique pair.
- [ ] Add `app/MediaAssetType.php` with `Image` and `Pdf` string-backed cases.
- [ ] Add `app/Models/MediaLabel.php` and `database/factories/MediaLabelFactory.php`.
- [ ] Update `MediaAsset`, `MediaAssetFactory`, and workspace/user relationships using explicit return types and eager-loadable relations.
- [ ] Run:
  - `php artisan test --compact tests/Feature/MediaAssetFoundationTest.php`
  - `vendor/bin/pint --dirty --format agent`
- [ ] Commit: `feat: add media types and workspace labels`

## Task 2: Enforce label limits and authorization

- [ ] Add failing tests to `tests/Feature/MediaLibraryTest.php` for:
  - editors creating labels;
  - viewers being unable to mutate labels;
  - normalization and case-insensitive duplicate prevention;
  - 30-character names;
  - 20-label workspace plan limit;
  - 8-label per-asset limit;
  - cross-workspace assignment rejection;
  - rename/delete behavior.
- [ ] Add `media_labels` to `PlanSeeder`, `PlanForm`, and entitlement usage calculation with a default workspace limit of 20.
- [ ] Add `app/Policies/MediaLabelPolicy.php`.
- [ ] Add `app/Services/MediaLabelService.php` as the only label mutation boundary:
  - normalize with `Str::squish()` and lowercase;
  - create inside the existing workspace quota lock;
  - rate-limit creation to 10 attempts per user/workspace/minute;
  - sync no more than 8 labels;
  - authorize and assert workspace ownership;
  - rename and delete transactionally.
- [ ] Register/discover the policy according to existing conventions.
- [ ] Run:
  - `php artisan test --compact tests/Feature/MediaLibraryTest.php`
  - `vendor/bin/filacheck --fix`
  - `vendor/bin/pint --dirty --format agent`
- [ ] Commit: `feat: enforce media label limits`

## Task 3: Accept and classify PDF uploads securely

- [ ] Add failing upload tests to `tests/Feature/MediaAssetProcessingTest.php` covering:
  - a valid `%PDF-` file is classified as `Pdf`;
  - PDF extension, detected MIME, and signature must agree;
  - image-only callers reject PDFs;
  - the 10 MB per-file limit applies equally to images and PDFs.
- [ ] Refactor `MediaAssetUploadService::start()` to accept an allowed-type list that defaults to images.
- [ ] Keep extension, content MIME, signature, and size validation explicit; never infer PDF only from the filename.
- [ ] Update `config/media.php` with separate image/document extensions, PDF page limit 50, and Poppler binary configuration.
- [ ] Ensure the queued asset stores the detected `MediaAssetType`.
- [ ] Run:
  - `php artisan test --compact tests/Feature/MediaAssetProcessingTest.php`
  - `vendor/bin/pint --dirty --format agent`
- [ ] Commit: `feat: validate pdf media uploads`

## Task 4: Process PDFs and provide authenticated downloads

- [ ] Add failing processing/controller tests for:
  - the original PDF stored on `MEDIA_ASSET_DISK` in `document`;
  - first-page preview stored as WebP in `master`;
  - page counts above 50 fail with a translated reason;
  - missing Poppler still produces a ready PDF with no preview;
  - workspace members can download;
  - outsiders receive 404/403 according to existing media conventions;
  - responses are attachments with `X-Content-Type-Options: nosniff`.
- [ ] Add `app/Services/PdfPreviewRenderer.php` using `Symfony\Component\Process\Process`:
  - inspect pages using configured `pdfinfo`;
  - render only page one using configured `pdftoppm`;
  - cap execution time;
  - return `null` when binaries are unavailable;
  - throw `MediaAssetProcessingException` for malformed or over-limit PDFs.
- [ ] Split `MediaAssetProcessingService` into explicit image/PDF paths without changing existing image behavior.
- [ ] Add a `document` media collection to `MediaAsset`; keep preview conversions on `master`.
- [ ] Add `MediaAssetDownloadController` and a named route before the conversion wildcard.
- [ ] Update rename/removal services so both collections remain consistent.
- [ ] Run:
  - `php artisan test --compact tests/Feature/MediaAssetProcessingTest.php tests/Feature/MediaLibraryTest.php`
  - `vendor/bin/pint --dirty --format agent`
- [ ] Commit: `feat: process and download pdf media`

## Task 5: Add lightweight label and PDF controls to the library

- [ ] Add failing Livewire tests for:
  - no label filter when a workspace has no labels;
  - one compact multi-select label filter when labels exist;
  - filtering by type and labels remains workspace-scoped;
  - upload labels are applied to every selected batch asset;
  - PDF cards expose download metadata and a placeholder when preview is absent.
- [ ] Update `MediaLibraryIndex` to:
  - eager-load labels;
  - expose `typeFilter`, `labelFilter`, and `uploadLabelIds`;
  - delegate mutations to `MediaLabelService`;
  - reset pagination when filters change.
- [ ] Update `resources/views/livewire/dashboard/media-library-index.blade.php`:
  - add quiet Images/PDFs type filtering;
  - render the Labels dropdown only when labels exist;
  - show at most two muted labels plus a count below ordinary card metadata;
  - display a document icon fallback for PDFs without previews;
  - keep label creation/editing in the inspector;
  - avoid overlay badges and permanent bulk controls.
- [ ] Update the sequential uploader so chosen upload labels are applied server-side without increasing concurrent processing.
- [ ] Add responsive Tailwind classes and reuse existing components/colors.
- [ ] Run:
  - `php artisan test --compact tests/Feature/MediaLibraryTest.php tests/Unit/MediaLibraryUploaderTest.php`
  - `npm run build`
- [ ] Commit: `feat: add lightweight media organization ui`

## Task 6: Make media pickers type-aware

- [ ] Add failing picker tests proving:
  - existing pickers remain image-only;
  - document pickers list only ready PDFs;
  - document pickers may upload PDFs;
  - image pickers reject PDF upload attempts;
  - cross-workspace access remains impossible.
- [ ] Extend `MediaAssetPicker` with an explicit accepted-types configuration and a document convenience method.
- [ ] Include accepted types in the picker upload mutation payload and pass them to `MediaAssetUploadService`.
- [ ] Update `MediaAssetPickerMutationController` to validate/filter types and serialize document placeholder/download fields.
- [ ] Update the picker Blade/JS only where required, keeping current image UX unchanged.
- [ ] Run:
  - `php artisan test --compact tests/Feature/MediaAssetConsumerIntegrationTest.php tests/Unit/MediaAssetPickerAccessibilityContractTest.php`
  - `npm run build`
- [ ] Commit: `feat: support document media pickers`

## Task 7: Attach PDFs to ingredients and recipe SOPs

- [ ] Add `IngredientDocument` and `RecipeSopDocument` cases to `MediaAssetUsageRole`.
- [ ] Add failing consumer tests for:
  - up to 8 PDFs attached to an ingredient;
  - up to 8 PDFs attached to a recipe SOP;
  - image assets rejected for document roles;
  - PDF documents rejected for image roles;
  - existing image and inline SOP media remain unchanged.
- [ ] Extend `MediaAssetUsageService::syncMany()` with an expected media type and preserve its workspace/ready checks.
- [ ] Add a multiple PDF picker to `IngredientEditor`, hydrate it on mount, and sync `IngredientDocument`.
- [ ] Add a multiple PDF picker to `RecipeWorkbenchContentFormSchema`, propagate its state through `RecipeWorkbench`, and sync `RecipeSopDocument` at the existing save boundary.
- [ ] Render attached documents as authenticated download links wherever ingredient/SOP attachments are shown.
- [ ] Run:
  - `php artisan test --compact tests/Feature/MediaAssetConsumerIntegrationTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchContractTest.php`
  - `vendor/bin/pint --dirty --format agent`
  - `npm run build`
- [ ] Commit: `feat: attach pdf documents to ingredients and sops`

## Task 8: Localize plan-facing text and the new feature

- [ ] Add failing localization tests proving plan name, description, price label, feature labels, media label usage, and PDF/label UI copy resolve through the active locale with English fallback.
- [ ] Add `lang/en/plans.php` and register `plans` in `config/interface-translations.php`.
- [ ] Add one shared plan presentation service/view model and replace raw plan strings in account and checkout views.
- [ ] Add all English source keys for labels, PDFs, errors, download actions, type filters, and document attachments.
- [ ] Generate translations for German, Spanish, French, Italian, and Dutch while preserving placeholders exactly.
- [ ] Import the existing deterministic catalogue into the local test database, add/update the new `language_lines`, and export `database/seeders/data/interface-translations.json`.
- [ ] Verify catalogue determinism by exporting twice and confirming no diff.
- [ ] Run:
  - `php artisan test --compact tests/Feature/AccountLocalizationTest.php tests/Feature/BillingFlowTest.php tests/Feature/MediaLibraryTest.php`
  - `php artisan translations:catalogue:export --no-interaction`
  - `git diff --exit-code database/seeders/data/interface-translations.json` after the second export
- [ ] Commit: `feat: localize media labels pdfs and plans`

## Task 9: Final quality and deployment verification

- [ ] Run `vendor/bin/filacheck --fix` and resolve all remaining findings.
- [ ] Run `vendor/bin/pint --dirty --format agent`.
- [ ] Run the focused media, consumer, localization, and Filament test suites.
- [ ] Run the full suite with `php artisan test --compact`.
- [ ] Run `npm run build`.
- [ ] Run `graphify update .` from the primary checkout after integration so the repository graph includes the new code.
- [ ] Review the complete diff for:
  - workspace scoping;
  - authorization on every mutation/download;
  - MIME/signature validation;
  - plan/per-asset limits;
  - translation placeholders;
  - no raw plan-facing database copy;
  - no unrelated changes or generated secrets.
- [ ] Commit any final fixes as `fix: harden media document workflow`.
- [ ] Prepare Forge handoff noting that `poppler-utils` enables previews but is not required for PDF storage/download; the existing media queue worker remains the processing mechanism.
