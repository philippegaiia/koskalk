---
paths:
  - '{resources/js/production-editing.js,app/Livewire/Concerns/InteractsWithProductionEditing.php}'
---

# Concerns

## Pair production reload drafts with their exact revision receipt
Reload prepares a Locked snapshot without rebasing mounted revisions or clearing drafts. The browser adopts it only while clean, then explicitly accepts that receipt; unconfirmed acceptance keeps writes disabled through polls. Never adopt a later revision for retained older input. Treat display refresh as separate from an acknowledged successful command so a failed render cannot report that save as failed.
