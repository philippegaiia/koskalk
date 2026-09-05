---
paths:
  - app/Livewire/Dashboard/IngredientEditor.php
---

# Dashboard

## Workspace soap chemistry is inherited
A workspace user cannot mark a manually created ingredient as trusted for soap saponification. Show and retain soap chemistry only for an editable duplicate of a trusted platform ingredient whose source_data contains user_authoring.trusted_koh_sap_value. Keep the trust flag hidden in the workspace editor.

## Count guidance limits from visible text
Workspace guidance is stored as sanitized HTML. Enforce its 10,000-character ceiling on rendered visible text after sanitization, excluding HTML markup; do not add a raw RichEditor maxLength that rejects valid formatted content.
