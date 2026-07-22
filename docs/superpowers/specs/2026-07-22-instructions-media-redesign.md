# Instructions & Media Redesign

**Date:** 22 July 2026  
**Status:** Approved design; implementation plans ready

Implementation plans:

1. `docs/superpowers/plans/2026-07-22-upload-original-filenames.md`
2. `docs/superpowers/plans/2026-07-22-instructions-media-workflow.md`
3. `docs/superpowers/plans/2026-07-22-sop-snapshots-media-retention.md`

## Purpose

Redesign the authenticated workbench's **Instructions & media** tab so that it clearly supports two different authoring tasks:

1. presenting the finished product; and
2. recording the manufacturing procedure used at the bench.

The existing page treats the description, SOP, and featured image as equally large form blocks. This produces an oversized, undifferentiated layout and forces the featured image into a destructive 4:3 crop. The redesign gives each task an appropriate amount of space, preserves varied product-image ratios, and makes saving reliable on a long page.

## Relationship to Other Workbench Areas

This remains the existing **Instructions & media** tab.

- **Label & output** continues to contain generated INCI/label output, declaration warnings, and calculated output.
- **Instructions & media** contains user-authored product presentation, the featured product image, and the manufacturing procedure/SOP.
- The generated label does not move into this tab.
- User-authored content is not translated automatically.

## Considered Layouts

### Open Workflow — Selected

Product description and featured image share the first row on desktop. The full-width SOP follows below. Everything remains visible in one reading sequence without nested navigation.

### Focused Switcher

An internal Presentation/Manufacturing switch would shorten the initial page but hide one of the two core tasks and add navigation inside an existing tab.

### Persistent Media Rail

A media rail would keep the featured image visible while editing, but it would constrain landscape images and reduce the width available to a long SOP.

The **Open Workflow** is selected because it best matches the content hierarchy and the downstream Product page and Formula Sheet outputs.

## Page Structure

### Page Header

Use a concise page heading and one sentence of contextual help. Avoid the current stacked card-style introduction and internal terminology such as “content records.”

Recommended English direction:

- **Heading:** Instructions & media
- **Supporting copy:** Add the product description and image used on the Product page, then record the manufacturing procedure used at the bench.

The save state may appear in this header on wide screens, but it must also remain available in the sticky save bar.

### Product Presentation

The first section uses two columns on desktop and one column on smaller screens.

The larger left column contains:

- **Product description** rich editor;
- concise help explaining that the description appears on the Product page;
- an initial editor height smaller than the SOP editor;
- a maximum of two embedded images.

The smaller right column contains:

- **Featured product image** upload;
- a compact adaptive preview;
- original filename display;
- Replace and Remove actions.

The featured-image preview must not force the image into a fixed crop. Portrait, landscape, and square images are displayed in a calm neutral frame using a contain treatment. Empty space around an unusual ratio is acceptable and intentional.

### Manufacturing Procedure

The second section spans the full content width below Product presentation.

It contains:

- **Manufacturing procedure** rich editor;
- contextual help mentioning process steps, temperatures, timings, checks, and cautions;
- a larger initial editing area than Product description;
- a maximum of eight embedded images.

The rich editor remains flexible rather than converting the procedure to structured steps in this delivery. Authors may resize embedded images within the editor.

### Responsive Behavior

On desktop, Product description and Featured product image are side by side. The description receives more width than the image upload.

On smaller screens:

- Product description appears first;
- Featured product image follows;
- Manufacturing procedure remains last;
- the sticky save bar keeps its status and primary action visible without covering editor controls.

## Featured Image Policy

The Product page needs one featured image in this delivery. A gallery is not introduced.

### Accepted Source

- JPEG, PNG, and WebP;
- maximum source upload size: 3 MB;
- free aspect ratio;
- minimum short edge: 300 px;
- minimum long edge: 500 px.

Orientation-independent validation is required: a 300 × 500 portrait and a 500 × 300 landscape both meet the minimum.

### Stored Image

- convert to WebP;
- preserve aspect ratio;
- never upscale a smaller valid image;
- maximum long edge: 800 px;
- target WebP quality: 80–82;
- store under the existing private recipe media namespace.

