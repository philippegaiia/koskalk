# Tailwind Source Boundary and Shared Width Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restrict the application Tailwind build to explicit runtime sources and make every public shell width boundary independently regression-tested against the shared `max-w-app` token.

**Architecture:** Disable Tailwind's automatic project scan in the application stylesheet, then explicitly register the Laravel runtime inputs that can contain utility class strings: application PHP, Blade views, JavaScript, Laravel pagination views, and compiled Blade views. Give the homepage hero and workspace inner wrappers stable data attributes, then use focused Pest assertions that match each marked element and prove it owns `max-w-app` without placing the legacy arbitrary-width utility verbatim in test source.

**Tech Stack:** Tailwind CSS 4 CSS-first configuration, Laravel 13 Blade, Pest 4, Vite

---

## File map and constraints

- Modify `resources/css/app.css`: replace automatic source discovery and broad resource globs with `source(none)` plus a complete runtime-only allowlist.
- Modify `resources/views/welcome.blade.php`: add stable attributes to the existing hero inner and workspace inner containers; make no visual, structural, or content changes.
- Modify `tests/Feature/PublicShellPagesTest.php`: add source-boundary coverage and replace the aggregate width assertion with four independently named container cases plus an escaped regex assertion for the retired width.
- Do not modify `.workbuddy-ai`, `.gitignore`, `resources/views/production-bench/inventory-material-detail.blade.php`, or the content/design of the welcome page.
- Do not commit. The orchestrating agent will review and report the uncommitted diff.

### Task 1: Lock the Tailwind scanner to runtime sources

**Files:**
- Modify: `resources/css/app.css:1-14`
- Test: `tests/Feature/PublicShellPagesTest.php`

- [ ] **Step 1: Add a failing source-boundary regression test**

Add this Pest test near the public shell width coverage in `tests/Feature/PublicShellPagesTest.php`:

```php
it('limits the application Tailwind scan to explicit runtime sources', function () {
    $source = (string) file_get_contents(resource_path('css/app.css'));
    preg_match_all('/^\s*@source\s+.+;$/m', $source, $matches);
    $sourceDeclarations = array_map('trim', $matches[0]);

    expect($source)->toContain("@import 'tailwindcss' source(none);");
    expect($sourceDeclarations)->toBe([
        "@source '../../app/**/*.php';",
        "@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';",
        "@source '../../storage/framework/views/*.php';",
        "@source '../views/**/*.blade.php';",
        "@source '../js/**/*.js';",
    ]);
});
```

This is an architecture-style configuration contract: `source(none)` disables the default repository scan, while every explicit source remains inside runtime code or framework-rendered templates. Therefore `tests/`, `docs/`, prose files, and `.workbuddy-ai/` cannot contribute utilities.

- [ ] **Step 2: Run the focused test and confirm it fails**

Run:

```bash
php artisan test --compact tests/Feature/PublicShellPagesTest.php --filter='limits the application Tailwind scan'
```

Expected: FAIL because `resources/css/app.css` still imports Tailwind without `source(none)` and does not list `app/**/*.php`.

- [ ] **Step 3: Replace automatic discovery with the explicit runtime allowlist**

Change the import and source declarations at the top of `resources/css/app.css` to exactly:

```css
@import 'tailwindcss' source(none);
@custom-variant dark (&:where(.dark, .dark *));
@import '../../vendor/filament/support/resources/css/index.css';
@import '../../vendor/filament/schemas/resources/css/index.css';
@import '../../vendor/filament/actions/resources/css/index.css';
@import '../../vendor/filament/forms/resources/css/index.css';
@import '../../vendor/filament/tables/resources/css/index.css';
@import './shared/soapkraft.css';
@import './shared/filament-soapkraft.css';

@source '../../app/**/*.php';
@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../views/**/*.blade.php';
@source '../js/**/*.js';
```

The application PHP source is required because Filament and Livewire can declare complete Tailwind class strings in PHP. The two narrowed `resources` paths preserve all Blade and JavaScript runtime utilities without scanning unrelated resource prose.

- [ ] **Step 4: Rerun the focused source-boundary test**

Run:

```bash
php artisan test --compact tests/Feature/PublicShellPagesTest.php --filter='limits the application Tailwind scan'
```

Expected: PASS.

### Task 2: Prove all four public width boundaries independently

**Files:**
- Modify: `resources/views/welcome.blade.php` at the hero inner wrapper and workspace inner wrapper
- Modify: `tests/Feature/PublicShellPagesTest.php` at the existing shared-width regression test

- [ ] **Step 1: Replace the aggregate test with failing per-container cases**

Replace the existing `bounds the public shell with the shared content width token` test with:

```php
it('bounds the :container with the shared content width token', function (string $containerMarker) {
    $homepage = view('welcome')->render();
    $pattern = sprintf(
        '/<(?=[^>]*\\s%s(?:\\s|=|\\/?>))(?=[^>]*\\sclass="(?:[^"]*\\s)?max-w-app(?:\\s[^"]*)?")[^>]+>/s',
        preg_quote($containerMarker, '/'),
    );

    expect($homepage)->toMatch($pattern);
})->with([
    'navigation' => 'data-public-nav-inner',
    'footer' => 'data-public-footer-inner',
    'homepage hero inner' => 'data-homepage-hero-inner',
    'homepage workspace inner' => 'data-homepage-workspace-inner',
]);

it('does not restore the retired hardcoded public width', function () {
    $homepage = view('welcome')->render();

    expect($homepage)->not->toMatch('/max-w-\\[1180px\\]/');
});
```

The lookaheads require the marker and `max-w-app` class on the same HTML element, independent of attribute order. The escaped brackets keep the obsolete utility from appearing as a literal Tailwind candidate in PHP test source.

- [ ] **Step 2: Run the width cases and confirm the two homepage cases fail**

Run:

```bash
php artisan test --compact tests/Feature/PublicShellPagesTest.php --filter='bounds the'
```

Expected: the navigation and footer datasets PASS; the hero and workspace datasets FAIL because their stable markers do not exist yet.

- [ ] **Step 3: Add stable markers to the existing homepage inner wrappers**

In `resources/views/welcome.blade.php`, change only the two wrapper start tags:

```blade
<div data-homepage-hero-inner class="mx-auto grid min-h-[calc(100svh-8rem)] max-w-app items-center gap-10 px-5 py-7 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.72fr)] lg:px-10 lg:py-12">
```

```blade
<div data-homepage-workspace-inner class="mx-auto max-w-app">
```

Do not alter the surrounding sections, text, responsive behavior, or existing classes.

- [ ] **Step 4: Run the complete focused test file**

Run:

```bash
php artisan test --compact tests/Feature/PublicShellPagesTest.php
```

Expected: all tests in the file PASS, including each named width dataset and the source-boundary contract.

### Task 3: Format, build, refresh the graph, and audit scope

**Files:**
- Verify only: `resources/css/app.css`, `resources/views/welcome.blade.php`, `tests/Feature/PublicShellPagesTest.php`
- Refresh generated graph output with the repository-required graph command

- [ ] **Step 1: Format the modified PHP test**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: exit code 0; formatting changes, if any, are limited to modified PHP files.

- [ ] **Step 2: Rerun focused Pest coverage after formatting**

Run:

```bash
php artisan test --compact tests/Feature/PublicShellPagesTest.php
```

Expected: PASS.

- [ ] **Step 3: Build both Vite stylesheet entries**

Run:

```bash
npm run build
```

Expected: exit code 0. This proves the Tailwind v4 source directives are valid and the application/public bundles still compile.

- [ ] **Step 4: Refresh the repository knowledge graph**

Run:

```bash
graphify update .
```

Expected: exit code 0. Generated `graphify-out/` changes are allowed only as the direct result of this required refresh.

- [ ] **Step 5: Review the final diff and prohibited paths**

Run:

```bash
git status --short
git diff -- resources/css/app.css resources/views/welcome.blade.php tests/Feature/PublicShellPagesTest.php
git diff -- .workbuddy-ai .gitignore resources/views/production-bench/inventory-material-detail.blade.php
```

Expected: the implementation diff contains only the CSS allowlist, two data markers, and focused regression tests; the prohibited-path diff is empty. Do not commit.
