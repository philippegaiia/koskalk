---
name: Soapkraft
description: Calm formulation software for soap and cosmetic makers.
colors:
  warm-bench-surface: "oklch(96.7% 0.008 91)"
  sidebar-clay: "oklch(95.3% 0.010 87)"
  clean-panel: "oklch(99.7% 0.004 91)"
  recessed-stone: "oklch(93.4% 0.014 93)"
  field-paper: "oklch(99.4% 0.003 91)"
  field-muted: "oklch(96.4% 0.010 94)"
  field-outline: "oklch(86.1% 0.017 88)"
  bench-ink: "oklch(33.8% 0.015 85)"
  bench-ink-strong: "oklch(24.0% 0.010 89)"
  bench-ink-soft: "oklch(50.2% 0.015 82)"
  living-green: "oklch(53.0% 0.075 145)"
  living-green-hover: "oklch(42.5% 0.078 145)"
  living-green-soft: "oklch(94.8% 0.028 145)"
  living-green-strong: "oklch(34.5% 0.072 145)"
  chemistry-amber: "oklch(55.5% 0.146 49)"
  chemistry-amber-soft: "oklch(94.2% 0.029 78)"
  quiet-blue: "oklch(50.0% 0.075 230)"
  danger-red: "oklch(55% .200 25)"
  success-teal: "oklch(49.0% 0.085 166)"
  forest-deep: "oklch(20.3% 0.026 149)"
  forest: "oklch(27.9% 0.039 150)"
  cream: "oklch(95.7% 0.012 80)"
  cream-warm: "oklch(92.6% 0.023 87)"
typography:
  display:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(2.5rem, 5vw, 4.25rem)"
    fontWeight: 600
    lineHeight: 1.02
    letterSpacing: "0.015em"
  headline:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(2rem, 4vw, 3.25rem)"
    fontWeight: 600
    lineHeight: 1.08
  title:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 600
    lineHeight: 1.25
  body:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.6
  label:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.6875rem"
    fontWeight: 700
    letterSpacing: "0.08em"
  numeric:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, Courier New, monospace"
    fontSize: "0.875rem"
    fontWeight: 400
rounded:
  sm: "8px"
  md: "14px"
  lg: "20px"
  pill: "9999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.living-green}"
    textColor: "{colors.cream}"
    rounded: "{rounded.sm}"
    padding: "10px 16px"
  button-primary-hover:
    backgroundColor: "{colors.living-green-hover}"
    textColor: "{colors.cream}"
    rounded: "{rounded.sm}"
    padding: "10px 16px"
  card:
    backgroundColor: "{colors.clean-panel}"
    textColor: "{colors.bench-ink}"
    rounded: "{rounded.md}"
    padding: "20px"
  field:
    backgroundColor: "{colors.field-paper}"
    textColor: "{colors.bench-ink-strong}"
    rounded: "{rounded.sm}"
    padding: "12px 16px"
---

# Design System: Soapkraft

## 1. Overview

**Creative North Star: "The Formulation Bench"**

Soapkraft should feel like a calm, practical, professional bench for soap and cosmetic formulation. It carries natural warmth through cream, stone, and green, but it stays precise enough for lye calculations, formulation tables, costing, label signals, and compliance preparation.

The product is dense by design. Users need many values on screen, especially in the workbench, so the visual system uses restrained color, compact controls, numeric alignment, and predictable structure. Warmth comes from the palette and soft elevation, not from craft decoration.

This system explicitly rejects the anti-references in PRODUCT.md: Etsy craft templates, generic SaaS card grids, flashy AI gradients, neon dark lab interfaces, and decorative handmade soap blog aesthetics.

**Key Characteristics:**

- Warm laboratory neutrals with one relaxed green accent.
- Dense, work-focused surfaces with clear hierarchy.
- Tonal layering first, soft warm elevation second.
- Numeric values use tabular monospace styling.
- Compliance is visible and calm, never visually dominant by default.

## 2. Colors

The palette is Warm Laboratory Neutrals plus Living Green, with amber, blue, red, and teal reserved for semantic meaning.

