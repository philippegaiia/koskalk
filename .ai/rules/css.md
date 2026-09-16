---
paths:
  - 'resources/css/**/*.css'
---

# Css

## Bridge public Filament buttons to user-shell tokens
For solid Filament primary buttons rendered inside `[data-user-shell]`, set the actual background and text from `--color-accent`, `--color-accent-hover`, and `--color-on-accent` in `resources/css/shared/filament-soapkraft.css`. A registered Filament palette generates different shades and will not exactly match `.sk-btn-primary`.

## Keep public selects light and on-brand
The authenticated user shell is light-only. Style both native selects (progressively with `appearance: base-select`) and Filament non-native select popovers with Soapkraft field, line, ink, and active-state tokens; do not leave OS-blue or Filament dark/default selection states in Production Bench.
