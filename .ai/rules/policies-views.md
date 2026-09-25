---
paths:
  - app/Http/Controllers/RecipeController.php
  - app/Policies/RecipePolicy.php
  - 'resources/views/**/recipe*.blade.php'
  - 'app/Policies/**'
---

# Policies Views

## Formula lock and unlock require Owner or Admin
Only the formula-owning workspace's actual Owner or a member with the Admin role may lock or unlock a formula. Editor and Viewer roles may not. Enforce this through a dedicated authorization ability at both the UI and write endpoint; do not infer it from general recipe update permission.

## Application admin does not bypass formula workspace permissions
User.is_admin controls application administration and Filament admin-panel access; it does not grant authority inside a customer's workspace. In the user-facing app, formula lock/unlock requires the actual workspace Owner or WorkspaceMemberRole::Admin. An application admin must also hold one of those workspace permissions; any support override must be a separate, explicit, audited admin action.

## Viewers may export visible workspace data
Viewer is read-only, not export-restricted: permit downloads and exports of records the Viewer may view. Apply the same selected-workspace and live-membership checks to downloads/exports; this never grants create, edit, delete or lock authority.