### Primary

- **Living Green** (`--color-accent`): primary actions, selected states, focus rings, and the most important active affordances.
- **Living Green Soft** (`--color-accent-soft`): selected navigation, subtle active backgrounds, hover fields, and quiet positive context.
- **Living Green Strong** (`--color-accent-strong`): active labels and text placed over soft green.

### Secondary

- **Chemistry Amber** (`--color-warning`, `--color-chemistry`): lye, chemistry, warning, and below-target signals.
- **Quiet Blue** (`--color-info`): informational states that are not success, warning, or danger.
- **Success Teal** (`--color-success`): success states and ideal quality results.
- **Danger Red** (`--color-danger`): destructive actions, high-risk conditions, and error states.

### Tertiary

- **Forest Landing Palette** (`--color-forest`, `--color-forest-deep`, `--color-forest-night`): public landing-page depth, brand contrast, and photo-led hero treatments.
- **Cream Landing Palette** (`--color-cream`, `--color-cream-warm`, `--color-cream-dark`): public shell backgrounds and warm brand sections.

### Neutral

- **Warm Bench Surface** (`--color-surface`): authenticated app background.
- **Sidebar Clay** (`--color-sidebar`): app navigation rail background.
- **Clean Panel** (`--color-panel`): primary cards, tables, and modal surfaces.
- **Recessed Stone** (`--color-panel-strong`): inset rows, grouped controls, and secondary panels.
- **Field Paper** (`--color-field`): input and select backgrounds.
- **Field Outline** (`--color-field-outline`): accessible field outline.
- **Bench Ink** (`--color-ink`, `--color-ink-strong`, `--color-ink-soft`): body, headings, and secondary text.

### Named Rules

**The Green Earns Attention Rule.** Living Green is for primary actions, selected states, focus, and meaningful state indicators. Do not use it as decoration.

**The Semantic Color Rule.** Amber, blue, red, and teal must explain state. They are not palette variety.

**The Brand Override Rule.** The landing page may use the forest and cream palette more boldly. The authenticated product stays restrained.

## 3. Typography

**Display Font:** Instrument Sans with system sans fallbacks.
**Body Font:** Instrument Sans with system sans fallbacks.
**Label/Mono Font:** System monospace stack for numeric values.

**Character:** One humanist sans carries the product. The voice is calm and technical without feeling sterile. Type hierarchy comes from weight, scale, case, and spacing, not from decorative font pairing.

### Hierarchy

- **Display** (600, `clamp(2.5rem, 5vw, 4.25rem)`, 1.02): public landing hero headlines only.
- **Headline** (600, `clamp(2rem, 4vw, 3.25rem)`, 1.08): public landing sections and major page statements.
- **Title** (600, `1.25rem`, 1.25): app cards, workbench panels, and page headings.
- **Body** (400, `0.875rem`, 1.6): dense product text, helper copy, table labels, and control text.
- **Label** (700, `0.6875rem`, `0.08em`, uppercase): metadata labels such as workbench sections, card eyebrows, and panel context.
- **Numeric** (400, `0.875rem`, tabular monospace): weights, percentages, costs, qualities, lye, water, and chemistry values.

### Named Rules

**The Metadata Label Rule.** Uppercase labels describe context; they are not section headlines. Use them sparingly and keep them small.

**The Numeric Trust Rule.** Any measured formulation value uses the numeric class or monospace stack so columns align without extra grid noise.

## 4. Elevation

Soapkraft uses a hybrid of tonal layering and soft warm elevation. In dense product surfaces, background shifts and thin structural lines help tables and form groups stay legible. Primary cards use warm umber shadows when they need to lift from the surface.

### Shadow Vocabulary

- **Public Card Elevation** (`0 2px 4px rgba(60, 50, 30, 0.04), 0 12px 24px rgba(60, 50, 30, 0.08)`): marketing cards and lifted public preview panels.
- **App Card Elevation** (`0 1px 2px rgba(60, 50, 30, 0.04), 0 12px 24px rgba(60, 50, 30, 0.07)`): authenticated product cards.
- **Shell Lines** (`0 1px 0 rgba(60, 50, 30, 0.08)`): public navigation and footer separation.

