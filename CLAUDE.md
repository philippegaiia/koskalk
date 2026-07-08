# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Koskalk is a professional formulation workspace for soap and cosmetic recipes. The product is a technical tool designed for SoapCalc-level efficiency with better clarity, data structure, and compliance support. It is not a consumer app or social platform.

### Tech Stack

- **Backend:** Laravel 13, PostgreSQL (target environment), SQLite (testing)
- **Frontend:** Blade + Livewire + Alpine.js + Tailwind 4.0
- **Admin:** Filament 5 (admin only, not user-facing)
- **Testing:** Pest PHP
- **AI:** Laravel Boost (for AI development assistance)

### Key Architecture Principles

1. **No DB writes on field change** - Alpine manages live draft state locally; explicit actions only
2. **Service-layer focus** - Livewire components and controllers remain thin; business logic lives in services
3. **Tenant-aware data** - User/workspace data uses global scopes and policies from day one
4. **Versioning is mandatory** - Ingredients, recipes, and compliance runs are all versioned
5. **Admin vs Public separation** - Filament is for data stewardship only; public UI uses custom Blade/Livewire

## Common Development Commands

### Setup
```bash
composer install
npm install
php artisan key:generate
php artisan migrate
npm run build
```

### Development
```bash
# Run all services (server, queue, logs, vite)
composer run dev

# Individual services
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
php artisan pail --timeout=0
npm run dev
```

### Testing
```bash
# Run all tests
composer test

# Run specific test file
php artisan test --filter=SoapCalculationServiceTest

# Run with coverage
php artisan test --coverage
```

### Code Quality
```bash
# Pint (Laravel's code formatter)
./vendor/bin/pint

# Pail (log monitoring)
php artisan pail
```

## Project Structure

### Service Layer (`app/Services/`)

Services contain the business logic. Key services include:

- `SoapCalculationService` - Core soap math, fatty acid aggregation, quality metrics
- `RecipeNormalizationService` - Phase-based formula normalization
- `InciGenerationService` - INCI list generation with compliance context
- `RecipeVersionCostingSynchronizer` - Cost calculation and synchronization
- `UserIngredientAuthoringService` - User ingredient creation and editing
- `RecipeWorkbenchService` - Formulation workspace logic

### Livewire Components (`app/Livewire/Dashboard/`)

Public UI uses Livewire components for the dashboard and formulation workspace:

- `RecipeWorkbench` - Main formulation page with Alpine for local state
- `IngredientsIndex` / `IngredientEditor` - Ingredient catalog browsing and editing
- `PackagingItemsIndex` / `PackagingItemEditor` - Packaging item management

### Models (`app/Models/`)

Three-tier ingredient model:
- `Ingredient` - Category, stewardship flags, source identity
- `IngredientVersion` - Display names, INCI, CAS/EC, price, source version data
- `IngredientSapProfile` - KOH SAP, derived NaOH SAP, fatty acid profiles

### Frontend Stack (`resources/`)

- `resources/js/recipe-workbench/` - JavaScript for the formulation workspace
- `resources/views/layouts/` - Public and app-shell layouts
- `resources/views/livewire/` - Livewire component views

## Domain Rules

### Ingredient Categories

Defined in `App\IngredientCategory` enum. Only carrier oils can appear in the saponification selection list. An ingredient must be explicitly marked as potentially saponifiable before driving soap math.

### Product Families

Product families (defined in `ProductFamily`) drive IFRA mapping, restriction applicability, and calculation defaults. Never hard-code these as conditionals.

### Data Ownership

**Platform-owned (admin-controlled):**
- Ingredients, ingredient versions, SAP profiles
- Allergen catalog, IFRA categories
- Compliance rules and jurisdictions

**Tenant-aware (user/workspace-owned):**
- Recipes, recipe versions, recipe items, recipe phases
- User ingredient prices and packaging items
- Compliance runs and results
- Private custom additives

### Non-negotiable Rules

1. Users cannot create saponifiable oils that drive soap math
2. Compliance runs are append-only — never overwrite
3. Every tenant-aware model gets global scope + policy before first controller
4. `product_family_id` drives IFRA and restriction logic — never hard-code
5. Filament is admin-only — no user-facing functionality leaks there

## Frontend Architecture

### Formulation Workspace

