# Contextual Help Rollout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver localized contextual help throughout Soapkraft while keeping long-form documentation in WordPress and preserving the density and state of every workbench.

**Architecture:** One shared, non-modal desktop panel and modal mobile sheet lives in the authenticated application shell. Pages register a server-resolved subset of stable topic keys, while direct and page-level triggers open that content client-side without a Livewire or WordPress request. Domain plans add English topics, reviewed database translations, precise WordPress links, and surface-specific triggers on top of the shared foundation.

**Tech Stack:** Laravel 13, Blade components, Livewire 4, Alpine.js, Tailwind CSS 4, Spatie Translation Loader, Filament 5, Pest 4, Vite 8, WordPress.

---

## Plan boundaries

Execute these plans in order. Each plan ends in working, tested software and has its own commit sequence.

1. [Contextual Help Foundation](2026-08-21-contextual-help-foundation.md)
   - Topic schema and validation
   - Locale resolution with English content fallback
   - No fallback for localized WordPress URLs
   - Shared panel, trigger, registry, JavaScript, responsive behavior, and accessibility
2. [Soap and Cosmetic Workbench Contextual Help](2026-08-21-workbench-contextual-help.md)
   - Shared workbench concepts
   - Soap-specific concepts and results
   - Cosmetic-specific concepts and results
   - Matching WordPress guides and translations
3. [Production Planning and Execution Contextual Help](2026-08-21-production-planning-execution-contextual-help.md)
   - Drafting, scheduling, flash planning, dates, batch sizing, and reservations
   - Stock preparation, allocation, starting, actuals, completion, aborting, and release
4. [Production Inventory, Purchasing, and Setup Contextual Help](2026-08-21-production-inventory-purchasing-setup-contextual-help.md)
   - Inventory quantities, lots, adjustments, expiry, and manufactured output
   - Suppliers, listings, procurement, orders, receipts, conversion, and costing
   - Numbering, presets, task organization, and calendars
5. [Remaining Contextual Help and WordPress Editorial Workflow](2026-08-21-contextual-help-remaining-surfaces.md)
   - Ingredients, compliance, settings, and remaining shared workbench topics
   - Ongoing English-change review, translation review, and WordPress publishing gates

## Release gates

- [ ] Foundation is merged before any surface plan begins.
- [ ] Soap and Cosmetic Workbench plans may ship together after both specialized topic sets pass separation tests.
- [ ] Production planning and execution are included in the first release program and may ship immediately after the workbench slice.
- [ ] Production inventory, purchasing, and setup follow as the next bounded production slice.
- [ ] A locale remains inactive until its application topics and corresponding published WordPress guides are reviewed.
- [ ] Missing localized WordPress articles hide the guide link; they never block concise in-app help.
- [ ] Every surface keeps safety, compliance, confirmation, and irreversible-action warnings inline.

## Shared verification after every plan

Run the narrow tests named by that plan, then run:

```bash
php artisan test --compact tests/Feature/ContextualHelpContentTest.php tests/Feature/ContextualHelpRenderingTest.php tests/Unit/ContextualHelpInteractionTest.php
npm run build
vendor/bin/pint --dirty --format agent
git diff --check
```

Expected: all selected tests pass, Vite builds successfully, Pint completes without an error, and `git diff --check` prints nothing.

When a plan modifies `app/Filament`, also run:

```bash
vendor/bin/filacheck --fix
```

Expected: no unresolved Filament compatibility issues.

After any code plan completes, refresh the repository graph:

```bash
graphify update .
```

Expected: `graphify-out/GRAPH_REPORT.md` and the graph data reflect the implemented files.
