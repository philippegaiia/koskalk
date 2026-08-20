---
paths:
  - 'resources/css/**/*.css'
---

# Css

## Bridge public Filament buttons to user-shell tokens
For solid Filament primary buttons rendered inside `[data-user-shell]`, set the actual background and text from `--color-accent`, `--color-accent-hover`, and `--color-on-accent` in `resources/css/shared/filament-soapkraft.css`. A registered Filament palette generates different shades and will not exactly match `.sk-btn-primary`.