The formulation page uses Alpine.js for instant local calculations:

- **Live state:** Alpine manages the draft state locally (no DB writes on field change)
- **Ingredient search:** Pre-loaded data, no server calls during search
- **Calculations:** Server provides preview payload, Alpine updates instantly
- **Save actions:** Explicit actions (Save Draft, Save as New Version) trigger server writes

### Design Principles

- Desktop-first, dense layout
- Table-first UI over card-based design
- Small-option controls use ticks/toggles instead of selects
- Minimal animations, focus on speed and clarity
- Clear unsaved state indicator always visible

## Testing Patterns

Use Pest PHP for all tests. Tests use SQLite in-memory database. Feature tests cover user workflows, while unit tests test services in isolation.

Example test structure:
```bash
tests/Feature/             # User workflows and integration tests
tests/Unit/                # Service-level unit tests
database/factories/        # Factory definitions for test data
```

## Documentation Structure

Project documentation lives in `docs/`:

- `docs/README.md` - Documentation index
- `docs/developer/` - Implementation details and architecture
- `docs/specs/` - Product rules and domain decisions
- `plan.md` - Overall build plan and product direction

## Important Files to Understand

- `app/Services/SoapCalculationService.php` - Core soap calculation logic
- `app/Livewire/Dashboard/RecipeWorkbench.php` - Main formulation component
- `app/Models/Ingredient.php` - Ingredient model structure
- `resources/js/recipe-workbench/` - Frontend formulation workspace
- `plan.md` - Complete product build plan

## Frontend Accessibility Standards

These rules apply to every Blade view, partial, and layout. Agents must follow them when creating or editing any HTML — no separate audit should be needed.

### Forms and inputs
- Every `<input>` and `<select>` must have `aria-label` or `aria-labelledby` pointing to a visible label
- Alpine dynamic labels: `:aria-label="'Percentage for ' + row.name"`

### Tab navigation
- Tab bar container: `role="tablist" aria-label="..."`
- Each tab button: `role="tab" :aria-selected="activeTab === 'x'" id="tab-x"`
- Tab panel: `role="tabpanel" aria-labelledby="tab-x" id="panel-x"`

### Toggle pill groups (radio-style)
- Container: `role="radiogroup" aria-label="..."`
- Each button: `role="radio" :aria-checked="value === 'x'"`

### Modals and dialogs
- Overlay/container: `role="dialog" aria-modal="true" aria-labelledby="modal-heading-id"`
- Heading inside modal gets the matching `id`
- Escape handler on inner card, not window

### Sections and regions
- Every `<section>` needs `aria-labelledby` (pointing to its heading) or `aria-label`
- Scrollable lists: `role="region" aria-label="..."`

### Dynamic messages
- Status feedback (save confirmations, counts): `role="status"`
- Warnings and errors: `role="alert"`
- Do not use `aria-live` on container that exists on page load — only on dynamically shown/hidden content

### Touch targets and sizing
- Toggle pill buttons: minimum `py-2.5` (~36px)
- Modal action buttons: minimum `py-2.5`
- Never use `text-[11px]` — minimum is `text-xs` (12px)

### Icon-only buttons
- Every icon-only or glyph button must have `aria-label` describing its action (e.g., `aria-label="Show ingredient details"`)

### Disabled or placeholder links
- Use `aria-disabled="true" tabindex="-1"` and `title="Coming soon"` or similar
- Change `href` to `javascript:void(0)` so the link is not navigable

## Development Context

This is a professional tool targeting SoapCalc users who want better data structure and compliance support. The goal is clarity and speed, not visual flourish. When making decisions, prioritize:

- **Speed** - The formulation page must feel instant
- **Clarity** - A professional must understand output without a manual
- **Trust** - Data integrity is non-negotiable; bad SAP values cause harm
- **Auditability** - Every calculation must trace to versioned source data

Avoid:
- Visual flourish over functionality
- Speculative enterprise complexity
- Generalized regulation engines before demand exists

## graphify

This project has a graphify knowledge graph at graphify-out/.

