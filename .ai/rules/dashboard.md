---
paths:
  - app/Livewire/Dashboard/IngredientEditor.php
  - app/Livewire/Dashboard/RecipeWorkbench.php
---

# Dashboard

## Workspace soap chemistry is inherited
A workspace user cannot mark a manually created ingredient as trusted for soap saponification. Show and retain soap chemistry only for an editable duplicate of a trusted platform ingredient whose source_data contains user_authoring.trusted_koh_sap_value. Keep the trust flag hidden in the workspace editor.

## Count guidance limits from visible text
Workspace guidance is stored as sanitized HTML. Enforce its 10,000-character ceiling on rendered visible text after sanitization, excluding HTML markup; do not add a raw RichEditor maxLength that rejects valid formatted content.

## Preserve mounted editing baselines in every workbench render
Every render of an editable saved workbench must include editing protection metadata, including hydration after save/content actions and markup used by Livewire navigation. Use the Locked expectedRecipeRevision, expectedVersionId and expectedCostingRevision properties; never replace the mounted baseline with fresh database revisions during render. Missing editing metadata disables client reservation acquisition, heartbeats and the recovery banner while the server still requires a lease.
