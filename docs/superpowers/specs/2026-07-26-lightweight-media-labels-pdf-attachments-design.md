# Lightweight Media Labels and PDF Attachments

## Goal

Extend the workspace Media Library so users can optionally organize assets with their own labels and retain reference documents such as COAs, TDS files, and SOPs.

The feature must remain visually light for workspaces that do not use labels. Labels are secondary metadata, not a folder hierarchy or a second navigation system.

## Product Principles

- Labels are optional and user-created. Soapkraft does not seed predefined labels.
- An asset may have several labels.
- The gallery remains primarily visual and asset-focused.
- Label controls appear only where they are useful.
- Images and PDFs share one workspace library but remain type-safe in consumer pickers.
- Existing workspace authorization and private-media delivery rules continue to apply.
- Limits are enforced authoritatively on the server.

## Scope

### Included

- User-created workspace labels for images and PDFs.
- Filtering the full Media Library by one or more labels.
- Assigning labels in the asset details interface.
- Bulk assignment and removal of labels after entering an explicit selection mode.
- Optional label assignment during full-gallery batch uploads.
- PDF upload, private storage, first-page preview generation, download, and attachment.
- PDF attachments for ingredients and recipe SOPs.
- Plan-controlled workspace label allowance.
- Localized plan names, descriptions, usage labels, and plan-feature copy.
- Complete interface translations and deterministic catalogue export.

### Excluded

- Hierarchical folders or nested labels.
- Predefined label taxonomies.
- Automatic label creation from filenames, metadata, OCR, or imported content.
- Full PDF text extraction, search, OCR, annotation, or in-browser editing.
- PDFs in image-only fields such as ingredient main image, ingredient icon, recipe featured image, or packaging image.
- Arbitrary document formats beyond PDF.

## Media Library UX

### Default gallery

The existing controls remain the primary interface:

- All
- Images
- PDFs
- Used
- Unused
- Processing status
- Search

The Images and PDFs controls are asset-type filters, not user labels.

If the workspace has no labels, no label control is rendered. Users who never use labels see no additional gallery chrome.

Once at least one label exists, the toolbar shows one compact `Labels` dropdown. The dropdown:

- lists only labels created in the current workspace;
- supports searching labels;
- shows asset counts;
- permits selecting one or more labels;
- provides a restrained route to label management.

There is no permanent row of label chips.

### Asset cards

Labels never overlay the image or PDF preview.

When an asset has labels, the card may show a single small, muted metadata line beneath the normal filename and usage information. It displays at most two label names, followed by a compact remainder count when needed. Assets without labels reserve no visible label chrome.

The card treatment must be quieter than the filename, usage state, and processing state.

### Asset details

The asset details interface is the primary location for label management. It provides:

- current labels;
- a searchable add-label control;
- creation of a new label when the workspace allowance permits;
- removal of labels from the current asset;
- rename and delete actions for workspace labels;
- attachment locations;
- original PDF download for document assets.

Deleting a label requires confirmation because it removes that label from every workspace asset. It does not delete any media asset.

### Bulk actions

Bulk controls are absent during normal browsing.

After the user explicitly enters selection mode, the gallery permits:

- applying existing labels to selected assets;
- creating one label and applying it to selected assets when the allowance permits;
- removing labels from selected assets.

The eight-label-per-asset rule is validated for the complete operation before any pivot records are written. A rejected bulk action does not partially mutate the selection.

### Uploads

The full Media Library accepts images and PDFs. Existing embedded image pickers remain single-file and image-only unless the consumer explicitly supports document attachments.

During a full-gallery batch upload:

- users may optionally select existing labels to apply to every uploaded asset;
- the label field is hidden when the workspace has no labels;
- creating labels is not part of the file chooser itself;
- files continue to upload sequentially;
- the 10 MB limit remains per file, not per batch.

The current transfer and processing progress remain visible. The slower transfer of a large file on a mobile uplink does not block other application requests.

