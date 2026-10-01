# Workbench Drag and Drop Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task in this session. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Make ingredient dragging predictable and visually clear in the soap and cosmetic workbenches, and correct the cosmetic drop hint's border and alignment.

**Architecture:** Keep the existing native drag mechanism and central `moveFormulaRow()` mutation. Resolve every hovered row to an insertion boundary, render that boundary consistently through shared CSS, and retain the row menu as the keyboard and touch alternative. Scope the styling to workbench row controls and drop targets.

**Tech Stack:** Laravel, Blade, Alpine.js, Tailwind CSS 4.2.2, Pest with the existing Node-backed workbench test harness.

**Execution constraints:** Stay on `main`; do not run a frontend build or start a server. Preserve the already approved white cosmetic data rows and unrelated local changes. The user approved implementation; changes are complete and remain uncommitted on main. Do not commit or push as part of this plan.

## Initial audit evidence and decisions

- Inspected the signed-in cosmetic and soap Formula pages and their rendered DOM. The cosmetic ingredient row has a 1px bottom border from `divide-y`, directly followed by the hint's explicit 1px top border. Together these form the heavier separator.
- The hint begins at the phase's left padding, beneath the handle. Align it with the ingredient name instead: column two on desktop, the ingredient name's normal inset on mobile. Use italic, regular-weight text at the existing readable size. Keep the hint visible at rest for discoverability.
- Both benches render the dotted control icons at 16px with a 1.7-unit stroke; their individual dots are roughly 1.1 CSS pixels wide. The menu icon already inherits dark `ink`; stronger dot geometry is necessary. The drag button also shrinks to 28px wide inside the padded 44px handle column.
- Source inspection shows that only the last row distinguishes the pointer's upper and lower halves. Other rows always insert before the target. Appending can highlight the last row and the trailing hint simultaneously. Invalid destinations do not clear the previous target. Both the row handler and the window handler invoke scrolling.
- Native dragging starts on the grip button and does not set a row drag image. Use the rendered row as the preview so the ingredient remains identifiable.
- No actual ingredient was dropped during this audit. Preview quality, scrolling feel, and cancellation require interaction verification after implementation.

## Current approved decisions

The user refined the initial implementation after reviewing it in the browser. These decisions supersede the original visual choices and are reflected in the tasks below:

- Dragged rows use **0.75 opacity**, with white cosmetic cells at rest.
- Filled control icons use a fixed 20px SVG canvas scaled to **16px at rest**, in `ink-soft`; hover, keyboard focus and menu-open states render at **20px**, in `ink-strong`. A 150ms quart transition changes only color and transform. Hover and open states have no background rectangle; hit areas remain 40–44px and keyboard focus remains visible.
- The exact 2px insertion line uses **75% accent / 25% white**.
- Only a successful native row move settles for **260ms** with quart easing, `translateY(-4px)` to zero and opacity 0.75 to 1. No background highlight is added. Reduced motion, no-op/rejected drops and menu moves skip it; a new drag or component teardown cancels queued or running settling.
- Window blur pauses scrolling via `stopRowDragScroll()` while preserving the drag source. Drop and dragend cleanup listeners remain in the bubbling phase so row placement runs first.
- All drop-zone CSS selectors, including desktop and reduced-motion rules, are scoped under `.sk-workbench`. Soap empty-state copy retains its existing typography; it shares the active fill without adopting the cosmetic hint layout.

## Files and responsibilities

| File | Responsibility |
| --- | --- |
| `resources/css/app.css` | Scoped hint alignment, light drop tint, insertion indicators, row control dimensions and focus |
| `resources/views/components/action-icon.blade.php` | Optional prominent dotted geometry, retaining other icons' defaults |
| `resources/views/components/recipe-workbench/formula-row-actions.blade.php` | Shared menu trigger treatment |
| `resources/views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php` | Cosmetic hints, row indicators and drag controls |
| `resources/views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php` | Soap oil indicators and drag controls |
| `resources/views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php` | Soap additive and fragrance indicators and controls |
| `resources/views/livewire/dashboard/recipe-workbench.blade.php` | Window-level drag scrolling and cleanup |
| `resources/js/recipe-workbench/component.js` | Insertion resolution, target cleanup, preview and scroll lifecycle |
| `tests/Feature/RecipeWorkbenchPersistenceTest.php` | Behavioral regression coverage using the existing Node harness |
| `tests/Feature/RecipeWorkbenchDesignPolishTest.php` | Update existing markup contracts affected by the revised bindings |
| `tests/Feature/CosmeticRecipeWorkbenchTest.php` | Update existing cosmetic rendering expectations where necessary |

