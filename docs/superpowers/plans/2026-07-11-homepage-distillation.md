# Homepage Distillation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the homepage explain Soapkraft immediately, distinguish the anonymous soap calculator from the registered formulation workspace, and remove repeated persuasion while preserving the existing visual identity.

**Architecture:** Keep the homepage as version-controlled Blade and Tailwind. Replace the current seven-section narrative with a literal hero, one product-proof section, a compact credibility band, and a final action. Make public navigation authentication-aware without adding a CMS or page builder.

**Tech Stack:** Laravel 13, Blade, Tailwind CSS 4, Pest 4, Laravel Herd.

---

### Task 1: Define the homepage contract

**Files:**
- Modify: `tests/Feature/PublicShellPagesTest.php`

- [x] Add guest assertions for the literal product heading, free-account CTA, anonymous soap-calculator CTA, and sign-in path.
- [x] Add authenticated assertions for the Open workspace path.
- [x] Assert that old repeated section headings and the unavailable free-plan batch promise are absent.
- [x] Run `php artisan test --compact tests/Feature/PublicShellPagesTest.php` and confirm the new assertions fail for the old homepage.

### Task 2: Distill homepage content and navigation

**Files:**
- Modify: `resources/views/welcome.blade.php`
- Modify: `resources/views/layouts/public.blade.php`

- [x] Replace the abstract hero heading with a literal product category and factual supporting copy.
- [x] Render accurate guest and authenticated actions.
- [x] Remove the repeated Benefits, Two Ways In, Comparison, and Workflow sections.
- [x] Add one compact product-proof section covering complete soap formulas, multiphase cosmetics, formula portfolio content, and costing.
- [x] Keep a concise, verified credibility band and final action.
- [x] Remove page-load animation from essential hero content and reduce hero height, with a compact full product visual on mobile.
- [x] Run the focused feature test until it passes.

### Task 3: Verify responsive behavior and quality

**Files:**
- Verify: `resources/views/welcome.blade.php`
- Verify: `resources/views/layouts/public.blade.php`

- [x] Run `npm run build`.
- [x] Inspect `http://koskalk.test/` at 1440x900 and 390x844.
- [x] Confirm no horizontal overflow, both entry paths, visible next-section content, and a substantially shorter page.
- [x] Run `php artisan test --compact tests/Feature/PublicShellPagesTest.php` and `git diff --check`.
- [x] Run `graphify update .`.
