---
paths:
  - '{resources/js/production-editing.js,resources/views/livewire/production-bench/production/production-detail.blade.php}'
---

# Livewire Production Bench Production

## Renderless production attachment results need local feedback
executeEditingCommand returns validation errors without re-rendering Blade @error blocks. Keep rejected attachment messages in Alpine beside Attach, including through later status polls, and show success only after an acknowledged command. Journal PDFs share Media Library's configured 180 KB limit; surface that limit instead of silently increasing it for this page.
