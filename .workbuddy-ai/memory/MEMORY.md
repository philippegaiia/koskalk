# Project notes — koskalk

Deep reference lives in `memory/reference/`: `css-and-ui.md`, `testing-and-git.md`,
`data-and-i18n.md`. Read those when the task touches them; they are not injected.

## State as of 2026-09-04

`main` @ `22a1574e`. **Unpushed: 16 ahead / 0 behind `origin/main`** — 12 ours, 4 the owner's own
AI-enrichment work. **Never push without asking** — publishing ours publishes theirs.
Split: `git rev-list origin/main..<pre-merge main> --count`.

Failing on `main`, none of it ours: 6 Pint failures (all inventory / production-bench); 4 full-suite
(three from root `.env` selecting Luna where tests pin Terra; one pre-existing Composer
queue-timeout contract mismatch, `--timeout=0` vs expected `300`).

## Reviewing: habits that prevent wrong calls

- **The spec is the authority on intent, not the test.** Specs `docs/superpowers/specs/`, plans
  `docs/superpowers/plans/`. Read the governing spec before reporting a behavioural finding; check
  drift both ways. Spec wins on intent; an explicit deliberate plan detail wins on implementation —
  flag divergence, don't file as a defect.
- **State retractions plainly**, never quietly drop a finding. **Retract per-clause**, never
  wholesale — split compound rules before ruling.
- **An interface assertion is not a usage assertion.** `implements HasForms` says nothing about
  whether the Blade renders `{{ $this->filtersForm }}`. Read the view for view findings.
- **Check the pre-branch version before calling something absent** — `git show <base>:<path>`
  separates "never had it" (gap) from "branch removed it" (regression, much worse).
- **Check whether the guarded thing feeds the guard's own predicate** — self-referential
  preconditions deadlock (e.g. `tracks()` derived from the settings a buffer writes).
- **Never trust a single headless-Chrome measurement.** `--dump-dom` is flaky by up to **15px —
  exactly one scrollbar** — and a retry loop reports the flake as a real number. Take ≥3 samples
  per width, keep the median. Unreproduced = symptom, not measurement.
- **Read `.ai/rules/index.md` before auditing any file — it maps path globs → rule files**
  (`app/Livewire/Dashboard/IngredientEditor.php` → `dashboard.md`, `resources/views/**` → `views.md`,
  `app/Livewire/**` → `livewire.md`). Skipping it produced a false finding: I called the hidden
  `is_soap_saponification_trusted` flag a defect when `dashboard.md` mandates it ("Keep the trust
  flag hidden in the workspace editor"). A rule file can also hand you the *remedy* for a separate
  finding. **Measure the owner's own compliance before filing a rule violation** — `livewire.md`
  says enforce with `authorize()`, but all 12 `$this->authorize()` calls sit in one component while
  4 others use `abort(403)`. A rule most of the owner's components break is not the house rule.

## Conventions

- Conventional lowercase commit subjects (`feat:`, `fix:`, `test:`, `refactor:`, `docs:`). Plan /
  design docs go straight onto `main` with a `docs:` subject.
- **Check `git rev-parse --abbrev-ref HEAD` before every commit** — the owner works in this same
  checkout and may switch branches between my commands. Leave HEAD where they had it.
- A **graphify post-commit hook runs automatically** and rebuilds `graphify-out/`. Never run
  `graphify update .` manually, never list it as a plan step.
- Work happens in git **worktrees** under `.worktrees/<slug>` on `codex/<slug>` branches; a
  worktree can vanish after merge — re-check `git worktree list` before using a path.
- Pint is the only automated gate; no PHPStan/Larastan. Unused params etc. are caught by nothing —
  raise them in review.
- Test helpers in Feature tests are **file-scoped**, declared at the bottom. Copy them into a
  scratch test rather than calling cross-file.
- **Under `.workbuddy-ai/`: commit `memory/`, `artifacts/`, `reports/`; ignore only `skills/`.** An
  untracked `memory/YYYY-MM-DD.md` is a *missed commit*, not a deliberate omission (logs tracked
  since 2026-08-29). But `.workbuddy-ai/skills/` is a generated mirror of the gitignored
  `.agents/skills/`, rebuilt by `scripts/sync-boost-skills.sh` — committing it is like committing
  `node_modules`. Never ignore `.workbuddy-ai/` wholesale.
- **Agent tooling dirs are gitignored as a rule** (`/.agents`, `/.codex`, `/.gemini`, `/.hermes`,
  `/.superpowers`, `/.worktrees`). If a new tool drops a dir at the repo root, check whether it is
  generated before letting it show up as untracked.
- **`mcp__laravel-boost__record-rule` writes to the main checkout, not your worktree.** Boost is
  configured against `/Users/philippe/Herd/koskalk`, so calling it from `.worktrees/<slug>`
  silently creates/edits `.ai/rules/*` **on `main`**. After calling it: copy the file into the
  worktree, apply any `index.md` change by hand, then delete it from the main checkout and
  `git checkout -- .ai/rules/index.md`. Verify the `index.md` diff is only your line first — the
  owner's tree routinely carries other people's uncommitted work.

## Environment (verify before trusting)

- **PHP is not on PATH:** `/Users/philippe/Library/Application Support/Herd/bin/php85`.
- **`herd` CLI is on PATH** at `/Users/philippe/Library/Application Support/Herd/bin/herd` (check
  `command -v herd` before hand-editing Herd config). An earlier note claimed it was not — re-check.
  `herd unlink <site>` removes only the symlink in `.../Herd/config/valet/Sites/`.
- **`koskalk.test` needs no symlink** — Herd *parks* `~/Herd`. It will not appear in
  `config/valet/Sites/`. Worktree previews *do* need a symlink (`herd link`).

## Skills

`jakubkrehel/skills` (120 skills) is **already installed** at `~/.agents/skills`, symlinked into
`~/.claude/skills` and `~/.codex/skills` — do **not** re-run `npx skills add`. Find them with
`find -L`, not `find` (the dirs are symlinks). Relevant: `better-interface` (orchestrator:
severity HIGH/MEDIUM/LOW, escalation triggers, cheaper-fix ladder Delete → platform → project →
correct value → Add, cap 15 findings, verdict Block/Approve); `better-accessibility` (+`hit-areas.md`);
`better-layout`, `better-writing`, `better-typography`, `better-colors`, `better-ui`;
`interface-review` (reviews a **diff** — wrong tool when the tree is clean); `grilling` (design-tree
interview, ask the whole frontier per round).

## Currently parked

- **Decimal alignment consolidation — PROVISIONAL, owner said do not implement.**
  `docs/superpowers/plans/2026-09-04-decimal-alignment-consolidation.md` holds the full 40-site
  audit. Only shipped change: `text-right` on the ingredient-index price input. A `pr-6` nudge was
  built and reverted the same day.
- **Ingredient editor UX audit** — `docs/superpowers/plans/2026-09-04-ingredient-editor-ux-audit.md`,
  9 findings, analysis only, nothing built. Unverified: F1 (Viewer role reaches the editor with a
  disabled form + live Save → 403) was traced statically, never reproduced with a real Viewer.
- Vite dev server **down** (`public/hot` absent) → `koskalk.test` serves the stale 2026-08-30
  bundle with **0 of the 22 badge hues**. Only the owner runs `npm run dev`/`build`.
- Open questions for the owner: should Viewers reach the editor at all? is material code
  workspace-scoped only for platform ingredients by design? should guidance/material code autosave?
  how much technical data do users need on a platform ingredient?
