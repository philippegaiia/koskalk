# Production Bench Delivery Roadmap

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the approved Production Bench as six independently testable checkpoints while preserving the current Basic production experience.

**Architecture:** The approved [Production Bench design](../specs/2026-07-28-production-bench-design.md) remains the durable product contract. Each checkpoint receives its own detailed TDD implementation plan immediately before execution, using the code and schema produced by the previous checkpoint rather than repeatedly re-analyzing the complete feature.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Blade, Alpine.js, Tailwind CSS 4, Pest 4, Laravel database transactions and row locking, existing private media library.

---

## Working Method

- Keep this roadmap and the approved design as the persistent source of truth.
- Execute in one long-lived `codex/production-bench` branch or worktree.
- Continue in the same Codex task unless the user explicitly asks for a handoff.
- Create one detailed implementation plan per checkpoint with exact files, tests, commands, and commit boundaries.
- Use test-driven development for every domain invariant and mutation.
- Commit small, coherent changes within each checkpoint.
- Run the checkpoint's focused tests, formatter, frontend build when applicable, and graph refresh before review.
- Stop at the review gates below. Do not reopen already approved design decisions unless implementation exposes a concrete contradiction.
- Record any approved design correction in the specification before continuing.

## Review Rhythm

The user reviews working software at four useful moments rather than after every small commit:

1. mass conversion in the Recipe/Product Bench;
2. supplier listing, receipt, document, and opening-stock experience;
3. stock truth, scheduling, and reservation experience;
4. complete production execution, traceability, Flash planning, and add-on behavior.

Technical commits continue between these gates, but implementation does not pause for routine internal refactors that preserve the approved design.

## Checkpoint 0: Mass Foundation

**Detailed plan:** `docs/superpowers/plans/2026-07-28-production-bench-phase-0-mass-foundation.md`

**Outcome:** Recipe/Product Bench stores and calculates a canonical mass while converting g/kg/oz/lb correctly for entry and display.

**Scope:**

- central mass-unit value object/enum and exact converter;
- canonical mass persistence strategy for formula versions and workbench payloads;
- workspace preferred mass display;
- formula-version display preference;
- convert-on-unit-change behavior in the workbench;
- backwards compatibility for existing formula versions and Basic production snapshots;
- regression tests for calculation, costing, saving, restoring, and printing.

**Review evidence:**

- changing `1 kg` to pounds displays approximately `2.20462 lb` without changing the formula;
- changing back restores `1 kg`;
- saved and restored formulas retain canonical quantities;
- existing g/oz/lb formulas remain numerically equivalent.

**Gate:** User validates the corrected unit interaction in Recipe/Product Bench.

## Checkpoint 1: Production Bench Shell and Stock Ledger

**Detailed plan:** `docs/superpowers/plans/2026-07-28-production-bench-phase-1-stock-foundation.md`

**Outcome:** Entitled workspaces can enter opening lots and see trustworthy physical, quarantined, reserved, available, incoming, and forecast quantities.

**Scope:**

- workspace Production Bench entitlement;
- customer-facing Blade/Livewire route group and navigation shell;
- stock lots with unique internal lot codes and optional supplier batch references;
- immutable stock movements;
- stock-position query/projection;
- opening ingredient lots and packaging balances;
- quarantine/release primitives;
- typed private document attachments;
- cancellation read-only enforcement foundation.

**Review evidence:**

- a workspace can activate Production Bench;
- a maker can enter opening ingredient and packaging stock;
- stock cards reconcile exactly to movements;
- unknown opening provenance is visible;
- disabling the entitlement makes the new area read-only without affecting Recipe/Product Bench.

## Checkpoint 2: Suppliers, Listings, Orders, and Receipts

**Detailed plan:** `docs/superpowers/plans/2026-07-28-production-bench-phase-2-purchasing.md`

**Outcome:** Makers can order supplier pack multiples, partially receive them into internal lots, and preserve supplier documents and batch references.

**Scope:**

- suppliers;
- multiple direct supplier listings per existing ingredient or packaging item;
- commercial pack description plus canonical mass/count per pack;
- purchase-order lifecycle;
- snapshotted purchase-order lines;
- partial goods receipts;
- distinct internal lots across deliveries sharing one supplier batch number;
- actual received mass/count;
- receipt, invoice, delivery-note, CoA, SDS, specification, and photo attachments;
- historical received-lot unit costs.

**Review evidence:**

