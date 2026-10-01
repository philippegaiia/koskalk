---
paths:
  - 'resources/css/**/*.css'
---

# Css

## Keep public selects light and on-brand
The authenticated user shell is light-only. Style both native selects (progressively with `appearance: base-select`) and Filament non-native select popovers with Soapkraft field, line, and ink tokens. Selected options use a neutral `--color-panel-strong` surface; reserve green for the checkmark or selected text, and do not leave OS-blue or Filament dark/default states in Production Bench.

## Bridge public Filament buttons to user-shell tokens
Solid primary buttons use --color-button-primary (darker default), --color-button-primary-hover (lighter hover), and --color-on-accent. Apply the same actual background/text tokens to .sk-btn-primary and solid Filament primary buttons inside [data-user-shell]; a registered Filament palette does not match exactly. These button roles supersede the previous accent/accent-hover mapping only for filled buttons. Rebind aliases on [data-user-shell] so they resolve that shell's accent values; preserve accent tokens for links, focus rings, and insertion lines.