## Task 1: Cosmetic drop hint and shared target appearance

- [x] Remove `border-t border-[var(--color-line)]` from the populated cosmetic hint; let the preceding row own the single separator.
- [x] Wrap the translated hint text in a span and give both empty and populated cosmetic hints `sk-formula-drop-zone`. Keep existing dragover/drop bindings and separate vertical spacing. Replace whole-surface green classes with `sk-formula-drop-zone-active`.
- [x] Add the following shared CSS within the workbench styling section. Preserve the data rows' established vertical padding rules.

```css
.sk-workbench {
    --workbench-drop-fill: color-mix(in oklab, var(--color-active-soft) 30%, white);
}

.sk-workbench .sk-formula-drop-zone {
    padding-inline: 0.625rem;
    color: var(--color-ink-soft);
    font-style: italic;
    font-weight: 400;
    text-align: start;
    transition: background-color 150ms;
}

.sk-workbench .sk-formula-drop-zone-active {
    background-color: var(--workbench-drop-fill);
    color: var(--color-active-strong);
}

@media (min-width: 64rem) {
    .sk-workbench .sk-formula-drop-zone {
        display: grid;
        grid-template-columns: 2.75rem minmax(0, 1fr);
        column-gap: 1px;
        padding-inline: 0;
    }

    .sk-workbench .sk-formula-drop-zone > span {
        grid-column: 2;
        padding-inline: 1rem;
    }
}

@media (prefers-reduced-motion: reduce) {
    .sk-workbench .sk-formula-drop-zone {
        transition: none;
    }
}
```

- [x] Use the same light fill for existing soap empty-phase targets. Keep ordinary data cells white. Do not repaint their white children or tint numerical inputs.
- [x] Verify one separator and correct text alignment in the browser at desktop and mobile widths. Compare empty and populated phases. Use computed borders and bounding rectangles, rather than an implementation-mirroring test for CSS.

## Task 2: Grip and menu visibility

- [x] Add a boolean `prominent` prop with default `false` to `action-icon`. For prominent `drag` and `more-horizontal` icons, use filled circles instead of almost-zero-length paths. The prominent geometry is:

```html
<!-- drag -->
<g fill="currentColor" stroke="none">
    <circle cx="8" cy="6" r="1.5" /><circle cx="16" cy="6" r="1.5" />
    <circle cx="8" cy="12" r="1.5" /><circle cx="16" cy="12" r="1.5" />
    <circle cx="8" cy="18" r="1.5" /><circle cx="16" cy="18" r="1.5" />
</g>
<!-- more-horizontal -->
<g fill="currentColor" stroke="none">
    <circle cx="6" cy="12" r="1.5" />
    <circle cx="12" cy="12" r="1.5" />
    <circle cx="18" cy="12" r="1.5" />
</g>
```

- [x] Set `:prominent="true"` on the four formula grip usages and the shared row-menu icon. Leave all other icon usages unchanged.
- [x] Give those buttons `sk-formula-row-control`, use `ink-soft` at rest and `ink-strong` on hover, keyboard focus and menu-open state. Keep a fixed 20px SVG canvas scaled to 0.8 at rest and 1 when interactive, with a 150ms quart transition. Remove hover/open background rectangles. Retain accessible labels, 40–44px hit areas, visible focus and the menu's existing focus management; disable transitions for reduced motion.
- [x] Remove horizontal padding from the grip/action cells so it cannot shrink the buttons. Preserve mobile zero-padding; provide 44px desktop control tracks by changing the action track from `2.5rem` to `2.75rem` in the affected headers, rows and totals. Do not change ingredient or amount track sizing.
- [x] Scope a 2px accent focus outline to these controls, after the current workbench focus override, with sufficient specificity to remain visible. Keep hit areas at least 40px for the grip and 44px for the menu without increasing data-row vertical spacing.
- [x] Update existing rendered-icon/markup assertions for the explicit prominent prop. Verify rest, hover, focus and open-menu states on both Formula pages; check that icons have no disabled-state opacity.

## Task 3: Predictable insertion boundaries

