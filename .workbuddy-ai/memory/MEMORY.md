# Project notes — koskalk

Deep reference: `memory/reference/css-and-ui.md`, `testing-and-git.md`, `data-and-i18n.md`. Read the
matching file before working in that area — they are not injected.

## Repo state

- **Never push without asking** — `main` carries the owner's own unpushed work. Pre-existing failures
  on `main` (Pint + 4 full-suite) are not ours.
- **A large currency-shaped failure cluster is almost always the locale trap, not the repo** — see the
  Environment note on `LANG=C`. Re-run the single file with a UTF-8 locale before blaming the tree.
- **Commit subjects: sentence case, imperative, no prefix.**
- **Check `git rev-parse --abbrev-ref HEAD` before every commit** — the owner shares this checkout;
  leave HEAD where they had it. Prefer `revert` over `reset --hard`.

## Conventions

- Worktrees under `.worktrees/<slug>` (`codex/<slug>`); re-check `git worktree list`.
- Pint is the only automated gate; no PHPStan.
- Test helpers in Feature tests are **file-scoped** — copy them, don't call cross-file.
- Under `.workbuddy-ai/`: commit `memory/`, `artifacts/`, `reports/`; ignore only `skills/`.
- `record-rule` writes to the **main checkout, not your worktree** — copy over, then revert main.
- A **graphify post-commit hook runs automatically**, and AGENTS.md also requires running
  `graphify update .` after modifying code in-session — follow AGENTS.md (2026-09-30: an earlier
  "never run by hand" note contradicted the committed instructions and was wrong).

## Reviewing: habits that prevent wrong calls

Full list in `memory/reference/reviewing.md`. The three that bite:

- **`.ai/rules/index.md` maps path globs → rule files — read it before auditing any file.**
- **The spec is the authority on intent, not the test** (`docs/superpowers/specs|plans`).
- **Derive measurements from the source; never pick a round number.** I cited "1280px" for weeks as
  the desktop reference; the app caps at **1184** and 1280 was only a viewport I chose.

## Environment

- **PHP is not on PATH:** `/Users/philippe/Library/Application Support/Herd/bin/php85`.
- **Bash `grep` is unreliable — use the Grep tool.** A bash-grep miss is not evidence of absence.
  Bit me twice on 2026-10-02: I used bash grep to "prove" `data-failure-message` did not exist in two
  blades and reported a half-wired fix; the attribute was on line 3 of both. Before reporting that
  something is missing, re-check with the Grep tool.
- **AGENTS.md prescribes `php artisan test --compact`** (narrow runs by the agent, full suite by the
  user). CLI `memory_limit` already defaults to 2048M. A 2026-09 note claimed `php artisan test`
  OOMs and prescribed `php85 -d memory_limit=2G vendor/bin/pest` — that limit equals the default, so
  the OOM claim is unverified; if a full-suite OOM recurs, reproduce and record the actual error
  before adopting a workaround.
- `herd` CLI is on PATH; `koskalk.test` needs no symlink (Herd parks `~/Herd`); worktree previews do.
- **Run tests with a UTF-8 locale (`LANG=en_US.UTF-8`) — before judging ANY failure cluster.**
  In an agent shell `LANG=C` is common and leaves PHP's `intl` default locale empty, so Symfony Intl
  `Currencies::exists()` fails for *every* code: `selectableCodes()` returns `[]`,
  `CurrencyCatalogTest` fails 2 of 4, and currency validation errors cascade into dozens of unrelated
  failures. Reproduced 2026-10-02 twice: 2 failed under `LANG=C`, 4 passed with `LANG=en_US.UTF-8`.
  **The blast radius is not only currency tests** — `ProductionWorkspaceAuthorizationTest` also
  reported a bogus 403-vs-200 on its `demotion` dataset under `LANG=C` and passed 14/14 with UTF-8.
  I declared "74 pre-existing failures on main" from this artifact and had to retract it. Also
  recorded in `.ai/rules/tests.md` via `record-rule`.
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