## PDF Behaviour

### Validation

PDF uploads must satisfy all of the following:

- maximum 10 MB per file;
- verified MIME type `application/pdf`;
- valid PDF file signature;
- no more than 50 pages;
- workspace media quota;
- normal workspace authorization.

The implementation must not trust the filename extension.

### Storage

PDF originals remain on the private media disk.

- Image assets retain the existing normalized WebP `master` media.
- PDF assets retain the original file in a document-specific media collection.
- A successful first-page render creates a WebP preview that uses the normal thumbnail pipeline.
- A preview-generation failure does not destroy or reject an otherwise valid PDF.

Downloads use an authenticated application route and an attachment content disposition. The private R2 URL is not exposed as a permanent public URL.

### Preview generation

PDF preview generation runs on the existing `media` queue and renders only the first page.

The implementation must detect whether the required server renderer is available. If rendering cannot run or fails safely:

- the asset becomes ready;
- the original remains attachable and downloadable;
- the UI displays the standard PDF placeholder;
- the failure is logged without exposing infrastructure details to the user.

The Forge deployment notes must identify the required PDF rendering package, but the application must retain the fallback behaviour.

### Attachments

PDFs are attachable as documents, not as inline images.

- Ingredients receive a document attachment area separate from their main image and icon.
- Recipe SOPs receive a document attachment area separate from rich-content images and featured media.
- Image-only pickers exclude PDFs at the query and validation layers.
- Document pickers accept ready PDF assets and may accept ready image assets only where the consumer explicitly allows supporting images.

Document attachment usages are represented through the existing polymorphic `media_asset_usages` system with document-specific usage roles. This avoids introducing a separate attachment ownership model while preserving type-safe rendering.

## Label Data Model

### `media_labels`

- `id`
- `workspace_id`
- `created_by_user_id`
- `name`
- `normalized_name`
- timestamps

Constraints and indexes:

- foreign key to workspace with cascade deletion;
- foreign key to creator with the project’s established user-deletion behaviour;
- unique index on `workspace_id` and `normalized_name`;
- index on `workspace_id` and `name`.

Normalization trims surrounding whitespace, collapses repeated internal whitespace, and performs Unicode-aware case normalization for uniqueness. The original display casing is retained in `name`.

### `media_asset_label`

- `media_asset_id`
- `media_label_id`
- timestamps

Constraints and indexes:

- unique asset/label pair;
- both foreign keys cascade on deletion;
- indexes support lookup from either side.

The relationship must additionally verify that the asset and label belong to the same workspace. Application validation performs this check before mutation, and all mutations occur within a transaction.

### Asset type

Media assets gain an explicit type represented by an enum with initial cases:

- Image
- Pdf

Existing assets are backfilled as Image. Upload validation, processing, filters, URLs, pickers, and usage assignment use the enum rather than inferring behaviour repeatedly from filenames.

## Limits and Abuse Resistance

### Plan-controlled limit

A new plan limit key controls the number of labels a workspace may create:

- key: `media_labels`
- initial free/default plan value: `20`
- empty value: unlimited, consistent with existing plan-limit semantics

The limit is editable in the existing Filament Plan resource. Creation uses the existing workspace quota-lock pattern so concurrent requests cannot exceed the allowance.

Lowering a plan limit does not remove existing labels. Existing labels remain assignable and removable, but new label creation is blocked until usage is below the current limit.

### Global safety rules

- maximum eight labels per asset;
- maximum 30 Unicode characters per label name;
- case-insensitive uniqueness within a workspace;
- no empty labels;
- no automatic label generation;
- at most 10 label-creation attempts per user and workspace per minute;
- transactional bulk mutations;
- database uniqueness and foreign-key enforcement.

These safety rules are application constants/configuration, not plan upsells.

## Authorization

Workspace viewers can filter by labels and view labels they are otherwise authorized to see through assets.

Workspace members with media-update permission can:

- create labels;
- assign and remove labels;
- rename labels;
- delete labels.

Media deletion permissions remain unchanged. Label permissions never grant access to an asset or consumer that the member could not otherwise access.

All label and attachment queries are workspace-scoped server-side.

## Plan Localization

Plan records retain stable slugs and their existing database copy as the administrative English fallback.

Public presentation resolves deterministic translation-catalogue keys using the plan slug, including:

- plan name;
- plan description;
- billing interval and price phrasing where applicable;
- media-asset allowance;
- media-label allowance;
- other displayed plan-feature labels.

Numeric values remain in `plan_limits` and are interpolated into translated strings. They are not duplicated in translation records.

Account, checkout, and plan-comparison surfaces use the same localized plan presenter so they cannot diverge. Missing catalogue entries fall back to the existing database value rather than exposing a raw translation key.

New copy is translated and reviewed for English, French, German, Spanish, Italian, and Dutch, then included in the deterministic authoritative interface catalogue.

## Error Handling

- Label validation errors appear beside the relevant label control.
- Reaching the plan allowance explains the 20-label workspace limit and does not suggest deleting assets.
- Exceeding eight labels explains the per-asset limit.
- Duplicate labels select or point to the existing label rather than creating a second case variant.
- PDF validation failures identify unsupported or invalid documents without exposing parser output.
- Preview failures produce a PDF placeholder and retain a ready, downloadable asset.
- Attachment failures do not remove or corrupt the underlying library asset.

## Accessibility

- The Labels dropdown has a visible label and selected-state announcement.
- Label removal controls include the label name in their accessible name.
- Selection mode and selected asset counts use a polite live region.
- PDF placeholders expose the asset filename and document type.
- First-page preview images use empty alternative text when the adjacent filename already labels the asset.
- All controls keep the existing Soapkraft focus treatment and touch-target sizing.

## Testing

### Labels

- workspace scoping and authorization;
- normalized uniqueness;
- 20-label plan allowance and concurrent quota locking;
- eight-label-per-asset validation;
- label rename and delete behaviour;
- cross-workspace assignment rejection;
- transactional bulk assignment/removal;
- hidden label UI for workspaces without labels;
- label filtering, search, and pagination;
- discreet card metadata truncation.

### PDFs

- MIME and signature validation;
- size and page-count validation;
- private original storage;
- successful first-page WebP preview;
- ready fallback when the renderer is unavailable or preview generation fails;
- authenticated attachment download;
- image-only picker exclusion;
- ingredient and SOP document attachment authorization;
- document usage synchronization and cleanup.

### Plans and localization

- Filament exposes the `media_labels` plan limit;
- the default plan seeds a value of 20;
- lower limits block creation without removing existing labels;
- account, checkout, and plan presentation resolve the active locale;
- missing plan copy falls back to database content;
- all six locales contain reviewed strings;
- the deterministic translation catalogue includes the new keys.

### Regression

- existing image uploads, processing, private delivery, focal points, and consumers remain green;
- existing sequential gallery batch uploads remain green;
- existing media quota calculations remain green;
- focused Pest suites pass;
- Pint and Filacheck pass when their respective files change;
- the production frontend build passes;
- the graph is refreshed after code changes.

## Acceptance Criteria

- A workspace that never creates labels sees no label-specific gallery control.
- Users create their own labels; Soapkraft creates none automatically.
- A labeled asset shows only a quiet metadata line beneath its normal card information.
- A workspace cannot create more labels than its plan permits.
- An asset cannot hold more than eight labels.
- The gallery can filter by user-created labels without folders or nested navigation.
- Valid PDFs remain private, downloadable, and attachable even when preview generation is unavailable.
- PDFs never appear in image-only pickers.
- Ingredients and recipe SOPs can attach ready PDF assets.
- Plan names, descriptions, and relevant feature entries display in the user’s active language.
- All new interface copy is durable through the authoritative translation catalogue.
