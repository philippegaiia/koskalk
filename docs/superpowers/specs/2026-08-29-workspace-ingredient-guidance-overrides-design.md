# Workspace Ingredient Guidance Overrides

## Goal

Let a workspace replace the guidance shown for a platform ingredient without changing the shared catalogue ingredient. Platform guidance remains the default. The override is deliberately simple: one workspace-owned Markdown value, written in whatever language the workspace member chooses.

## Product decisions

- The feature applies to platform ingredients. Workspace-owned ingredients keep using their own editable `info_markdown`.
- The override belongs to the workspace, not an individual user.
- Workspace owners, admins, and editors may create, update, or reset it. Viewers remain read-only under the existing workspace role model; an intern who performs catalogue work uses the editor role.
- The override is language-agnostic. The system stores no source locale and generates no translations.
- The override accepts at most 2,000 Unicode characters after trimming.
- The value may use the same limited Markdown supported by ingredient guidance. Raw HTML is not accepted.
- Images are outside this feature. A later design may add workspace image overrides with their own storage and deletion lifecycle.

## Effective guidance

Every workspace-facing ingredient view resolves one effective guidance value:

1. If the workspace has an override for the platform ingredient, show it exactly as saved.
2. Otherwise, show `Ingredient::localizedInfoMarkdown()` for the active application/workspace locale.

Changing the workspace language does not alter an existing override. Resetting the override immediately resumes platform inheritance, so the latest platform guidance appears in the active language.

The platform ingredient and its translations remain immutable from the workspace UI. Platform enrichment, guidance refreshes, and localization refreshes do not read or update workspace overrides.

## User flow

The platform ingredient detail/editor shows a `Workspace guidance` area near the platform guidance.

### No override

- Show the localized platform guidance as the effective value.
- Offer `Customize guidance` to members with write access.
- Opening customization pre-fills the editor with the currently displayed localized platform guidance. Nothing is stored until the member saves.

### Override exists

- Clearly label the displayed text as workspace guidance.
- Offer `Edit workspace guidance` and `Use platform guidance` to members with write access.
- Show a live `current / 2,000` character counter.
- `Use platform guidance` requires confirmation because it deletes the workspace override. The platform content itself is untouched.

### Validation and errors

- Save requires a non-empty string after trimming and enforces the 2,000-character limit.
- Raw HTML is rejected; supported Markdown remains available.
- Failed validation keeps the draft in the editor and shows a field-level message.
- Concurrent edits use a transaction and the current persisted row. The last successful save wins; audit metadata identifies the last editor.

## Data model

Create `workspace_ingredient_guidances` with:

- `id`
- `workspace_id`, foreign key with cascade deletion
- `ingredient_id`, foreign key with cascade deletion
- `guidance_markdown`, text
- `created_by_user_id`, nullable foreign key with null-on-delete
- `updated_by_user_id`, nullable foreign key with null-on-delete
- timestamps
- unique constraint on `(workspace_id, ingredient_id)`

The row exists only when an override exists. Reset deletes the row rather than storing an inheritance flag or a copy of platform content.

Create a small `WorkspaceIngredientGuidance` model and a service that owns authorization, validation, save, reset, and effective-value resolution. Keep persistence out of Livewire components.

## Catalogue consolidation and deletion

Platform-ingredient consolidation must reconcile overrides per workspace:

- Source-only override: transfer it to the target ingredient.
- Target-only override: keep it.
- Identical source and target overrides: keep the target and delete the duplicate source row.
- Different source and target overrides in the same workspace: abort consolidation and report the workspace conflict so no authored guidance is lost.

Deleting a workspace deletes its overrides. Deleting an ingredient cascades its overrides after the existing platform-deletion checks have authorized the deletion.

## Auditability

Store the creating and last-updating user IDs plus timestamps. Existing application activity/audit infrastructure should record create, update, and reset actions when available. The UI only needs to show the effective source (`Platform guidance` or `Workspace guidance`); editor identity is administrative history rather than catalogue prose.

## Alternatives considered

### Duplicate the platform ingredient into the workspace

This would reuse the existing workspace-owned ingredient editor, but it also duplicates identity, chemistry, declarations, and future catalogue corrections. It turns a content preference into catalogue fork maintenance, so it is rejected.

### Store overrides in workspace JSON settings

This avoids a table, but weakens foreign keys, uniqueness, merge reconciliation, auditing, and queryability. It is rejected in favor of a dedicated relation like workspace material codes and prices.

### Localize every workspace override

Per-locale overrides or automatic translation would reintroduce a review problem for languages the member does not use. The agreed product model assumes one active user language and stores the member's text unchanged, so localization is rejected for this feature.

## Testing requirements

Tests must prove:

- A workspace without an override receives the platform guidance in the active locale.
- Creating an override changes the effective guidance only for that workspace.
- Another workspace continues to receive platform guidance.
- Changing locale leaves an existing override unchanged.
- Reset deletes the row and restores the latest localized platform guidance.
- The editor pre-fills from the currently displayed platform locale without persisting until save.
- Empty, HTML-containing, and over-2,000-character values are rejected without changing the stored override.
- Owners, admins, and editors can write; viewers and non-members cannot.
- Workspace-owned ingredients continue using their own `info_markdown` and do not create override rows.
- Platform enrichment and localization do not modify workspace overrides.
- Ingredient consolidation handles source-only, target-only, identical, and conflicting overrides without data loss.

## Success criteria

A workspace member can accept the platform guidance without storing anything, replace it with up to 2,000 characters of workspace guidance, and return to the latest localized platform guidance with one reset action. No workspace action changes the platform catalogue or another workspace, and no translation workflow is required.