The Product page must use a non-destructive contain presentation for ratios that do not fill its visual frame. Later output-specific crops may be designed as derived presentation choices, but they must not replace or destructively crop the stored master used here.

## Embedded Rich-Text Image Policy

### Counts

- Product description: maximum two embedded images;
- Manufacturing procedure: maximum eight embedded images.

The limit applies to images present in the saved field, not to how many upload attempts occurred. Moving an existing attachment between the two fields must preserve ownership and must not create a duplicate object.

### Stored Image

- accept JPEG, PNG, and WebP sources;
- retain the current 1.5 MB maximum source upload size;
- convert to WebP;
- preserve aspect ratio;
- never upscale;
- maximum long edge: 680 px;
- target WebP quality: 80;
- authors may choose a smaller rendered width inside the editor.

Upload rejection must explain whether the problem is file type, source size, dimensions, or the image-count limit.

## Storage and Ownership

Recipe media remains private.

Rich-content attachments use the existing namespace:

`recipes/<recipe-public-id>/rich-content/<ulid>.webp`

Featured images use the existing featured-image namespace under the recipe public identifier.

The database stores private object paths and rich-text attachment identifiers, not image binaries or public R2 URLs. Laravel continues to serve private media through authorization-checked routes.

### Application-Wide Original Filename Convention

Every persisted Filament `FileUpload` control in Soapkraft follows the same convention. The generated ULID or random name remains the physical storage filename. The user-controlled original filename must never become the object key.

The initial implementation covers:

- recipe featured images;
- private ingredient featured images and icons;
- admin ingredient featured images and icons;
- private packaging-item featured images;
- admin product-type fallback images.

Persist a sanitized display-only original filename separately beside each path attribute. Examples include:

`featured_image_original_name`

`icon_image_original_name`

`fallback_image_original_name`

Requirements:

- retain at most 255 characters;
- remove path components and control characters;
- escape the value whenever rendered;
- update it when the featured image is replaced;
- clear it when the image is removed;
- display it after the form reloads instead of exposing the ULID filename.

This follows Filament's recommended `storeFileNamesIn()` pattern: keep random storage names while storing original names independently.

Existing records may have a stored path without original-name metadata. Their upload previews show a neutral label such as **Current image** rather than exposing the generated storage filename.

Rich-editor images are inline attachments rather than named `FileUpload` previews. They must not expose generated paths in visible interface copy. Their existing path-based attachment references remain unchanged in this delivery.

## Saving and Dirty-State Protection

### Explicit Save

A sticky **Save changes** bar remains visible while the user scrolls the page. It shows one of these states:

- All changes saved;
- Unsaved changes;
- Saving…;
- Saved at `<time>`;
- Save failed.

Manual Save changes remains available even when safety autosave is enabled.

### Safety Autosave

When the form becomes dirty, schedule a safety save no later than two minutes later. Continuous typing must not postpone the save indefinitely. After a successful save, later changes begin a new dirty interval.

Autosave must not submit while a file is still uploading. If an upload is in progress when the interval expires, save as soon as the upload completes.

### Leaving the Page

Warn before leaving only when:

- changes are still unsaved;
- a save is in progress; or
- the last save failed.

Do not warn after a successful manual or automatic save.

The dirty-form guard should be reusable elsewhere in Soapkraft, but individual forms opt in. It must not be enabled globally on pages that do not track editable state.

### New Unsaved Formula

An unsaved formula may hold text draft state in the current workbench session. The interface must explain that the formula must be saved before media can be attached. Image actions remain disabled until a recipe record exists.

## Content Lifecycle and Outputs

### Product-Level Content

The planned Product page uses the recipe's current:

- featured image;
- product description;
- manufacturing procedure in its Manufacturing destination.

Product description and featured-image presentation remain current product-level content and are not versioned in this delivery.

### SOP Snapshotting

When a formula is saved, capture the manufacturing procedure with that saved formula.

- The Product page Manufacturing destination shows the current recipe SOP.
- The saved Formula Sheet uses the SOP captured with that save.
- Paid saved-history records retain the SOP captured at their respective save.
- Free users retain only the SOP associated with the latest saved formula.

