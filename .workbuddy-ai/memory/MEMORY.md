# Project notes — koskalk

Deep reference: `memory/reference/css-and-ui.md`, `testing-and-git.md`, `data-and-i18n.md`. Read the
matching file before working in that area — they are not injected.

## Repo state

- **Never push without asking** — `main` carries the owner's own unpushed work. Pre-existing failures
  on `main` (Pint + 4 full-suite) are not ours.
- **Commit subjects: sentence case, imperative, no prefix.**
- **Check `git rev-parse --abbrev-ref HEAD` before every commit** — the owner shares this checkout;
  leave HEAD where they had it. Prefer `revert` over `reset --hard`.

## Conventions

- Worktrees under `.worktrees/<slug>` (`codex/<slug>`); re-check `git worktree list`.
- Pint is the only automated gate; no PHPStan.
- Test helpers in Feature tests are **file-scoped** — copy them, don't call cross-file.
- Under `.workbuddy-ai/`: commit `memory/`, `artifacts/`, `reports/`; ignore only `skills/`.
- `record-rule` writes to the **main checkout, not your worktree** — copy over, then revert main.
- A **graphify post-commit hook runs automatically** — never run `graphify update .` by hand.

## Reviewing: habits that prevent wrong calls

Full list in `memory/reference/reviewing.md`. The three that bite:

- **`.ai/rules/index.md` maps path globs → rule files — read it before auditing any file.**
- **The spec is the authority on intent, not the test** (`docs/superpowers/specs|plans`).
- **Derive measurements from the source; never pick a round number.** I cited "1280px" for weeks as
  the desktop reference; the app caps at **1184** and 1280 was only a viewport I chose.

## Environment

- **PHP is not on PATH:** `/Users/philippe/Library/Application Support/Herd/bin/php85`.
- **Bash `grep` is unreliable — use the Grep tool.** A bash-grep miss is not evidence of absence.
- **`php artisan test` OOMs** — `php85 -d memory_limit=2G vendor/bin/pest` (~2m20s).
- `herd` CLI is on PATH; `koskalk.test` needs no symlink (Herd parks `~/Herd`); worktree previews do.
- **`npm run build` is sandbox-denied** (Vite `loadEnv` reads `.env`); check CSS via `public/hot`.

## Skills

`jakubkrehel/skills` (120) at `~/.agents/skills`, symlinked into `~/.claude/skills` +
`~/.codex/skills` — do **not** re-run `npx skills add`; find with `find -L`. **Not registered in the
Skill tool** — read `~/.agents/skills/<name>/SKILL.md` directly.

## Currently parked

- **Decimal alignment consolidation — owner said do not implement** (see the 2026-09-04 plan).
- **Ingredient editor UX audit** — analysis only; F1 traced statically, never reproduced.
- Open: should Viewers reach the editor? is material code workspace-scoped only for platform
  ingredients? do guidance/material code autosave?
