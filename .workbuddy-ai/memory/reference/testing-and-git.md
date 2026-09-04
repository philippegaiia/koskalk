# Test suite, git forensics and merging — koskalk deep reference

Moved out of `MEMORY.md` 2026-09-04 to keep that file under the injection limit.

## Test suite: recurring failure causes

Triage doc: `docs/superpowers/plans/2026-08-29-test-suite-failures-triage.md`.

- **`56726688`** changed model casts and bumped
  `config('ingredient-enrichment.schema_version')` 3 → 4 without updating tests pinning the old
  shapes. Check its blast radius when a test suddenly stops matching.
- **Schema-gated validation:** `IngredientEnrichmentResultValidator` early-returns its whole
  cross-field provenance block when the payload isn't on the current schema. Tests hard-coding an
  old `schema_version` silently pass strict rules by *skipping* them. Always use
  `config('ingredient-enrichment.schema_version')`, never a literal.
- **Superseded tests linger** (e.g. `MediaStorageTest` survived removal of rich-editor uploads).
- **Tests are hard-blocked from the real database.** `tests/Support/TestDatabaseSafety.php` (from
  `tests/TestCase.php`) throws unless the connection is `sqlite: :memory:`. The only sanctioned
  exception is a disposable `koskalk_fk_index_roundtrip_*` PG database for explicit FK-index
  verification. `phpunit.xml` sets `DB_CONNECTION=sqlite` *without* `force`, so
  `DB_CONNECTION=pgsql ... artisan test` gets past PHPUnit and is then refused by the guard.
  **Do not work around it.** Consequence: you cannot render a page against the Herd/PG database from
  the suite — driver divergence can only be checked in a real browser.
- **`pest --parallel` ignores `-d memory_limit`.** ParaTest workers don't inherit the parent's `-d`
  flags, so `php -d memory_limit=1G vendor/bin/pest --parallel` still dies at 128M
  (`HtmlSanitizerConfig.php` is the usual victim). For a full suite drop `--parallel` and let the
  parent's `-d memory_limit=2G` apply — ~127s for 2910 tests. `phpunit.xml` sets no `memory_limit`
  and no `passthru-php`, so there's nothing to lean on.
- **`php artisan test` does not forward `-d memory_limit`** to the Pest subprocess — the run dies at
  128M inside `blade-icons/IconsManifest.php`. Invoke Pest directly:
  `php85 -d memory_limit=1024M vendor/bin/pest`. `phpunit.xml` already pins `sqlite :memory:` and
  `APP_ENV=testing`, so the database needs no special handling at the root checkout.

## Git forensics traps (each produced a false conclusion once)

- **Attribution ranges die at merge.** `git log main..branch -- <file>` is empty by definition
  after a merge. Diff against pre-merge main instead.
- **zsh eats `$var:path`.** Write `git show ${c}:config/foo.php`. Unquoted `grep --include=*.php`
  also fails — use the Grep tool. Unquoted globs that don't match abort the rest of the command.
- **Never let a probe fail silently.** `cmd 2>/dev/null | grep -c X` returning `0` is
  indistinguishable from `cmd` having failed. Print a sentinel before trusting a uniform zero.
- **A literal grep for a runtime-composed string always returns nothing, and reads as "dead."**
  Search the *interpolation site* (`?? 'inert'`, `sk-badge-{{`), never the composed result. "No
  literal reference" ≠ "no reference". Applies to CSS classes, translation keys, route names alike.
- **macOS/BSD `grep` has no `-P`.** Use `sed -n "s/…/\1/p"` for lookaround-style extraction, or the
  Grep tool. `grep -oP` fails with an unhelpful "invalid option -- P". `cat -A` is also unavailable.
- Before concluding a file is absent, re-check `git ls-tree -r --name-only <rev> -- <dir>`.
- Before asserting a test "never passed", verify the measurement on a commit where the value is
  known; prefer `git log --all --oneline -S '<string>' -- <path>`.
- **A diffstat does not distinguish "created" from "extended."** Files I described as added were
  11/8/42-line stubs at the base. `create mode` lines are easy to skim past; run
  `git show <base>:<path>` for anything you plan to call new.
- **Never trust `git merge-tree` for conflict prediction** — it printed `our`/`their` stages and
  still exited 0. Use `git rev-list main..HEAD --count` / `HEAD..main --count`, and
  `git merge-base --is-ancestor main <branch>` to prove a fast-forward is possible. (`merge-base`
  has no `--short` flag; compare full SHAs.)

## Merging into `main` (the owner's own checkout)

- **The tree is routinely dirty with other sessions' work** — agent memory,
  `.workbuddy-ai/skills/`, untracked `docs/` write-ups, Filament asset rebuilds. **Never
  `git clean` or `git stash` it.** Snapshot `git status --porcelain` + `shasum` of the untracked
  files before, compare after, report the count. A `--ff-only` merge only moves the branch pointer,
  so it *cannot* touch untracked files — but verify that rather than asserting it.
- **Prove the merge introduced nothing:** `git rev-parse main^{tree}` must equal the branch tip's
  tree. If those match, the branch's own green test run transfers to `main` verbatim.
- **Scope Pint to the branch before calling it dirty:**
  `git diff --name-only <base>..<tip> | grep '\.php$' | xargs php vendor/bin/pint --test`.
  `pint --test $FILES` with a quoted variable fails — it treats the whole list as one filename.
  Use `xargs`.

## `.ai/rules` — read the operative clause, not the parenthetical

`services.md:15` prohibits instantiating services **with `new`**. `app(SomeService::class)` *is*
container resolution and satisfies the rule; the parenthetical ("method injection… constructor
injection…") states a preference, not an exclusive mechanism. Measured: 15 of 39 Livewire files use
`app()`. Don't file that as a violation — a rule failing the owner's own components is not the
house rule.
