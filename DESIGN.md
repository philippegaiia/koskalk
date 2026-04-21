# Koskalk — Design System

> **Creative North Star: "The Clinical Naturalist"**
> The interface of a professional soap chemist: rigorous precision of a lab tool,
> organic warmth of the craft. Premium feel through intentional restraint —
> no decoration, no "crafty" aesthetics. Complex chemical data made legible and authoritative.

---

## 1. Stack Context

- **Frontend (user-facing):** Blade + Livewire
- **Admin (internal only):** Filament — users never see this
- **Filament components reused in user UI:** Tables and Forms for Ingredients and Packaging pages only
- **CSS:** Tailwind v4 with `@theme` OKLCH tokens in `resources/css/app.css`
- **Font:** Instrument Sans (already loaded via Fontshare)
- **Rule:** Never modify files in `vendor/filament/`. Only modify `.blade.php` files and the `@theme` block in `app.css`.

---

## 2. Color Tokens — `@theme` block

Paste this into `resources/css/app.css`, replacing the existing `@theme {}` block:

```css
@theme {
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif,
        'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';
    --font-numeric: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas,
        'Liberation Mono', 'Courier New', monospace;

    --color-surface: oklch(98.3% .011 85);
    --color-sidebar: oklch(96.8% .010 86);
    --color-panel: oklch(99.5% .003 80);
    --color-panel-strong: oklch(95.1% .014 84);
    --color-field: oklch(99.7% .002 82);
    --color-field-muted: oklch(96.8% .010 84);
    --color-field-outline: oklch(78% .012 82);

    --color-hero: oklch(22.0% .025 154);
    --color-hero-soft: oklch(27.0% .028 154);
    --color-surface-strong: oklch(30.0% .022 80);

    --color-line: oklch(88% .010 80);
    --color-line-strong: oklch(72% .018 80);
    --color-line-hero: oklch(40% .030 154);

    --color-ink: oklch(28% .012 75);
    --color-ink-strong: oklch(13% .008 70);
    --color-ink-soft: oklch(54% .012 75);

    --color-ink-sidebar: oklch(24% .025 150);
    --color-ink-sidebar-soft: oklch(48% .025 150);
    --color-inverse: oklch(98% .006 84);
    --color-inverse-soft: oklch(76% .010 84);
    --color-inverse-muted: oklch(58% .010 84);

    --color-accent: oklch(53.0% .077 163);
    --color-accent-hover: oklch(38% .105 154);
    --color-accent-soft: oklch(94% .036 154);
    --color-accent-strong: oklch(32% .092 154);

    --color-warning: oklch(45.7% .087 65);
    --color-warning-hover: oklch(38% .100 55);
    --color-warning-soft: oklch(93% .038 68);
    --color-warning-strong: oklch(36% .095 55);

    --color-danger: oklch(55% .200 25);
    --color-danger-hover: oklch(46% .185 25);
    --color-danger-soft: oklch(95% .050 25);
    --color-danger-strong: oklch(45% .180 25);

    --color-success: oklch(52% .155 142);
    --color-success-soft: oklch(95% .040 142);
    --color-success-strong: oklch(36% .130 142);
}
```

---

## 3. Surface Architecture — The "No-Line" Rule

Depth is created by **background shifts only**. 1px solid borders for sectioning are prohibited.

```
Level 0 — Page background
  --color-surface  oklch(97.5% .008 82)  Warm Stone

  Level 1 — Primary cards (lifted)
    --color-panel  oklch(99.5% .003 80)  Quasi-white

    Level 2 — Insets / recessed zones (rows, data wells)
      --color-panel-strong  oklch(93.5% .014 80)  Slightly darker
```

**Cards have NO border.** Elevation comes from shadow only (see Section 5).

**Exception — ghost border for inputs only:**
Use `outline: 1px solid oklch(from var(--color-ink) l c h / 0.15)` on `<input>` and `<select>` for accessibility.
It should be felt, not seen.

---

## 4. Border Radius — 3 Levels Only

