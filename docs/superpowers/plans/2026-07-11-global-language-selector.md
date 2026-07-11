# Global Language Selector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an always-available interface-language selector for guests and registered users with browser detection, durable preferences, and independent number formatting.

**Architecture:** A locale resolver applies active-language precedence in web middleware. A POST endpoint persists explicit choices to session/cookie and optionally the user, while a shared Blade component renders the same native-language selector in every shell. Settings and registration reuse the same resolver and active locale registry.

**Tech Stack:** Laravel 13, Blade, Livewire 4, Filament 5, Pest 4, Tailwind CSS 4.

---

### Task 1: Preference persistence and resolution

**Files:**
- Create: `database/migrations/*_add_locale_to_users_table.php`
- Create: `app/Services/LocalePreferenceResolver.php`
- Modify: `app/Models/User.php`
- Create: `tests/Feature/LanguagePreferenceTest.php`

- [ ] Generate the migration, service class, and Pest test with Artisan.
- [ ] Write failing tests for user/session/cookie/browser/default precedence, base-language matching, and inactive-locale fallback.
- [ ] Run the focused test and verify RED.
- [ ] Implement the nullable constrained user locale and resolver.
- [ ] Run the focused test and verify GREEN.

### Task 2: Middleware and explicit switch endpoint

**Files:**
- Create: `app/Http/Middleware/SetApplicationLocale.php`
- Create: `app/Http/Controllers/LocalePreferenceController.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/LanguagePreferenceTest.php`

- [ ] Write failing tests for guest session/cookie persistence, authenticated user persistence, inactive-locale rejection, and local redirect fallback.
- [ ] Run the focused tests and verify RED.
- [ ] Register locale middleware at the end of the web group and add the CSRF-protected POST route.
- [ ] Implement validated switching with the `soapkraft_locale` cookie.
- [ ] Run the focused tests and verify GREEN.

### Task 3: Shared selector in every shell

**Files:**
- Create: `app/View/Components/LanguageSelector.php`
- Create: `resources/views/components/language-selector.blade.php`
- Modify: `resources/views/layouts/public.blade.php`
- Modify: `resources/views/layouts/calculator.blade.php`
- Modify: `resources/views/layouts/app-shell.blade.php`
- Modify: `lang/en/public.php`
- Modify: `tests/Feature/LanguagePreferenceTest.php`

- [ ] Write failing rendering tests for homepage/authentication, calculator, and authenticated app shell.
- [ ] Run the focused tests and verify RED.
- [ ] Build the reusable native select with globe icon, native names, current selection, and auto-submit.
- [ ] Place it in each global header without adding it to workbench cards or tabs.
- [ ] Run rendering tests and verify GREEN.

### Task 4: Registration and Settings integration

**Files:**
- Modify: `app/Http/Controllers/Auth/RegisteredUserController.php`
- Modify: `app/Livewire/Dashboard/SettingsIndex.php`
- Modify: `resources/views/livewire/dashboard/settings-index.blade.php`
- Modify: `tests/Feature/LanguagePreferenceTest.php`

- [ ] Write failing tests for registration inheritance and Settings persistence independent from number format.
- [ ] Run tests and verify RED.
- [ ] Initialize registrations from the resolved guest locale.
- [ ] Add the active-language field to Settings and queue the explicit preference cookie when saved.
- [ ] Run tests and verify GREEN.

### Task 5: Migration, documentation, and verification

**Files:**
- Modify: `docs/developer/localization.md`
- Modify: `docs/developer/current-state.md`
- Modify: `docs/codex-handoff.md`

- [ ] Run the migration and interface translation synchronization.
- [ ] Update documentation with selector placement and precedence.
- [ ] Run Pint, Filacheck when applicable, and the affected Pest suite.
- [ ] Run the production frontend build and `git diff --check`.
- [ ] Refresh Graphify.
