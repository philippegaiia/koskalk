# Schema, data and i18n — koskalk deep reference

Moved out of `MEMORY.md` 2026-09-04 to keep that file under the injection limit.

## Schema / data gotchas

- **SQLite `SUM()` is lossy on decimal** — returns `real`, a 1e-9 drift at exactly the stored
  scale. Never push decimal aggregation into SQL; row-by-row BCMath in PHP is the only
  implementation correct on both drivers (defends `MaterialActivityService::groupTotals()`).
- **`decimal(20,9)` leaves only 11 integer digits.** Validate quantities *after* unit conversion:
  `999999999` kg → PG `numeric field overflow` (500); SQLite shrugs, so the suite can't catch it.
- **The Herd preview runs PostgreSQL, not SQLite** (`koskalk_restore_20260722_023001`).
  Driver-divergent behaviour (NULL ordering, alias visibility in `WHERE`, quoting) is only
  verifiable there. Use **single quotes** for SQL literals — double quotes are identifiers in PG.
  Preview URL: `http://<worktree-slug>.test`.

## Translations

- **`interface-translations.json` must stay sorted by group AND key** (`strcmp` over `group.key`).
  Wrong position → `InvalidInterfaceTranslationCatalogue` in six tests. Insert alphabetically. New
  keys need all six locales (de, es, fr, it, nl, pt_BR).
- **A new lang key needs three places:**
  1. `lang/en/<file>.php` — what `__()` reads. English is live immediately.
  2. `database/seeders/data/interface-translations.json` — what the translation test checks. This is
     the *reviewed source*, not the live store.
  3. **The `language_lines` table** — the live store for every other locale. Nothing in
     `database/seeders` imports the catalogue; it is a manual step:
     `translations:catalogue:import --mode=preserve-existing` (adds missing rows only, safe).
     `--mode=authoritative` overwrites and would clobber translations edited by hand in the Filament
     InterfaceTranslations resource. `translations:sync` inserts missing keys only.
- **Verify a translation change with `localizedShortLabel('de')`, not by reading the JSON — if every
  locale returns the English string, that is the fallback firing, not success.**
  Measured 2026-09-03: zero `*.short_label` rows existed in the DB, and 170/170
  `categories.*.label` de/es/fr/it/nl values were `Kategorie: <English>` placeholders.

## Permissions (relevant to editor UX work)

`Ingredient::isEditableBy()` (`app/Models/Ingredient.php:392`) — true if owned by the user, else
true only when the workspace role for `tenantWorkspaceId()` is Owner / Admin / Editor. **Viewer and
null-workspace ingredients return false.** Counterpart `isReadOnly()` in
`app/Livewire/Dashboard/IngredientEditor.php:1380`, with `abort_if($this->isReadOnly(), 403)` at
line 210. The Blade save bar is gated on a *different* condition
(`@unless ($isPlatformIngredient)`, `ingredient-editor.blade.php:185`) — see the audit doc
`docs/superpowers/plans/2026-09-04-ingredient-editor-ux-audit.md` (finding F1).
