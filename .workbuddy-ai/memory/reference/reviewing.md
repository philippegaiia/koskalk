# Reviewing: habits that prevent wrong calls

Split out of `MEMORY.md` 2026-09-08 to keep that file under the injection limit. Read before any
audit, spec review, or findings write-up.

## Reading the evidence

- **`.ai/rules/index.md` maps path globs → rule files — read it before auditing any file.** Skipping
  it produced a false finding: I called the hidden `is_soap_saponification_trusted` flag a defect
  when `dashboard.md` mandates it. A rule file can also hand you the *remedy* for another finding.
- **Measure the owner's own compliance before filing a rule violation** — `livewire.md` says enforce
  with `authorize()`, but all 12 calls sit in one component while 4 others use `abort(403)`.
- **The spec is the authority on intent, not the test.** Specs `docs/superpowers/specs/`, plans
  `docs/superpowers/plans/`. Read the governing spec before reporting a behavioural finding; check
  drift both ways. Spec wins on intent; an explicit deliberate plan detail wins on implementation —
  flag divergence, don't file as a defect.
- **An interface assertion is not a usage assertion.** `implements HasForms` says nothing about
  whether the Blade renders `{{ $this->filtersForm }}`. Read the view for view findings.
- **Check the pre-branch version before calling something absent** — `git show <base>:<path>`
  separates "never had it" (gap) from "branch removed it" (regression, much worse).
- **Check whether the guarded thing feeds the guard's own predicate** — self-referential
  preconditions deadlock (e.g. `tracks()` derived from the settings a buffer writes).

## Writing it up

- **State retractions plainly, per-clause** — never quietly drop a finding, and split compound rules
  before ruling on them.
- **When amending a findings doc, re-check its summary/proposal section too.** Mine kept the
  superseded advice and contradicted the new sections in 4 places. State in the header which section
  governs. **Report self-corrections accurately** — I twice overstated what I had got wrong.
- **Name the condition the code evaluates, never a role label.** "A Viewer sees X" is a claim about
  an actor *and* about reachability. `WorkspaceMemberRole::Viewer` is declared in
  `app/Enums/WorkspaceMemberRole.php:10` and referenced **nowhere**; the only code creating a
  membership row assigns `Owner` (`SettingsIndex.php:167`), the factory defaults to `Editor`. So
  "reproduce with a Viewer account" asked for something that cannot exist. Before naming an actor,
  grep the enum/method for *assignment* sites, not just definitions. The reachable read-only case was
  a **non-member** opening a **public workspace-owned** ingredient.

## Measuring

- **Derive numbers from the source; never pick a round number.** I cited "1280px" as the desktop
  reference for weeks; the app caps at 1184 (`--container-app: 74rem`) and 1280 was only a viewport I
  chose in headless Chrome. That produced a table that contradicted the cap and a recommendation that
  had to be withdrawn. See `css-and-ui.md` → "Content width" for the real formula.
- **Never trust a single headless-Chrome measurement.** `--dump-dom` is flaky by up to **15px —
  exactly one scrollbar** — and a retry loop reports the flake as a real number. Take ≥3 samples per
  width, keep the median. Unreproduced = symptom, not measurement.
- **Chrome clamps `--window-size` to a 500px minimum** — 320/375/414 all report `innerWidth=500`. To
  test phone widths, force a width on a wrapper element; don't shrink the window.
- `getBoundingClientRect()` ignores ancestor clipping; root CSS `zoom` gives bogus metrics.
- A **transient failure I cannot reproduce on demand is a symptom, not a property** — say what was
  observed and that it is not reproduced; don't ship a workaround, especially a destructive one.

## A11y specifics

- **`aria-label` beats a wrapping `<label>`** (WCAG 2.5.3 Label in Name, Level A): a control showing
  visible text while taking an `aria-label` prop announces *only* the prop, so a voice user asking
  for the visible label reaches nothing. Render the prop as the visible text and point at it with
  `aria-labelledby`.
- A card with a sticky `z-20` thead needs `relative z-30` on any Filament form wrapper above it —
  Filament panels are `position: absolute; z-index: 20`, not teleported, so they lose to later DOM.
