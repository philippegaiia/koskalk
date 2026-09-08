# Project notes — koskalk

Deep reference: `memory/reference/css-and-ui.md`, `testing-and-git.md`, `data-and-i18n.md`.

## Repo state

- **Never push without asking** — `main` carries the owner's own unpushed work.
- `main` has pre-existing failures that are not ours: Pint (inventory / production-bench) and 4
  full-suite (root `.env` picks Luna where tests pin Terra; one Composer queue-timeout contract).

## Conventions

- **Commit subjects: sentence case, imperative, no prefix** — "Polish soap workbench controls…",
  "Archive ingredient editor implementation plan". The owner dropped `feat:`/`fix:` prefixes roughly
  13 commits ago; everything from `94d5e2ac` onward is unprefixed, everything before it is prefixed.
  All commits are authored by the owner. (A standing note here claimed the prefixed form was the
  convention — it was written from the older half of the log.)
- **Check `git rev-parse --abbrev-ref HEAD` before every commit** — the owner shares this checkout.
  Leave HEAD where they had it.
- Work happens in git **worktrees** under `.worktrees/<slug>` on `codex/<slug>` branches; a worktree
  can vanish after merge — re-check `git worktree list`.
- Pint is the only automated gate; no PHPStan/Larastan.
- Test helpers in Feature tests are **file-scoped** — copy them, don't call cross-file.
- **Under `.workbuddy-ai/`: commit `memory/`, `artifacts/`, `reports/`; ignore only `skills/`**
  (generated mirror of gitignored `.agents/skills/`). An untracked `memory/YYYY-MM-DD.md` is a
  missed commit.
- **Agent tooling dirs are gitignored as a rule** (`/.agents`, `/.codex`, `/.gemini`, `/.hermes`,
  `/.superpowers`, `/.worktrees`) — check a new root dir isn't generated before calling it untracked.
- **`mcp__laravel-boost__record-rule` writes to the main checkout, not your worktree.** Copy the
  file over, hand-apply the `index.md` change, then delete it from main and
  `git checkout -- .ai/rules/index.md`.
- A **graphify post-commit hook runs automatically** — never run `graphify update .` by hand.

## Reviewing: habits that prevent wrong calls

- **`.ai/rules/index.md` maps path globs → rule files — read it before auditing any file.** Skipping
  it produced false findings; a rule file can also hand you the *remedy*.
- **The spec is the authority on intent, not the test** (`docs/superpowers/specs|plans`).
- **State retractions plainly, per-clause** — never drop a finding silently.
- **An interface assertion is not a usage assertion** — read the view for view findings.
- **`git show <base>:<path>`** before calling something absent: gap vs regression.
- **Never trust a single headless-Chrome measurement** — `--dump-dom` flakes by up to 15px (one
  scrollbar). ≥3 samples, keep the median.
- **Name the condition the code evaluates, never a role label** — grep for *assignment* sites.
  `WorkspaceMemberRole::Viewer` is declared and referenced nowhere; no Viewer account exists.
- **When amending a findings doc, re-check its summary too** — mine contradicted new sections in 4
  places. State in the header which section governs.

## Environment (verify before trusting)

- **PHP is not on PATH:** `/Users/philippe/Library/Application Support/Herd/bin/php85`.
- **`herd` CLI is on PATH.** `koskalk.test` needs no symlink — Herd parks `~/Herd`; worktree
  previews *do* need `herd link`.
- **Confirm a new Tailwind utility compiled** before claiming it renders: `cat public/hot` →
  `curl -s "$VITE/resources/css/app.css" | grep -o '<full class value>'`.
- Vite dev server status fluctuates — check `public/hot` + `public/build/assets/app-*.css` mtime
  before assuming a stale bundle. Only the owner runs `npm run dev`/`build`.
- **WCAG contrast from oklch tokens:** oklch → OKLab → *linear* sRGB → luminance. Gamma-encoding
  before luminance roughly halves every ratio (turned real 6.3:1 into 2.51:1). Sanity-check by
  printing the token as hex (`ink-soft` → `#5e5851`).
- **Bash `grep` silently returns nothing for `\|` alternation here.** Use the Grep tool.
- **`php artisan test` OOMs** (128M) while booting the app. Run
  `php85 -d memory_limit=2G vendor/bin/pest` for the full suite (~2m20s, 3257 tests).
- **Removing a `lang/en` key also requires removing its `interface-translations.json` entry** — the
  catalogue importer rejects any key it cannot find in the app
  (`InterfaceTranslationCatalogue.php:188`, "not application-owned").

## Sticky table headers

`overflow-x: auto` also computes `overflow-y: auto`, but with no height the wrapper never scrolls —
`sticky top-0` on a `<thead>` then silently does nothing. Bound it (`max-h-[70dvh] overflow-auto`);
precedent at `production-bench/production/batch-size-form.blade.php:56`. Prefer `dvh` over `vh`.

Layers: thead `z-20` > corner `<th>` `z-30` > sticky body `<td>` `z-10`. Filament dropdown panels
are `position: absolute; z-index: 20` and **not teleported** (`teleport => false`), so they lose to a
sticky `z-20` thead that comes later in the DOM — give their wrapper its own stacking context
(`relative z-30`).

## Skills

`jakubkrehel/skills` (120) already installed at `~/.agents/skills`, symlinked into `~/.claude/skills`
and `~/.codex/skills` — do **not** re-run `npx skills add`. Find with `find -L` (symlinks).
Relevant: `better-interface` (orchestrator: HIGH/MEDIUM/LOW, cheaper-fix ladder Delete → platform →
project → correct value → Add, cap 15 findings, verdict Block/Approve); `better-accessibility`,
`better-layout`, `better-writing`, `better-typography`, `better-colors`, `better-ui`;
`interface-review` (reviews a **diff**); `grilling`.

**`better-*` are NOT registered in the Skill tool** — `Skill("better-interface")` fails with "Can not
find skill". Read `~/.agents/skills/<name>/SKILL.md` directly; budget 5-6 reads.

## Currently parked

- **Decimal alignment consolidation — PROVISIONAL, owner said do not implement**
  (`docs/superpowers/plans/2026-09-04-decimal-alignment-consolidation.md`, 40-site audit).
  Only shipped change: `text-right` on the ingredient-index price input.
- **Ingredient editor UX audit** — `docs/superpowers/plans/2026-09-04-ingredient-editor-ux-audit.md`,
  9 findings, analysis only. Unverified: F1 traced statically, never reproduced.
- Open questions for the owner: should Viewers reach the editor at all? is material code
  workspace-scoped only for platform ingredients by design? should guidance/material code autosave?
</content>
</invoke>