Rules:
- Before answering architecture or codebase questions, read graphify-out/GRAPH_REPORT.md for god nodes and community structure
- If graphify-out/wiki/index.md exists, navigate it instead of reading raw files
- For cross-module "how does X relate to Y" questions, prefer `graphify query "<question>"`, `graphify path "<A>" "<B>"`, or `graphify explain "<concept>"` over grep — these traverse the graph's EXTRACTED + INFERRED edges instead of scanning files
- After modifying code files in this session, run `graphify update .` to keep the graph current (AST-only, no API cost)

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- filament/filament (FILAMENT) - v5
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== filament/filament rules ===

## Filament

- Filament is a Laravel UI framework built on Livewire, Alpine.js, and Tailwind CSS. UIs are defined in PHP via fluent, chainable components. Follow existing conventions in this app.
- Use the `search-docs` tool for official documentation on Artisan commands, code examples, testing, relationships, and idiomatic practices. If `search-docs` is unavailable, refer to https://filamentphp.com/docs.

### Artisan

- Always use Filament-specific Artisan commands to create files. Find available commands with the `list-artisan-commands` tool, or run `php artisan --help`.
- Inspect required options before running, and always pass `--no-interaction`.

### Patterns

Always use static `make()` methods to initialize components. Most configuration methods accept a `Closure` for dynamic values.

Use `Get $get` to read other form field values for conditional logic:

<code-snippet name="Conditional form field visibility" lang="php">
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

Select::make('type')
    ->options(CompanyType::class)
    ->required()
    ->live(),

TextInput::make('company_name')
    ->required()
    ->visible(fn (Get $get): bool => $get('type') === 'business'),

</code-snippet>

Use `Set $set` inside `->afterStateUpdated()` on a `->live()` field to mutate another field reactively. Prefer `->live(onBlur: true)` on text inputs to avoid per-keystroke updates:

<code-snippet name="Reactive field update" lang="php">
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

TextInput::make('title')
    ->required()
    ->live(onBlur: true)
    ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
        'slug',
        Str::slug($state ?? ''),
    )),

TextInput::make('slug')
    ->required(),

</code-snippet>

Compose layout by nesting `Section` and `Grid`. Children need explicit `->columnSpan()` or `->columnSpanFull()`:

<code-snippet name="Section and Grid layout" lang="php">
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

Section::make('Details')
    ->schema([
        Grid::make(2)->schema([
            TextInput::make('first_name')
                ->columnSpan(1),
            TextInput::make('last_name')
                ->columnSpan(1),
            TextInput::make('bio')
                ->columnSpanFull(),
        ]),
    ]),

</code-snippet>

Use `Repeater` for inline `HasMany` management. `->relationship()` with no args binds to the relationship matching the field name:

<code-snippet name="Repeater for HasMany" lang="php">
use Filament\Forms\Components\Repeater;

Repeater::make('qualifications')
    ->relationship()
    ->schema([
        TextInput::make('institution')
            ->required(),
        TextInput::make('qualification')
            ->required(),
    ])
    ->columns(2),

</code-snippet>

Use `state()` with a `Closure` to compute derived column values:

<code-snippet name="Computed table column value" lang="php">
use Filament\Tables\Columns\TextColumn;

TextColumn::make('full_name')
    ->state(fn (User $record): string => "{$record->first_name} {$record->last_name}"),

</code-snippet>

Use `SelectFilter` for enum or relationship filters, and `Filter` with a `->query()` closure for custom logic:

<code-snippet name="Table filters" lang="php">
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

SelectFilter::make('status')
    ->options(UserStatus::class),

SelectFilter::make('author')
    ->relationship('author', 'name'),

Filter::make('verified')
    ->query(fn (Builder $query) => $query->whereNotNull('email_verified_at')),

</code-snippet>

Actions are buttons that encapsulate optional modal forms and behavior:

<code-snippet name="Action with modal form" lang="php">
use Filament\Actions\Action;

Action::make('updateEmail')
    ->schema([
        TextInput::make('email')
            ->email()
            ->required(),
    ])
    ->action(fn (array $data, User $record) => $record->update($data)),

</code-snippet>

### Testing

Testing setup (requires `pestphp/pest-plugin-livewire` in `composer.json`):

- Always call `$this->actingAs(User::factory()->create())` before testing panel functionality.
- For edit pages, pass `['record' => $user->id]`, use `->call('save')` (not `->call('create')`), and do not assert `->assertRedirect()` (edit pages do not redirect after save).