| Level | Value | Used for |
|---|---|---|
| Card / section wrapper | `0.75rem` (12px) | All section cards |
| Inner / row / input | `0.5rem` (8px) | Ingredient rows, input fields, inner zones |
| Badge / pill | `9999px` | NaOH/KOH/Water badges, category tags |

**Nested radius rule:** when an inner element sits inside a padded container,
`inner-radius = outer-radius - container-padding`. If padding ≥ outer-radius, inner = 0.

Never apply the same radius uniformly to all elements.

---

## 5. Elevation & Shadows

Only **Level 1 cards** receive a shadow. Rows, badges, and inputs have no shadow.

```css
/* Card shadow — warm umber double-layer, never pure black */
box-shadow:
  0 2px 4px rgba(60, 50, 30, 0.04),
  0 12px 24px rgba(60, 50, 30, 0.08);
```

In Tailwind: `shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)]`

---

## 6. Typography

**Font:** Instrument Sans — single typeface, weight variation only. No second font family needed.

### Section metadata labels
Section headers (`REACTION CORE`, `FATTY ACID PROFILE`, etc.) are **metadata, not titles**.

```html
<p class="text-[0.6875rem] font-medium uppercase tracking-[0.05em] text-[var(--color-ink-soft)]">
    Reaction Core
</p>
```
Use `<p>` or `<span>`, never `<h2>`/`<h3>` for these labels.

### Numeric values
All weights (g), percentages (%), INS values, and chemical quantities:
```html
<span class="font-mono text-sm">47.3</span>
```
Monospace ensures column alignment without drawing grid lines.

### Size scale
| Context | Size | Weight |
|---|---|---|
| Page/section title | `text-xl` | semibold |
| Section metadata label | `0.6875rem` | medium + uppercase |
| Body / UI | `text-sm` (14px) | regular |
| Readable content | `text-base` (16px) | regular |
| Numeric values | `text-sm font-mono` | regular |
| Tiny metadata | `text-xs` | regular |

---

## 7. Components

### Card (Section wrapper — Level 1)
```html
<div class="rounded-xl bg-[var(--color-panel)]
            shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)]
            p-5">
    <!-- content -->
</div>
```

### Inset zone (Level 2 — ingredient rows, data wells)
```html
<div class="rounded-lg bg-[var(--color-panel-strong)] px-4 py-3">
    <!-- row content -->
</div>
```

### Ingredient row (inside card, spaced with gap not dividers)
```html
<div class="flex items-center gap-3 rounded-lg bg-[var(--color-panel-strong)] px-4 py-3">
    <span class="flex-1 text-sm text-[var(--color-ink)]">Olive oil virgin</span>
    <span class="text-sm font-mono text-[var(--color-ink-soft)] w-12 text-right">60%</span>
    <span class="text-sm font-mono text-[var(--color-ink)] w-16 text-right">600 g</span>
    <button class="text-[var(--color-ink-soft)] hover:text-[var(--color-danger)] ml-1">×</button>
</div>
```
Use `space-y-2` between rows — **never** use `<hr>` or border-bottom for row separation.

### Input field (ghost border, focus transitions)
```html
<input class="w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2 text-sm
              outline outline-1 outline-[oklch(from_var(--color-ink)_l_c_h_/_0.15)]
              focus:outline-2 focus:outline-[var(--color-accent)]
              transition-all duration-150">
```

### Buttons
```html
<!-- Primary CTA (solid sage — one per section max) -->
<button class="rounded-lg bg-[var(--color-accent)] hover:bg-[var(--color-accent-hover)]
               text-white text-sm font-medium px-4 py-2 transition-colors">
    Save Formula
</button>

<!-- Secondary (ghost) -->
<button class="rounded-lg border border-[oklch(from_var(--color-accent)_l_c_h_/_0.40)]
               text-[var(--color-accent)] hover:bg-[var(--color-accent-soft)]
               text-sm font-medium px-4 py-2 transition-colors">
    Save Draft
</button>

<!-- Tertiary / navigation (text only) -->
<button class="text-sm text-[var(--color-ink-soft)] hover:text-[var(--color-ink)]
               transition-colors px-2 py-1">
    Open Recipe
</button>
```

