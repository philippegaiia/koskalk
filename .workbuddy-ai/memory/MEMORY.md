# Project notes — koskalk

Deep reference: `memory/reference/css-and-ui.md` (build/preview, visual language, badges, decimal
alignment, Livewire/Filament, sticky headers, contrast), `testing-and-git.md`, `data-and-i18n.md`.
Read the matching file before working in that area — they are not injected.

## Repo state

- **Never push without asking** — `main` carries the owner's own unpushed work.
- Pre-existing failures on `main`, none ours: Pint (inventory / production-bench) and 4 full-suite
  (root `.env` picks Luna where tests pin Terra; one Composer queue-timeout contract).

## Conventions

- **Commit subjects: sentence case, imperative, no prefix** (unprefixed from `94d5e2ac` on).
- **Check `git rev-parse --abbrev-ref HEAD` before every commit** — the owner shares this checkout;
  leave HEAD where they had it. Prefer non-destructive repairs (`revert` over `reset --hard`).
- Work happens in git **worktrees** under `.worktrees/<slug>` on `codex/<slug>` branches; re-check
  `git worktree list` — one can vanish after merge.
- Pint is the only automated gate; no PHPStan/Larastan.
- Test helpers in Feature tests are **file-scoped** — copy them, don't call cross-file.
- **Under `.workbuddy-ai/`: commit `memory/`, `artifacts/`, `reports/`; ignore only `skills/`** (a
  generated mirror of gitignored `.agents/skills/`).
- Agent tooling dirs are gitignored as a rule — check a new root dir isn't generated before calling
  it untracked.
- **`mcp__laravel-boost__record-rule` writes to the main checkout, not your worktree** — copy over,
  hand-apply the `index.md` change, then revert main.
- A **graphify post-commit hook runs automatically** — never run `graphify update .` by hand.

## Reviewing: habits that prevent wrong calls

- **`.ai/rules/index.md` maps path globs → rule files — read it before auditing any file.** A rule
  file can also hand you the *remedy* for another finding.
- **The spec is the authority on intent, not the test** (`docs/superpowers/specs|plans`).
- **State retractions plainly, per-clause** — never drop a finding silently.
- **An interface assertion is not a usage assertion** — read the view for view findings.
- **`git show <base>:<path>`** before calling something absent: gap vs regression. **Name the
  condition the code evaluates, never a role label** — grep for *assignment* sites.
- **Never trust a single headless-Chrome measurement** — flakes by up to 15px (one scrollbar); take
  ≥3 samples and keep the median.
- **When amending a findings doc, re-check its summary too** — mine contradicted new sections.

## Environment (verify before trusting)

- **PHP is not on PATH:** `/Users/philippe/Library/Application Support/Herd/bin/php85`.
- **Bash `grep` is unreliable in this shell — use the Grep tool by default.** It returns nothing for
  `\|` alternation, and has also silently missed plain multi-pattern greps that the Grep tool found
  instantly. A bash-grep miss is not evidence of absence.
- **`php artisan test` OOMs** (128M) — run `php85 -d memory_limit=2G vendor/bin/pest` (~2m20s).
- **`herd` CLI is on PATH.** `koskalk.test` needs no symlink — Herd parks `~/Herd`; worktree
  previews *do* need `herd link`.

## Skills

`jakubkrehel/skills` (120) at `~/.agents/skills`, symlinked into `~/.claude/skills` and
`~/.codex/skills` — do **not** re-run `npx skills add`; find with `find -L` (symlinks). Relevant:
`better-interface` (orchestrator: HIGH/MEDIUM/LOW, cheaper-fix ladder Delete → platform → project →
correct value → Add, cap 15 findings, verdict Block/Approve); `better-accessibility`,
`better-layout`, `better-writing`, `better-typography`, `better-colors`, `better-ui`;
`interface-review` (reviews a **diff**); `grilling`.
**Not registered in the Skill tool** — `Skill("better-interface")` fails. Read
`~/.agents/skills/<name>/SKILL.md` directly.

## Currently parked

- **Decimal alignment consolidation — PROVISIONAL, owner said do not implement**
  (`docs/superpowers/plans/2026-09-04-decimal-alignment-consolidation.md`, 40-site audit).
- **Ingredient editor UX audit** — `docs/superpowers/plans/2026-09-04-ingredient-editor-ux-audit.md`,
  9 findings, analysis only. F1 was traced statically, never reproduced.
- Open questions for the owner: should Viewers reach the editor at all? is material code
  workspace-scoped only for platform ingredients by design? should guidance/material code autosave?