- [x] Extend the existing cosmetic drag test in `RecipeWorkbenchPersistenceTest.php` with three target rows. Before changing production logic, add these Node assertions inside its established workbench harness:

```js
workbench.phaseItems.phase_b = [
    { id: 'b1', ingredient_id: 101 },
    { id: 'b2', ingredient_id: 102 },
    { id: 'b3', ingredient_id: 103 },
];
const targetEvent = (clientY) => ({
    clientY,
    currentTarget: { getBoundingClientRect: () => ({ top: 200, height: 40 }) },
});
assert.strictEqual(workbench.resolvedDropTargetRowId('phase_b', targetEvent(210), 'b1'), 'b1');
assert.strictEqual(workbench.resolvedDropTargetRowId('phase_b', targetEvent(230), 'b1'), 'b2');
assert.strictEqual(workbench.resolvedDropTargetRowId('phase_b', targetEvent(230), 'b2'), 'b3');
assert.strictEqual(workbench.resolvedDropTargetRowId('phase_b', targetEvent(230), 'b3'), null);
```

- [x] Run the affected drag test and confirm the lower-half non-final-row assertions fail. Replace the resolver with:

```js
resolvedDropTargetRowId(phaseKey, event, targetRowId = null) {
    if (targetRowId === null) {
        return null;
    }

    const rows = this.phaseItems[phaseKey] ?? [];
    const targetIndex = rows.findIndex((row) => row.id === targetRowId);
    const rect = event?.currentTarget?.getBoundingClientRect?.();

    if (targetIndex === -1 || !rect || typeof event?.clientY !== 'number') {
        return targetRowId;
    }

    return event.clientY >= rect.top + (rect.height / 2)
        ? rows[targetIndex + 1]?.id ?? null
        : targetRowId;
},
```

- [x] Keep the existing post-removal index calculation and `moveFormulaRow()` guards. Expand behavioral assertions to cover upward/downward moves, adjacent and self no-ops, cross-phase insertion, empty targets and append. Assert row data and amounts are preserved and rejected soap/duplicate moves leave both lists unchanged.
- [x] Make `isDropTarget()` an exact phase-and-boundary match; remove its last-row fallback. For each rendered row, show a 2px line mixed from 75% accent and 25% white at the resolved boundary using an absolutely positioned pseudo-element, so it does not alter row height. At phase end, show the line at the cosmetic trailing hint's top, or the soap final row's bottom. Render only one line per boundary.
- [x] Keep the source row identifiable with 0.75 opacity during dragging. After a successful native move, settle only the moved row for 260ms with quart easing, a 4px downward settle and opacity 0.75 to 1; add no background highlight. Skip reduced motion, no-op/rejected drops and menu moves, and cancel queued/running feedback on a new drag or teardown. The insertion line communicates the exact position independently of the light drop-zone fill.

## Task 4: Cleanup, row preview and scrolling

- [x] Add `clearRowDropTarget()` to clear only `dropTargetPhaseKey` and `dropTargetRowId`. Start a drag with no selected destination. Call the helper for invalid dragover targets and guarded dragleave events. Ignore dragleave events whose `relatedTarget` remains inside the current target; child controls must not cause flicker.
- [x] Extend the existing Node tests: select a valid target, then visit a duplicate or forbidden destination and assert both target fields are null and `dropEffect` is `none`. End/cancel a drag and assert all drag fields reset without mutating row arrays.
- [x] In `beginRowDrag()`, locate the rendered row with `event.currentTarget.closest('[data-workbench-row-id]')`; when `setDragImage` is available, pass that row and pointer offsets clamped to its bounds. Add the same row attribute to soap rows if absent. Preserve the text/plain payload and `effectAllowed = 'move'`.
- [x] Remove the scroll call from `allowPhaseDrop()`. Keep a single window `dragover` entry point. Replace event-sized scroll steps with one cancellable animation-frame loop using the latest pointer Y. Use the existing 72–120px edge band, a maximum speed of 600px/second, elapsed time capped at 32ms, and stop when the pointer leaves the band.
- [x] Store the pending frame ID and last timestamp in per-workbench runtime state. Cancel the frame on `endRowDrag()`, external drop, dragend and pointer exit from the document. Bind window blur to `stopRowDragScroll()` so it cancels the frame without discarding the source row. Keep `@drop.window` and `@dragend.window` in the bubbling phase, without capture, so row placement runs before global cleanup. Cleanup must not prevent unrelated drops or native browser actions.
- [x] Update the existing scroll test to stub `requestAnimationFrame` and `cancelAnimationFrame`. Assert that repeated dragover events schedule only one frame, middle-of-viewport movement produces no scroll, top/bottom directions are correct, and end/cancel stops subsequent frames. Keep the old guard that idle workbenches never scroll. Exercise the actual Blade blur handler: it must cancel the pending frame, invalidate stale callbacks, preserve row data and source identity, and permit a subsequent drop. Pin bubbling window cleanup with a markup contract assertion.
- [x] Keep existing menu moves as the keyboard/touch fallback. Verify single-open menus, endpoint disabling and focus return. Do not add a drag library or promise catalogue dragging; the existing catalogue uses its add controls.

