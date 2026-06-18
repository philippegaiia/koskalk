# Home Page Redesign Spec

**Date:** 2026-04-11
**Status:** Approved

---

## 1. Goal

Redesign the homepage to drive **trial sign-ups**. The page should show visitors their recipe portfolio immediately, remove friction to getting started, and lead with concrete benefits (not abstract chemistry concepts).

---

## 2. Page Structure

### Section 1: Hero
- **Headline:** "Your soap recipes. Precise. Organized. Ready to share."
- **Subline:** "Build your recipe portfolio with precise calculations, INCI labels, and allergen compliance built in."
- **Primary CTA:** "Start free" (white button, prominent)
- **Secondary CTA:** "See an example" (ghost button, scrolls to preview)
- **Visual:** Recipe workspace image (placeholder for now)

### Section 2: Value Pillars (4 cards)
| Card | Title | Body |
|------|-------|------|
| 1 | Your recipe portfolio | Store recipes with photos, notes, and version history — all in one place |
| 2 | Precise calculations | SAP values, lye calculations, and costings you can trust |
| 3 | Compliance included | Allergen summaries and IFRA compliance for every recipe |
| 4 | Share with makers | Share ingredients and recipes with other Koskalk members |

### Section 3: Recipe Preview
- **Title:** "Built for soapmakers"
- **Visual:** Screenshot of recipe workbench (placeholder)
- **CTA:** "Try the workbench →"

### Section 4: Closing CTA
- **Headline:** "Start building your portfolio today."
- **CTA:** "Create free account"
- **Microcopy:** "15 recipes and 20 private ingredients included. No credit card required."

### Section 5: Pricing
- Do not show public pricing tiers for v1.
- Billing is deferred for launch. Paid plans may be introduced later after product validation.

---

## 3. Design System

Follow existing design tokens from `design.md`:
- Use `var(--color-*)` tokens
- Card shadow, rounded-xl for cards
- Section labels use `<p>` with metadata style
- Font-mono for numeric values

---

## 4. Images

All images use styled placeholder divs with descriptive labels — easy to swap later.

---

## 5. Out of Scope

- Pricing page (separate)
- Payment checkout
- Authentication flow
- Dashboard changes