### Named Rules

**The Structural Lines Rule.** Borders are allowed for tables, inputs, app shell separation, and dense grouped rows. Decorative colored side stripes are forbidden.

**The Warm Shadow Rule.** Shadows use warm umber values, never pure black. If the shadow reads as dramatic, it is too heavy for the product.

## 5. Components

### Buttons

- **Shape:** Compact rounded rectangle or pill depending on context. App primitive uses 8px; public CTAs may use pills.
- **Primary:** Living Green background with inverse text. Use for the main action in a local workflow.
- **Hover / Focus:** Hover deepens to Living Green Hover. Focus uses a 2px Living Green outline with 2px offset.
- **Secondary / Ghost / Tertiary:** Ghost actions use soft neutral text and panel hover. Outline actions use the line token and neutral text.

### Chips

- **Style:** Small rounded chips use either soft semantic backgrounds or a line token with neutral text.
- **State:** Active chips use soft green with strong green text. Neutral category chips should not look like CTAs.

### Cards / Containers

- **Corner Style:** App cards use a 14px radius. Inset zones and fields use 8px. Large modal or hero objects may use 20px or more only when the scale earns it.
- **Background:** Primary cards use Clean Panel. Insets use Recessed Stone or mixed panel/surface backgrounds.
- **Shadow Strategy:** Cards may use App Card Elevation or Public Card Elevation. Rows, badges, and most inputs stay flat.
- **Border:** Product cards may carry transparent or structural borders. Tables and dense rows may use line tokens for legibility.
- **Internal Padding:** Product cards usually use 20px to 24px. Dense rows use 12px to 16px.

### Inputs / Fields

- **Style:** Field Paper background, 8px radius, soft line or outline, 14px text.
- **Focus:** 2px Living Green outline. Focus must be visible against warm surfaces.
- **Error / Disabled:** Error uses Danger Red soft and strong tokens. Disabled fields use Field Muted and reduced opacity.

### Navigation

Navigation is familiar and work-focused. The authenticated app uses a left sidebar with 17rem width on desktop, collapsible behavior on smaller screens, 8px nav item radius, and soft green active states. The public nav is fixed, warm, lightly translucent, and uses simple text links plus one calculator CTA.

### Signature Component: Formula Workbench

The workbench combines an ingredient browser, formula tables, diagnostics, quality bars, and save controls. It should stay dense, scannable, and predictable. Ingredient rows, percentages, weights, totals, lye, water, costing, and quality values must keep numeric alignment. Progressive disclosure is allowed for compliance and advanced diagnostics, but the core soap calculation remains visible.

## 6. Do's and Don'ts

### Do:

- **Do** use the tokens in `resources/css/shared/soapkraft.css` as the visual source of truth.
- **Do** use Living Green for primary action, active state, and focus only.
- **Do** use Chemistry Amber for lye, warning, and chemistry signals.
- **Do** use `numeric` or the numeric font stack for weights, percentages, costs, lye, water, and quality values.
- **Do** keep workbench surfaces compact, structured, and easy to scan.
- **Do** keep compliance visible but calm, especially for users using Soapkraft as a calculator or recipe portfolio.
- **Do** preserve keyboard focus, reduced-motion behavior, and mobile/tablet usability.

### Don't:

- **Don't** make Soapkraft look like an Etsy craft template.
- **Don't** use generic SaaS card grids as the default landing or dashboard answer.
- **Don't** use flashy AI gradients, gradient text, or neon dark lab sci-fi styling.
- **Don't** use decorative handmade soap blog motifs, blobs, bubbles, leaf patterns, or soap textures as UI decoration.
- **Don't** use colored left-border or right-border stripes on cards, rows, callouts, or alerts.
- **Don't** use amber, blue, red, or teal for decoration. They must communicate state.
- **Don't** make compliance visually dominate ordinary calculator and recipe work.
- **Don't** imply the product replaces professional regulatory review where that review is required.