## Task 5: Focused verification and review

- [x] Run the Node-backed feature coverage after each behavioral change with a focused filter, then the three affected files together:

```sh
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
vendor/bin/pint --dirty --format agent
git diff --check
graphify update .
```

Expected: all affected tests pass, Pint and diff checks pass, and the graph refresh completes. If formatting changes test PHP, rerun the affected tests afterward. No build command is included.

- [x] Use the existing local development asset workflow for browser verification. If the JS/CSS source changes are not reflected, report that verification limit and respect the no-build request.
- [x] Verify cosmetic and soap desktop rows plus narrow layouts: the hint aligns with the ingredient name, separators are single, grips/menu buttons are visible, and the insertion line agrees with the eventual order. Verify a drag cancellation and an invalid target leave no stale line or continued scroll.
- [x] Exercise actual moves in test-owned unsaved draft state without saving the user's sample recipes: cosmetic reordering, cosmetic phase transfer and soap oil reordering passed. Amounts were preserved and drag state reset. Preview setup, edge scrolling, cancellation and rejected moves passed automated tests; native preview appearance, slow/fast pointer movement and edge-scroll feel were not fully observed with browser automation. Reduced-motion styling was inspected in source; menu focus and Escape return were verified in the browser.
- [x] Save before/after screenshots, report completed changes and any browser-verification limit, and ask the user to run the complete suite with `php artisan test --compact` after the focused tests pass.

## Self-review

All five user requests are covered: Task 1 fixes border/alignment/italics and the green fill; Task 2 improves both control types in both benches; Tasks 3–4 address the drag interaction; Task 5 verifies behavior without building. Main and existing white-row changes are explicit constraints. The plan preserves numerical precision, formula mutation guards, shared row spacing, addition feedback and existing menu focus behavior.

## Execution result

- Sol 6.1 implemented the scoped visual and drag changes; specification and quality reviews passed.
- Cosmetic rows remain `#FFFFFF` at rest; all four draggable row types use `opacity-75` during dragging.
- Desktop and narrow-layout DOM checks confirmed hint alignment, a single separator, 16px icons at rest, 20px icons on hover/focus/open and 40–44px controls, with transparent hover/open surfaces. Native drop settling was observed in the browser and returned to full opacity and position.
- Affected test files: **191 passed, 20 skipped, 2,282 assertions** after the review follow-up. After temporary diagnostics were removed, the four narrow drag tests passed again (19 assertions). Pint and diff checks passed. No build was run.
- Browser verification used new unsaved formulas. Temporary diagnostics were removed. Refinement screenshot: `/private/tmp/koskalk-surface-audit/11-cosmetic-drag-refinements.jpg`. The user confirmed the full suite passed before this review follow-up.

## Review follow-up

- Changed window blur to pause the scroll loop while retaining the drag source; the regression failed with the old binding, then passed with the fix. It checks stale-frame invalidation, resuming scrolling, subsequent placement and preserved amounts.
- Added a markup contract for bubbling window drop/dragend cleanup and scroll-only blur. Row handlers continue to own their final cleanup.
- Scoped every drop-zone selector to the workbench. Fresh browser checks confirmed the hint and ingredient header text both begin at x=365px on desktop; mobile retains italic text with a 10px inset and no horizontal overflow.
- Kept the user-approved 0.75 opacity, 16px-to-20px soft-to-strong controls, transparent hover/open surfaces and 260ms drop settling. These supersede the initial implementation choices.
- Pint and diff checks passed. No build, commit or push was performed.