### Badges
```html
<!-- Alkaline — NaOH / KOH -->
<span class="rounded-full px-3 py-0.5 text-xs font-semibold
             bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]">
    NaOH  47.3 g
</span>

<!-- Hydration — Water -->
<span class="rounded-full px-3 py-0.5 text-xs font-semibold
             bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]">
    Water  113.0 g
</span>

<!-- Neutral — category tag -->
<span class="rounded-full px-3 py-0.5 text-xs font-medium
             bg-[var(--color-panel-strong)] text-[var(--color-ink-soft)]">
    Carrier Oil
</span>
```

### Koskalk Quality metric bar
```html
<div class="space-y-3">
    <!-- Example row — repeat per metric -->
    <div>
        <div class="flex justify-between mb-1">
            <span class="text-[0.6875rem] uppercase tracking-[0.05em] text-[var(--color-ink-soft)]">
                Unmolding Firmness
            </span>
            <span class="text-xs font-mono text-[var(--color-ink)]">27.6</span>
        </div>
        <div class="h-1.5 rounded-full bg-[var(--color-panel-strong)] overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500"
                 style="width: 35%; background: var(--color-warning);">
            </div>
        </div>
    </div>
</div>
```
Bar color mapping:
- `var(--color-warning)` — Low / below target range
- `var(--color-accent)` — Moderate / Good / target range
- `var(--color-danger)` — High risk (DOS risk, Slime risk)

### Glassmorphism (tooltips and small overlays ONLY)
```css
.tooltip-overlay {
    background: oklch(99.5% .003 80 / 0.60);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
}
```
**Do not apply glassmorphism to:** sidebars, navigation panels, section cards, or any persistent UI element.

---

## 8. Do's and Don'ts

### ✅ Do
- Use background shifts to define boundaries — never lines
- Use `space-y-2` / `space-y-3` between rows and items
- Use `font-mono` for all weights, percentages, and chemical values
- Use `0.6875rem uppercase tracking-[0.05em]` for section labels
- Limit accent color to CTAs and active states only
- Use 8px radius for inner elements, 12px for section cards, pill for badges
- Apply shadow to cards only — one level of elevation

### ❌ Don't
- **No gradient buttons** — solid accent color only
- **No colored left-border on cards** — `border-left: 3px solid accent` is forbidden
- **No icons in colored circles or squares** — icons are naked and monochrome
- **No `<hr>` or border-bottom** to separate rows — use spacing instead
- **No background decorations** — no blobs, bubbles, patterns, leaf/soap textures
- **No more than 2 non-neutral hues** visible in any single viewport
- **No uniform radius** — always differentiate card / inner / badge levels
- **No shadow on rows, badges, or inputs** — shadow is for card elevation only
- **No pure black `#000` shadows** — always use warm umber `rgba(60, 50, 30, ...)`
- **No glassmorphism outside tooltips** — not on panels, sidebars, or cards
- **No `<h2>`/`<h3>` for section labels** — use `<p>` with metadata style

---

## 9. Agent Instructions Summary

When receiving a design or frontend task for Koskalk, the agent must:

1. Read this file before touching any Blade template or CSS
2. Use `var(--color-*)` tokens — never hardcode hex values
3. Use `font-mono` on all numeric/chemical values
4. Apply the 3-level radius system — never uniform radius
5. Apply shadow to cards only — never to rows, inputs, or badges
6. Separate items with spacing (`space-y-*`) — never with `<hr>` or `border-bottom`
7. Keep accent color (`var(--color-accent)`) strictly for CTAs and active states
8. Section labels are `<p>` with metadata style — not heading elements
9. Do not modify `vendor/filament/` files under any circumstances
10. Glassmorphism is allowed only for tooltips and small floating overlays