- one olive-oil ingredient can have several pack/UOM listings;
- ordering three supplier packs calculates the expected total;
- a partial receipt posts only the actual received mass;
- two deliveries may share a supplier batch while retaining distinct internal lots and costs;
- documents remain private and attached to the correct receipt or lot.

**Gate:** User validates the supplier-listing, order, receipt, and document UX together with opening stock.

## Checkpoint 3: Forecasting, Scheduling, and Reservations

**Detailed plan:** `docs/superpowers/plans/2026-07-28-production-bench-phase-3-planning-reservations.md`

**Outcome:** Makers can schedule production far in advance without reserving stock, then explicitly create safe lot-specific reservations later.

**Scope:**

- `ProductionRun` draft and scheduled states;
- immutable published-formula handoff;
- soap scaling from initial oil mass;
- cosmetic scaling from total formula mass;
- material and optional packaging requirements;
- forecast demand;
- explicit FEFO lot proposals;
- manual lot selection;
- hard reservations;
- shortages and reservation conflicts;
- cancellation and reservation release;
- transactional concurrency protection.

**Review evidence:**

- scheduled runs affect forecast demand but not available stock;
- reserving later reduces available but not physical stock;
- over-reservation is blocked;
- two concurrent attempts cannot reserve the same availability;
- cancelling releases reservations.

**Gate:** User validates the stock-truth cards, scheduling flow, shortage display, and reservation interaction.

## Checkpoint 4: Production Execution, Output, and Actual Cost

**Detailed plan:** `docs/superpowers/plans/2026-07-28-production-bench-phase-4-execution-costing.md`

**Outcome:** A reserved run can be started, completed, costed, quarantined, released, and preserved in Basic production history.

**Scope:**

- start and in-production lifecycle;
- actual lot consumption;
- controlled negative stock when actual consumption exceeds reservation;
- compensating stock adjustments;
- abort reconciliation;
- finished output in integer units;
- in-house intermediate output in canonical mass;
- actual received-lot cost consumption;
- downstream intermediate cost propagation;
- atomic completion;
- linked Basic `ProductionBatch` snapshot;
- output quarantine, curing/availability date, and release;
- finished-goods issue movements;
- rich production journal and attachments.

**Review evidence:**

- completion posts consumption, releases unused reservations, creates one output lot, and creates one Basic snapshot atomically;
- actual over-consumption produces a visible negative balance;
- adjustment reconciles it without changing history;
- completed costs remain unchanged after catalog-price updates;
- intermediate cost flows into a downstream batch;
- released output becomes available while quarantined output does not.

## Checkpoint 5: Traceability, Flash Planner, and Lifecycle Completion

**Detailed plan:** `docs/superpowers/plans/2026-07-28-production-bench-phase-5-traceability-flash.md`

**Outcome:** Production Bench delivers its full professional V1 value and can be piloted with selected customers.

**Scope:**

- backward and forward lot genealogy;
- search by internal lot, supplier batch, production run, ingredient, product, order, or receipt;
- disposable multi-run Flash Planner;
- total material requirements;
- current stock and shortage calculation;
- total material value and shortage cash estimate using indicative prices;
- missing-price visibility;
- print/export;
- full add-on cancellation, read-only access, resumption, and archive-eligibility metadata;
- responsive and accessibility polish;
- final regression and performance tests.

**Review evidence:**

- a finished lot traces to supplier receipts through any intermediate;
- a supplier batch traces forward across several deliveries and products;
- several hypothetical runs aggregate requirements and indicative cash needs without changing stock;
- cancelling and resuming the add-on preserves all state;
- critical workflows remain usable at tablet widths.

**Gate:** User performs the final V1 product review before pilot enablement.

## Completion Criteria

- Every checkpoint's detailed plan is committed before its implementation starts.
- Every checkpoint ends with focused tests and a clean worktree.
- PHP changes are formatted with `vendor/bin/pint --dirty --format agent`.
- Filament changes, if any are limited to internal support tooling, pass `vendor/bin/filacheck --fix`.
- Modified code is reflected with `graphify update .`.
- The final implementation satisfies the success criteria in the approved design.
- No excluded ERP functionality is introduced speculatively.

## Recommended Execution Mode

Use **Inline Execution** with `superpowers:executing-plans` in this Codex task. This best preserves context and provides the requested review checkpoints.

Subagent-driven execution remains available for a later checkpoint only if the user explicitly requests parallel agents.