Historical references must not silently display a newer procedure.

### Media References and Cleanup

Saved history reuses media objects; it must not copy image binaries on every save.

An attachment may be deleted from private storage only when it is no longer referenced by:

- the current product description;
- the current manufacturing procedure;
- a retained saved-history SOP or other retained historical output.

Replacing or removing current content must not break images used by a retained historical SOP. When saved history expires or is removed, media cleanup may delete objects that have no remaining references.

An uploaded object that is never committed to recipe content must not remain indefinitely as an orphan. Failed saves, abandoned replacements, and cancelled uploads require safe cleanup without deleting the last confirmed featured image or any retained rich-content attachment.

## Translation Rules

English interface copy is finalized before translations are created.

Translate contextually into French, Spanish, German, Italian, and Dutch:

- headings;
- field labels;
- helper text;
- buttons;
- save statuses;
- validation messages;
- image-limit messages;
- unsaved-form and leave-page warnings;
- empty and error states.

Do not translate:

- product descriptions;
- manufacturing procedures;
- uploaded filenames;
- ingredient/product data entered by the user.

The English text remains the base version in code. Supported-locale values are seeded through the established interface-translation process and remain editable in the database.

## Accessibility

- The featured-image preview must not use the original filename as alt text.
- The Product page may use the product name as a conservative fallback until dedicated image-alt authoring is designed.
- Save status changes must be announced without stealing focus.
- Upload errors must be associated with the relevant field.
- Keyboard users must be able to replace and remove the featured image.
- The sticky save bar must not obscure focused controls at common viewport sizes.
- Motion is limited to restrained status and layout transitions and respects reduced-motion preferences.

## Failure and Empty States

### No Featured Image

Show a compact upload target and explain that the image appears on the Product page. Downstream Product pages render a deliberate neutral media area rather than a broken image.

### Invalid Featured Image

Identify the failing rule precisely:

- unsupported file type;
- more than 3 MB at upload;
- shorter than 300 px on the short edge;
- shorter than 500 px on the long edge;
- image processing failed.

The prior saved image remains unchanged when replacement processing fails.

### Rich-Text Image Limit

When the description already contains two images or the SOP contains eight, keep existing content intact and explain the relevant maximum. Removing an embedded image allows another to be added after cleanup rules are satisfied.

### Autosave Failure

Keep the form dirty, show **Save failed**, retain the manual Save changes action, and warn before leaving. Do not report a successful save until the server confirms it.

## Security

- Continue accepted-MIME validation and server-side image decoding.
- Retain randomly generated storage names.
- Do not use `preserveFilenames()` or the original client filename as a storage path.
- Continue recipe-scoped private media authorization and attachment-path tampering protection.
- Do not expose a public URL for the private R2 bucket.
- Escape original filename metadata in the interface.

## Verification Criteria

Implementation is complete only when automated tests cover:

- portrait, landscape, and square featured images without forced cropping;
- orientation-independent minimum dimensions;
- 800 px maximum featured-image long edge;
- 680 px maximum embedded-image long edge;
- two-description-image and eight-SOP-image limits;
- original featured filename persistence and display after reload;
- ULID storage path retained despite original-name display;
- replacement and removal metadata behavior;
- original-name display behavior for every persisted Filament `FileUpload` control;
- neutral display labels for pre-existing uploads without original-name metadata;
- cleanup of uploads abandoned before a successful save;
- private media authorization and path tampering protection;
- cleanup that preserves media referenced by retained history;
- manual save, two-minute safety autosave, and save-state transitions;
- leave protection for dirty, saving, and failed states;
- no leave warning after a confirmed save;
- saved-formula SOP snapshot behavior;
- mobile stacking and non-obscuring sticky save behavior;
- contextual interface translations for all five supported non-English locales.

## Non-Goals

This redesign does not add:

- a separate media gallery;
- output-specific image cropping;
- structured SOP steps or checklists;
- automatic translation of authored content;
- a public Product page or sharing controls;
- formula-output redesign beyond consuming the approved description, image, and SOP data;
- storage of original uncompressed image binaries.
