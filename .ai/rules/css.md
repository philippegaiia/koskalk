---
paths:
  - 'resources/css/**/*.css'
---

# Css

## Keep public selects light and on-brand
The authenticated user shell is light-only. Style both native selects (progressively with `appearance: base-select`) and Filament non-native select popovers with Soapkraft field, line, and ink tokens. Selected options use a neutral `--color-panel-strong` surface; reserve green for the checkmark or selected text, and do not leave OS-blue or Filament dark/default states in Production Bench.

## Bridge public Filament buttons to user-shell tokens
Solid primary buttons use --color-button-primary (darker default), --color-button-primary-hover (lighter hover), and --color-on-accent. Apply the same actual background/text tokens to .sk-btn-primary and solid Filament primary buttons inside [data-user-shell]; a registered Filament palette does not match exactly. These button roles supersede the previous accent/accent-hover mapping only for filled buttons. Rebind aliases on [data-user-shell] so they resolve that shell's accent values; preserve accent tokens for links, focus rings, and insertion lines.

## Keep the page ground near-neutral; elevation is a lift, not a float
--color-surface is the largest painted area in the app, and perceived saturation grows with area: a chroma that reads as neutral paper in a swatch reads as pale green full-screen. Near-neutral tints also sit inside the spread of panel white points, so a calibrated wide-gamut Mac neutralises them while a cheaper panel adds to the hue (owner report 2026-10-04: "perfect on my Mac, too much on some displays"). Chroma is therefore held at 0.009 (global) and 0.0055 ([data-user-shell]); raising it back reintroduces the complaint. Small surfaces keep their chroma on purpose -- --color-active-soft, --color-success-soft and the badge scale need more of it to register at all.

--shadow-card is elevation, not float: 0 -1px 2px rgba(60,50,30,.03), 0 1px 2px rgba(60,50,30,.04), 0 6px 14px -4px rgba(60,50,30,.06). It was 0 12px 24px / .07 and read as a modal that exaggerated the gap between stacked sections. The -4px spread keeps it hugging the edge. The 0 -1px layer exists because panels have no border and sit only ~1.8 lightness above the ground, so a downward-only shadow left the top edge undefined; it is weaker than the layer below (0.03 vs 0.04) so light still reads as coming from above. --color-surface and --shadow-card values are pinned by string in RecipeWorkbenchDesignPolishTest -- update that test in the same change or the suite fails.

Corollary: a mode selector is not an action. The "Enter amounts as" control in entry-mode-toggle.blade.php uses --color-active-soft with --color-ink-strong, not the --color-active fill used by the action/toggle pills in formula-settings. Hover a --color-control surface with --color-control-hover (97.6%) and pair it with a text step to --color-ink; the small background step cannot carry the hover alone. Two traps here: `hover:bg-[var(--color-panel)]` is a no-op (--color-panel is 0.6 lightness from --color-control), and `hover:bg-[var(--color-panel-muted)]` is worse than a no-op on a --color-field-muted band because both are 96.4% -- the control melts into the surface it sits on.

Corollary: do not nest a raised surface inside a recessed one, and do not put an elevation shadow on a recess. --color-field-muted (96.4%) is darker than the page ground (97.5%), so a band using it reads as inset; a raised --color-control track inside it, or a --shadow-card on it, would claim the opposite at the same time. The "Enter amounts as" control is no longer a band: it sits directly on the page ground as a toolbar for the whole formula column (it governs oils and additions alike, so it stays above the soap/cosmetic split, not inside one card), and its two segments sit loose (gap-2), matching the radio groups in formula-settings.

## Two hue families only: warm 50-95 and botanical green 145-166
Do not introduce a hue outside those two ranges for a surface. A blue ground (hue 245) was tried on the reference panels and removed 2026-10-04: beside the warm family it read as an unbranded third family, and at chroma 0.007 it read as cold grey rather than as a deliberate blue. Reference/analysis panels are now marked by --color-reference-heading (oklch(37.0% 0.025 245), slate ink) and --color-reference-line (oklch(91.0% 0.014 245), slate rule) on a warm ground -- the signal lives in the ink and the hairline, not in a fill. Blue survives only as --color-info (hue 230) where it means "informational/materials".

Corollary: a near-white cannot mark a selected row against a near-white dropdown. .sk-combobox-reference selection uses --color-panel-muted, not --color-reference-header, because that token is now a warm near-white.

## Tell workbench zones apart by lightness, not hue
Inside .sk-workbench, cards carry a zone class instead of a new hue. sk-zone-work (formula cards: saponification, additions, liquid process, cosmetic phases) sits brightest on --color-field; results stay on --color-panel; sk-zone-rail (ingredient browser) is a warm tint below the page ground. The rail is a tinted card, not a recess: it keeps --shadow-card like its sibling sections, has no row rules (the hover fill separates rows) and keeps its names at text-sm font-medium for reading comfort. Owner feedback 2026-10-28: a recessed rail with a hairline and row rules read as heavy, and 13px bold names on the tint took effort to read.