<code-snippet name="Table test" lang="php">
use function Pest\Livewire\livewire;

livewire(ListUsers::class)
    ->assertCanSeeTableRecords($users)
    ->searchTable($users->first()->name)
    ->assertCanSeeTableRecords($users->take(1))
    ->assertCanNotSeeTableRecords($users->skip(1));

</code-snippet>

<code-snippet name="Create resource test" lang="php">
use function Pest\Laravel\assertDatabaseHas;

livewire(CreateUser::class)
    ->fillForm([
        'name' => 'Test',
        'email' => 'test@example.com',
    ])
    ->call('create')
    ->assertNotified()
    ->assertHasNoFormErrors()
    ->assertRedirect();

assertDatabaseHas(User::class, [
    'name' => 'Test',
    'email' => 'test@example.com',
]);

</code-snippet>

<code-snippet name="Edit resource test" lang="php">
livewire(EditUser::class, ['record' => $user->id])
    ->fillForm(['name' => 'Updated'])
    ->call('save')
    ->assertNotified()
    ->assertHasNoFormErrors();

assertDatabaseHas(User::class, [
    'id' => $user->id,
    'name' => 'Updated',
]);

</code-snippet>

<code-snippet name="Testing validation" lang="php">
livewire(CreateUser::class)
    ->fillForm([
        'name' => null,
        'email' => 'invalid-email',
    ])
    ->call('create')
    ->assertHasFormErrors([
        'name' => 'required',
        'email' => 'email',
    ])
    ->assertNotNotified();

</code-snippet>

Use `->callAction(DeleteAction::class)` for page actions, or `->callAction(TestAction::make('name')->table($record))` for table actions:

<code-snippet name="Calling actions" lang="php">
use Filament\Actions\Testing\TestAction;

livewire(ListUsers::class)
    ->callAction(TestAction::make('promote')->table($user), [
        'role' => 'admin',
    ])
    ->assertNotified();

</code-snippet>

### Correct Namespaces

- Form fields (`TextInput`, `Select`, `Repeater`, etc.): `Filament\Forms\Components\`
- Infolist entries (`TextEntry`, `IconEntry`, etc.): `Filament\Infolists\Components\`
- Layout components (`Grid`, `Section`, `Fieldset`, `Tabs`, `Wizard`, etc.): `Filament\Schemas\Components\`
- Schema utilities (`Get`, `Set`, etc.): `Filament\Schemas\Components\Utilities\`
- Table columns (`TextColumn`, `IconColumn`, etc.): `Filament\Tables\Columns\`
- Table filters (`SelectFilter`, `Filter`, etc.): `Filament\Tables\Filters\`
- Actions (`DeleteAction`, `CreateAction`, etc.): `Filament\Actions\`. Never use `Filament\Tables\Actions\`, `Filament\Forms\Actions\`, or any other sub-namespace for actions.
- Icons: `Filament\Support\Icons\Heroicon` enum (e.g., `Heroicon::PencilSquare`)

### Common Mistakes

- **Never assume public file visibility.** File visibility is `private` by default. Always use `->visibility('public')` when public access is needed.
- **Never assume full-width layout.** `Grid`, `Section`, `Fieldset`, and `Repeater` do not span all columns by default.
- **Use `Select::make('author_id')->relationship('author', 'name')` for BelongsTo fields.** `BelongsToSelect` does not exist in v4.
- **`Repeater` uses `->schema()`, not `->fields()`.**
- **Never add `->dehydrated(false)` to fields that need to be saved.** It strips the value from form state before `->action()` or the save handler runs. Only use it for helper/UI-only fields.
- **Use correct property types when overriding `Page`, `Resource`, and `Widget` properties.** These properties have union types or changed modifiers that must be preserved:
  - `$navigationIcon`: `protected static string | BackedEnum | null` (not `?string`)
  - `$navigationGroup`: `protected static string | UnitEnum | null` (not `?string`)
  - `$view`: `protected string` (not `protected static string`) on `Page` and `Widget` classes

=== laraveldaily/filacheck rules ===

## laraveldaily/filacheck

- After you have created/modified any files in `app/Filament` folder, you must run `vendor/bin/filacheck --fix`, to ensure there is no deprecated Filament code. Reported not fixed issues MUST be fixed before continuing.

</laravel-boost-guidelines>
