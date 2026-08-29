# Workspace Ingredient Guidance Overrides Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a workspace replace the platform guidance for an active platform ingredient with one language-agnostic, 2,000-character Markdown value, and return to the current localized platform guidance at any time.

**Architecture:** Keep platform `Ingredient::info_markdown` and `IngredientTranslation::info_markdown` canonical. Store workspace overrides in a separate table keyed by workspace and platform ingredient. A dedicated service owns authorization, normalization, validation, audit attribution, effective-value resolution, and reset; the ingredient editor delegates to it. Catalogue consolidation explicitly reconciles overrides per workspace, while enrichment and localization continue to touch only platform records.

**Tech Stack:** PHP 8.5, Laravel 13.29, Livewire 4.4, Filament 5.7, Pest, Alpine.js, Laravel Truss.

---

## Product invariants

- The override exists only for active platform ingredients (`owner_type`, `owner_id`, and `workspace_id` are all null).
- Workspace-owned ingredients continue to use their existing editable `ingredients.info_markdown` field.
- The stored override has no locale column and is shown verbatim in every locale until reset.
- Without an override, effective guidance is resolved live through `Ingredient::localizedInfoMarkdown($locale)`.
- Starting customization copies the currently displayed localized platform guidance into the editor but does not persist it.
- Saving trims the outer whitespace, requires non-empty text, counts Unicode characters, limits the value to 2,000 characters, and rejects raw HTML tags.
- Owners, admins, and editors may save or reset. Viewers and non-members may read the effective guidance but cannot mutate it.
- Reset deletes the override row; it does not copy platform text into workspace storage.
- Platform enrichment and localization never read or write workspace overrides.
- Catalogue merge reconciliation is per workspace: source-only moves, target-only stays, identical values deduplicate, and differing values abort the transaction.
- Images are outside this change.

## File map

**Create**

- `app/Models/WorkspaceIngredientGuidance.php` — override persistence model and audit relationships.
- `app/Services/WorkspaceIngredientGuidanceService.php` — effective-value resolver and sole write boundary.
- `database/factories/WorkspaceIngredientGuidanceFactory.php` — focused test data.
- `database/migrations/2026_08_29_120000_create_workspace_ingredient_guidances_table.php` — tenant-safe storage and constraints. If Artisan emits a different same-day timestamp, keep its generated filename and use that filename in subsequent commands.
- `tests/Feature/WorkspaceIngredientGuidanceTest.php` — persistence, resolution, validation, authorization, audit, and reset coverage.

**Modify**

- `app/Models/Ingredient.php` — `workspaceGuidances()` relationship only.
- `app/Models/Workspace.php` — `ingredientGuidances()` relationship only.
- `app/Livewire/Dashboard/IngredientEditor.php` — editor state and save/reset actions.
- `resources/views/livewire/dashboard/ingredient-editor.blade.php` — effective guidance card and customization form.
- `app/Services/IngredientCatalogConsolidationService.php` — merge reconciliation.
- `tests/Feature/UserIngredientAuthoringTest.php` — browser-facing Livewire behavior and role boundary.
- `tests/Feature/IngredientCatalogConsolidationTest.php` — all four merge cases.
- `tests/Feature/IngredientGuidanceBatchReviewTest.php` — enrichment/apply isolation regression.
- `tests/Feature/PlatformIngredientDeletionTest.php` — cascade cleanup regression.
- `lang/en/ingredients.php` — English interface copy and validation messages.
- `database/seeders/data/interface-translations.json` — reviewed translations for every new owned key.
- `tests/Feature/InterfaceTranslationCatalogueTest.php` — exact catalogue coverage for the new copy.

## Task 1: Prepare and verify the isolated worktree

- [ ] Confirm the branch and cleanliness.

Run from `/Users/philippe/Herd/koskalk/.worktrees/workspace-ingredient-guidance-overrides`:

```bash
git branch --show-current
git status --short
```

Expected: branch `codex/workspace-ingredient-guidance-overrides`; only this plan is untracked before it is committed.

- [ ] Make the existing local runtime available without installing or changing dependencies.

```bash
ln -s /Users/philippe/Herd/koskalk/vendor vendor
ln -s /Users/philippe/Herd/koskalk/.env .env
```

Expected: both symlinks are ignored by Git. If either path already exists, leave it unchanged.

- [ ] Re-read repository guidance before implementation.

```bash
sed -n '1,240p' .ai/rules/index.md
grep -rin 'guidance\|ingredient\|workspace\|markdown\|migration\|livewire\|translation\|test' .ai/rules
```

Read every mapped rule for the files in the file map. Before writing tests, read the `testing-best-practices` skill in full. Before Laravel/Livewire implementation, use Laravel Boost `search-docs` with `['validation unicode max string', 'database transaction lockForUpdate', 'livewire locked properties actions validation', 'markdown html input strip unsafe links']` scoped to the installed packages.

- [ ] Verify the baseline focused suites before introducing a failing test.

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientCatalogConsolidationTest.php tests/Feature/IngredientGuidanceBatchReviewTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: PASS. If a baseline test fails, stop and report the pre-existing failure before changing code.

- [ ] Commit the approved plan.

```bash
git add docs/superpowers/plans/2026-08-29-workspace-ingredient-guidance-overrides.md
git commit -m "docs: plan workspace ingredient guidance overrides"
```

## Task 2: Add constrained override persistence

**Files:** create the model, factory, migration, and feature test; modify `Ingredient.php` and `Workspace.php`.

- [ ] Generate the model, factory, migration, and Pest feature test with Laravel commands.

```bash
php artisan make:model WorkspaceIngredientGuidance --factory --migration --no-interaction
php artisan make:test --pest WorkspaceIngredientGuidanceTest --no-interaction
```

- [ ] Write the first failing persistence test in `tests/Feature/WorkspaceIngredientGuidanceTest.php`.

```php
<?php

use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientGuidance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores one audited guidance override per workspace and platform ingredient', function (): void {
    $creator = User::factory()->create();
    $updater = User::factory()->create();
    $workspace = Workspace::factory()->for($creator, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
    ]);

    $override = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'guidance_markdown' => '## Overview',
        'created_by_user_id' => $creator->id,
        'updated_by_user_id' => $updater->id,
    ]);

    expect(Schema::hasColumns('workspace_ingredient_guidances', [
        'workspace_id',
        'ingredient_id',
        'guidance_markdown',
        'created_by_user_id',
        'updated_by_user_id',
    ]))->toBeTrue()
        ->and($override->workspace->is($workspace))->toBeTrue()
        ->and($override->ingredient->is($ingredient))->toBeTrue()
        ->and($override->creator->is($creator))->toBeTrue()
        ->and($override->updater->is($updater))->toBeTrue()
        ->and($workspace->ingredientGuidances->contains($override))->toBeTrue()
        ->and($ingredient->workspaceGuidances->contains($override))->toBeTrue();
});
```

- [ ] Run the new test to prove it fails for the missing table/model behavior.

```bash
php artisan test --compact tests/Feature/WorkspaceIngredientGuidanceTest.php
```

Expected: FAIL because the generated persistence layer is not implemented.

- [ ] Implement the generated migration with the exact columns and constraints.

```php
Schema::create('workspace_ingredient_guidances', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
    $table->text('guidance_markdown');
    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    $table->unique(
        ['workspace_id', 'ingredient_id'],
        'workspace_ingredient_guidances_workspace_ingredient_unique',
    );
});
```

The `down()` method must call `Schema::dropIfExists('workspace_ingredient_guidances')`.

- [ ] Implement `WorkspaceIngredientGuidance` with explicit fillable attributes and unscoped belongs-to relations.

```php
<?php

namespace App\Models;

use Database\Factories\WorkspaceIngredientGuidanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'ingredient_id',
    'guidance_markdown',
    'created_by_user_id',
    'updated_by_user_id',
])]
class WorkspaceIngredientGuidance extends Model
{
    /** @use HasFactory<WorkspaceIngredientGuidanceFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withoutGlobalScopes();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
```

- [ ] Implement the factory using active platform ingredients by default.

```php
public function definition(): array
{
    return [
        'workspace_id' => Workspace::factory(),
        'ingredient_id' => Ingredient::factory()->state([
            'owner_type' => null,
            'owner_id' => null,
            'workspace_id' => null,
            'is_active' => true,
        ]),
        'guidance_markdown' => "## Overview\n\n".fake()->paragraph(),
        'created_by_user_id' => null,
        'updated_by_user_id' => null,
    ];
}
```

- [ ] Add `workspaceGuidances(): HasMany` to `Ingredient` and `ingredientGuidances(): HasMany` to `Workspace`.

```php
public function workspaceGuidances(): HasMany
{
    return $this->hasMany(WorkspaceIngredientGuidance::class);
}
```

```php
public function ingredientGuidances(): HasMany
{
    return $this->hasMany(WorkspaceIngredientGuidance::class);
}
```

- [ ] Add database constraint regressions: duplicate workspace/ingredient insertion fails, workspace deletion cascades, ingredient deletion cascades, and deleting either audit user sets only that audit foreign key to null.

- [ ] Run the test, format, and commit.

```bash
php artisan test --compact tests/Feature/WorkspaceIngredientGuidanceTest.php
vendor/bin/pint --dirty --format agent
git add app/Models/Ingredient.php app/Models/Workspace.php app/Models/WorkspaceIngredientGuidance.php database/factories/WorkspaceIngredientGuidanceFactory.php database/migrations tests/Feature/WorkspaceIngredientGuidanceTest.php
git commit -m "feat: store workspace ingredient guidance overrides"
```

Expected: PASS.

## Task 3: Implement the effective-guidance service and write boundary

**Files:** create `WorkspaceIngredientGuidanceService.php`; extend `WorkspaceIngredientGuidanceTest.php`.

- [ ] Add failing resolver tests that prove the locale semantics.

Create an English platform value and a French `IngredientTranslation`. Assert:

```php
expect($service->effectiveGuidance($workspace, $ingredient, 'fr'))
    ->toBe('Conseils de la plateforme');

WorkspaceIngredientGuidance::factory()->create([
    'workspace_id' => $workspace->id,
    'ingredient_id' => $ingredient->id,
    'guidance_markdown' => 'Workspace text in any language',
]);

expect($service->effectiveGuidance($workspace, $ingredient, 'fr'))
    ->toBe('Workspace text in any language')
    ->and($service->effectiveGuidance($workspace, $ingredient, 'de'))
    ->toBe('Workspace text in any language');
```

Also assert a workspace-owned ingredient ignores any override query and returns its own `localizedInfoMarkdown()` value.

- [ ] Add failing mutation tests for every business rule.

Cover these exact cases:

- owner creates an override and outer whitespace is trimmed;
- editor updates it, `created_by_user_id` remains the owner, and `updated_by_user_id` becomes the editor;
- admin can reset it;
- viewer and non-member receive `AuthorizationException` on save and reset;
- empty-after-trim, 2,001 Unicode characters, and `<script>alert(1)</script>` each produce a `ValidationException` on `guidance_markdown`;
- exactly 2,000 Unicode characters succeeds;
- Markdown such as headings, lists, emphasis, and links succeeds;
- inactive platform and workspace-owned ingredients are rejected;
- reset removes the row and immediately exposes the current locale’s platform guidance.

- [ ] Run only this test file and confirm the new cases fail because the service is absent.

```bash
php artisan test --compact tests/Feature/WorkspaceIngredientGuidanceTest.php
```

- [ ] Create the service with this public API.

```php
final class WorkspaceIngredientGuidanceService
{
    public const MAX_LENGTH = 2000;

    public function overrideFor(
        Workspace $workspace,
        Ingredient $ingredient,
    ): ?WorkspaceIngredientGuidance;

    public function effectiveGuidance(
        Workspace $workspace,
        Ingredient $ingredient,
        ?string $locale = null,
    ): ?string;

    public function save(
        User $actor,
        Workspace $workspace,
        Ingredient $ingredient,
        ?string $guidanceMarkdown,
    ): WorkspaceIngredientGuidance;

    public function reset(
        User $actor,
        Workspace $workspace,
        Ingredient $ingredient,
    ): void;
}
```

- [ ] Implement effective resolution without changing the `Ingredient` localization method.

```php
public function effectiveGuidance(
    Workspace $workspace,
    Ingredient $ingredient,
    ?string $locale = null,
): ?string {
    if (! $this->isPlatformIngredient($ingredient)) {
        return $ingredient->localizedInfoMarkdown($locale);
    }

    return $this->overrideFor($workspace, $ingredient)?->guidance_markdown
        ?? $ingredient->localizedInfoMarkdown($locale);
}
```

- [ ] Implement write authorization once and call it before both save and reset.

```php
private function assertWritable(User $actor, Workspace $workspace, Ingredient $ingredient): void
{
    if (! in_array($workspace->roleFor($actor), [
        WorkspaceMemberRole::Owner,
        WorkspaceMemberRole::Admin,
        WorkspaceMemberRole::Editor,
    ], true)) {
        throw new AuthorizationException;
    }

    if (! $this->isPlatformIngredient($ingredient) || ! $ingredient->is_active) {
        throw ValidationException::withMessages([
            'guidance_markdown' => __('ingredients.editor.validation.workspace_guidance_forbidden'),
        ]);
    }
}

private function isPlatformIngredient(Ingredient $ingredient): bool
{
    return $ingredient->owner_type === null
        && $ingredient->owner_id === null
        && $ingredient->workspace_id === null;
}
```

- [ ] Normalize and validate with Laravel validation so `max:2000` uses multibyte string length. Reject raw HTML tags with a closure; do not reject ordinary comparison characters.

```php
$normalized = trim((string) $guidanceMarkdown);

$validated = validator(
    ['guidance_markdown' => $normalized],
    [
        'guidance_markdown' => [
            'required',
            'string',
            'max:'.self::MAX_LENGTH,
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strip_tags($value) !== $value) {
                    $fail(__('ingredients.editor.validation.workspace_guidance_html'));
                }
            },
        ],
    ],
    [
        'guidance_markdown.required' => __('ingredients.editor.validation.workspace_guidance_required'),
        'guidance_markdown.max' => __('ingredients.editor.validation.workspace_guidance_max', [
            'max' => self::MAX_LENGTH,
        ]),
    ],
)->validate();
```

Import `Closure` explicitly. Compare with `strip_tags()` only to detect markup; store the original validated Markdown and never silently transform it.

- [ ] Persist audit attribution transactionally while preserving the original creator.

```php
return DB::transaction(function () use ($actor, $ingredient, $validated, $workspace): WorkspaceIngredientGuidance {
    $override = WorkspaceIngredientGuidance::query()
        ->where('workspace_id', $workspace->id)
        ->where('ingredient_id', $ingredient->id)
        ->lockForUpdate()
        ->first() ?? new WorkspaceIngredientGuidance([
            'workspace_id' => $workspace->id,
            'ingredient_id' => $ingredient->id,
            'created_by_user_id' => $actor->id,
        ]);

    $override->guidance_markdown = $validated['guidance_markdown'];
    $override->updated_by_user_id = $actor->id;
    $override->save();

    return $override;
}, attempts: 5);
```

`reset()` must lock and delete the matching row in a transaction. It must be idempotent after authorization and ingredient validation.

- [ ] Run, format, and commit.

```bash
php artisan test --compact tests/Feature/WorkspaceIngredientGuidanceTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/WorkspaceIngredientGuidanceService.php tests/Feature/WorkspaceIngredientGuidanceTest.php
git commit -m "feat: resolve and manage workspace ingredient guidance"
```

Expected: PASS.

## Task 4: Add the ingredient editor customization flow

**Files:** modify `IngredientEditor.php`, its Blade view, English copy, and `UserIngredientAuthoringTest.php`.

- [ ] Add failing Livewire tests for the end-to-end state transitions.

For an editor in the active workspace and an active platform ingredient with English and French platform guidance, set `app()->setLocale('fr')` and assert:

1. the effective card shows the French platform guidance;
2. `workspaceGuidanceMarkdown` begins null and `isEditingWorkspaceGuidance` is false;
3. `startWorkspaceGuidanceCustomization` pre-fills the French text without creating a database row;
4. `saveWorkspaceGuidance` stores the edited value, exits edit mode, and updates the card;
5. changing the app locale to English still displays the saved override exactly;
6. `resetWorkspaceGuidance` deletes the row and the card resumes the current English platform value.

Add separate tests that a viewer sees the effective card without customize/reset controls and cannot call either mutation action. Add a private-ingredient test asserting no workspace override card is rendered and the existing `data.info_markdown` form continues to save normally.

- [ ] Run the Livewire test file to establish RED.

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter="workspace ingredient guidance"
```

Expected: FAIL because state, actions, and UI do not exist.

- [ ] Add these component properties.

```php
public ?string $workspaceGuidanceMarkdown = null;

public bool $isEditingWorkspaceGuidance = false;
```

Do not store platform guidance in a public Livewire property. Re-resolve it server-side from the current ingredient, workspace, and locale.

- [ ] Inject `WorkspaceIngredientGuidanceService` into `mount()` and initialize only saved override state.

```php
$workspace = $this->workspaceForIngredientSettings($ingredient);
$override = $ingredient instanceof Ingredient
    && $ingredient->owner_type === null
    && $workspace instanceof Workspace
        ? $workspaceIngredientGuidances->overrideFor($workspace, $ingredient)
        : null;

$this->workspaceGuidanceMarkdown = $override?->guidance_markdown;
```

Rename the private helper `workspaceForMaterialCode()` to `workspaceForIngredientSettings()` and update all existing call sites because it now resolves both workspace-owned settings. Keep material-code behavior unchanged.

- [ ] Add start, cancel, save, and reset actions.

```php
public function startWorkspaceGuidanceCustomization(
    WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
): void {
    $context = $this->workspaceGuidanceWriteContext();

    if ($context === null) {
        $this->addError('workspaceGuidanceMarkdown', __('ingredients.editor.validation.workspace_guidance_forbidden'));

        return;
    }

    [$user, $workspace, $ingredient] = $context;

    if (! $this->canEditWorkspaceGuidance()) {
        $this->addError('workspaceGuidanceMarkdown', __('ingredients.editor.validation.workspace_guidance_forbidden'));

        return;
    }

    $this->workspaceGuidanceMarkdown = $workspaceIngredientGuidances
        ->effectiveGuidance($workspace, $ingredient, app()->getLocale());
    $this->isEditingWorkspaceGuidance = true;
    $this->resetErrorBag('workspaceGuidanceMarkdown');
}
```

`cancelWorkspaceGuidanceCustomization()` must reload the saved override (or null), clear errors, and exit edit mode. `saveWorkspaceGuidance()` and `resetWorkspaceGuidance()` must delegate to the service, translate `ValidationException` errors onto `workspaceGuidanceMarkdown`, translate `AuthorizationException` to the forbidden message, refresh component state, exit edit mode on success, and dispatch the normal app notification.

Extract a typed private context helper returning `array{User, Workspace, Ingredient}|null` and use the same role list as the service in `canEditWorkspaceGuidance()`. The service remains authoritative; the component guard only controls presentation and friendly errors.

- [ ] Pass only trustworthy derived display data from `render()`.

```php
$workspace = $this->workspaceForIngredientSettings($ingredient);
$workspaceGuidanceOverride = $ingredient instanceof Ingredient
    && $ingredient->owner_type === null
    && $workspace instanceof Workspace
        ? app(WorkspaceIngredientGuidanceService::class)->overrideFor($workspace, $ingredient)
        : null;
$effectiveWorkspaceGuidance = $ingredient instanceof Ingredient
    && $ingredient->owner_type === null
    && $workspace instanceof Workspace
        ? app(WorkspaceIngredientGuidanceService::class)->effectiveGuidance(
            $workspace,
            $ingredient,
            app()->getLocale(),
        )
        : null;
```

Add `workspaceGuidanceOverride`, `effectiveWorkspaceGuidance`, and `canEditWorkspaceGuidance` to the view data. Do not expose user-controlled derived state.

- [ ] Render a platform-only guidance card before the material-code card.

Display the source badge as either “Platform guidance” or “Workspace guidance.” Render Markdown using:

```php
{!! Str::markdown($effectiveWorkspaceGuidance, [
    'html_input' => 'strip',
    'allow_unsafe_links' => false,
]) !!}
```

Keep this inside the existing prose/card styles. Even though raw HTML is rejected on save, output sanitization is mandatory for defense in depth and legacy/imported rows.

When editing, use a textarea with both browser and server constraints and an Alpine Unicode-aware counter:

```blade
<div x-data="{ value: $wire.entangle('workspaceGuidanceMarkdown').live }">
    <textarea
        id="workspace-guidance-markdown"
        x-model="value"
        maxlength="2000"
        rows="12"
        class="sk-field-control w-full"
        aria-describedby="workspace-guidance-help workspace-guidance-count"
        aria-invalid="{{ $errors->has('workspaceGuidanceMarkdown') ? 'true' : 'false' }}"
    ></textarea>
    <p id="workspace-guidance-count" aria-live="polite">
        <span x-text="Array.from(value ?? '').length"></span>/2000
    </p>
</div>
```

Use `Array.from()` so the counter counts Unicode code points rather than UTF-16 code units. The entangled Alpine value is the single textarea binding; do not add a duplicate `wire:model` binding.

Show Customize when no override, Edit and “Use platform guidance” when an override exists, Save/Cancel in edit mode, and no mutation buttons for viewers. Reset must use a confirmation prompt through the project’s existing button/action pattern.

- [ ] Add English keys under `ingredients.editor.workspace_guidance` and `ingredients.editor.validation`.

Required workspace-guidance keys:

```php
'workspace_guidance' => [
    'eyebrow' => 'Workspace content',
    'heading' => 'Ingredient guidance',
    'platform_badge' => 'Platform guidance',
    'override_badge' => 'Workspace guidance',
    'helper' => 'Use the platform guidance or replace it for everyone in this workspace.',
    'read_only' => 'Only workspace owners, admins, and editors can change this guidance.',
    'customize' => 'Customize guidance',
    'edit' => 'Edit guidance',
    'save' => 'Save guidance',
    'cancel' => 'Cancel',
    'reset' => 'Use platform guidance',
    'reset_confirm' => 'Remove the workspace guidance and use the current platform guidance?',
    'count' => ':count/:max characters',
    'saved' => 'Workspace guidance saved.',
    'reset_done' => 'Platform guidance restored.',
],
```

Required validation keys:

```php
'workspace_guidance_forbidden' => 'This ingredient guidance cannot be changed in this workspace.',
'workspace_guidance_required' => 'Enter ingredient guidance before saving.',
'workspace_guidance_max' => 'Ingredient guidance may not exceed :max characters.',
'workspace_guidance_html' => 'Use Markdown instead of raw HTML.',
```

- [ ] Run the complete Livewire file, format, and commit.

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php
vendor/bin/pint --dirty --format agent
git add app/Livewire/Dashboard/IngredientEditor.php resources/views/livewire/dashboard/ingredient-editor.blade.php lang/en/ingredients.php tests/Feature/UserIngredientAuthoringTest.php
git commit -m "feat: customize platform ingredient guidance by workspace"
```

Expected: PASS.

## Task 5: Reconcile overrides during catalogue changes

**Files:** modify consolidation service/tests and `PlatformIngredientDeletionTest.php`.

- [ ] Add four failing consolidation tests using two platform ingredients and `WorkspaceIngredientGuidanceFactory`.

Assert:

- source-only row changes its `ingredient_id` to target;
- target-only row remains untouched;
- same-workspace identical source/target text deletes source and retains target;
- same-workspace differing text throws `RuntimeException` containing `workspace ingredient guidance conflict`, and the outer transaction preserves both ingredients and both rows.

- [ ] Run the focused merge cases to establish RED.

```bash
php artisan test --compact tests/Feature/IngredientCatalogConsolidationTest.php --filter="workspace ingredient guidance"
```

- [ ] Import `WorkspaceIngredientGuidance`, call `mergeWorkspaceGuidances($source, $target)` after material codes and before prices, and implement the same per-workspace locking pattern.

```php
private function mergeWorkspaceGuidances(Ingredient $source, Ingredient $target): void
{
    $guidances = WorkspaceIngredientGuidance::query()
        ->whereIn('ingredient_id', [$source->id, $target->id])
        ->orderBy('workspace_id')
        ->lockForUpdate()
        ->get()
        ->groupBy('workspace_id');

    foreach ($guidances as $workspaceId => $workspaceGuidances) {
        $sourceGuidance = $workspaceGuidances->firstWhere('ingredient_id', $source->id);

        if (! $sourceGuidance instanceof WorkspaceIngredientGuidance) {
            continue;
        }

        $targetGuidance = $workspaceGuidances->firstWhere('ingredient_id', $target->id);

        if (! $targetGuidance instanceof WorkspaceIngredientGuidance) {
            $sourceGuidance->update(['ingredient_id' => $target->id]);

            continue;
        }

        if ($sourceGuidance->guidance_markdown === $targetGuidance->guidance_markdown) {
            $sourceGuidance->delete();

            continue;
        }

        throw new RuntimeException(
            "Cannot merge {$source->catalog_key} into {$target->catalog_key}: workspace ingredient guidance conflict for workspace {$workspaceId}.",
        );
    }
}
```

Do not add guidance rows to the destructive removal blockers. The foreign key deliberately cascades after the existing deletion workflow authorizes deletion.

- [ ] Add a deletion regression to the existing platform deletion service test: create an override, perform an already-authorized platform deletion, and assert the override row no longer exists.

- [ ] Run, format, and commit.

```bash
php artisan test --compact tests/Feature/IngredientCatalogConsolidationTest.php tests/Feature/PlatformIngredientDeletionTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientCatalogConsolidationService.php tests/Feature/IngredientCatalogConsolidationTest.php tests/Feature/PlatformIngredientDeletionTest.php
git commit -m "feat: reconcile workspace guidance during catalogue changes"
```

## Task 6: Prove enrichment isolation

**Files:** modify `IngredientGuidanceBatchReviewTest.php` only unless the test exposes an actual production coupling.

- [ ] Add a failing-regression test around the existing accepted guidance apply flow.

Create a platform ingredient, French translation, workspace, and override. Apply a reviewed platform guidance change through the same service/action already used by neighboring tests. Assert all three outcomes:

```php
expect($ingredient->fresh()->info_markdown)->toBe($newEnglishPlatformGuidance)
    ->and($ingredient->translations()->where('locale', 'fr')->value('info_markdown'))
    ->toBe($newFrenchPlatformGuidance)
    ->and($override->fresh()->guidance_markdown)
    ->toBe('Workspace-authored guidance');
```

- [ ] Run the regression test. It should pass without production changes because enrichment has no relationship to the new table.

```bash
php artisan test --compact tests/Feature/IngredientGuidanceBatchReviewTest.php --filter="workspace override"
```

Expected: PASS. A failure means an unintended coupling exists; diagnose it before changing enrichment code.

- [ ] Commit the regression.

```bash
git add tests/Feature/IngredientGuidanceBatchReviewTest.php
git commit -m "test: keep workspace guidance outside enrichment"
```

## Task 7: Add reviewed interface translations

**Files:** modify the translation catalogue and its test.

- [ ] Add a failing catalogue test that enumerates every new full key and asserts locale order `['de', 'es', 'fr', 'it', 'nl', 'pt_BR']` with non-empty reviewed values.

```php
it('commits reviewed workspace ingredient guidance copy', function (): void {
    $catalogue = app(InterfaceTranslationCatalogue::class)
        ->read(database_path('seeders/data/interface-translations.json'));
    $translations = collect($catalogue['translations'])
        ->keyBy(fn (array $row): string => $row['group'].'.'.$row['key']);

    foreach ([
        'ingredients.editor.workspace_guidance.eyebrow',
        'ingredients.editor.workspace_guidance.heading',
        'ingredients.editor.workspace_guidance.platform_badge',
        'ingredients.editor.workspace_guidance.override_badge',
        'ingredients.editor.workspace_guidance.helper',
        'ingredients.editor.workspace_guidance.read_only',
        'ingredients.editor.workspace_guidance.customize',
        'ingredients.editor.workspace_guidance.edit',
        'ingredients.editor.workspace_guidance.save',
        'ingredients.editor.workspace_guidance.cancel',
        'ingredients.editor.workspace_guidance.reset',
        'ingredients.editor.workspace_guidance.reset_confirm',
        'ingredients.editor.workspace_guidance.count',
        'ingredients.editor.workspace_guidance.saved',
        'ingredients.editor.workspace_guidance.reset_done',
        'ingredients.editor.validation.workspace_guidance_forbidden',
        'ingredients.editor.validation.workspace_guidance_required',
        'ingredients.editor.validation.workspace_guidance_max',
        'ingredients.editor.validation.workspace_guidance_html',
    ] as $fullKey) {
        expect($translations)->toHaveKey($fullKey)
            ->and(array_keys($translations[$fullKey]['text']))
            ->toBe(['de', 'es', 'fr', 'it', 'nl', 'pt_BR']);

        foreach ($translations[$fullKey]['text'] as $text) {
            expect(trim((string) $text))->not->toBe('');
        }
    }
});
```

- [ ] Run the test to prove the catalogue entries are missing.

```bash
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php --filter="workspace ingredient guidance copy"
```

Expected: FAIL on the first missing key.

- [ ] Add professionally reviewed German, Spanish, French, Italian, Dutch, and Brazilian Portuguese text for all 19 keys in `database/seeders/data/interface-translations.json`.

Keep the file’s deterministic sort order: group first, key second, and locale order exactly `de`, `es`, `fr`, `it`, `nl`, `pt_BR`. Preserve `:count` and `:max` placeholders exactly where used. Do not machine-fill untranslated English duplicates merely to satisfy the test.

- [ ] Run catalogue tests and commit.

```bash
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php
git add database/seeders/data/interface-translations.json tests/Feature/InterfaceTranslationCatalogueTest.php
git commit -m "feat: translate workspace ingredient guidance controls"
```

Expected: PASS.

## Task 8: Migrate, verify, and refresh architecture artifacts

- [ ] Run every focused feature suite affected by the implementation.

```bash
php artisan test --compact tests/Feature/WorkspaceIngredientGuidanceTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientCatalogConsolidationTest.php tests/Feature/IngredientGuidanceBatchReviewTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: PASS.

- [ ] Format changed PHP and Blade files, then rerun the focused suites if Pint changes anything.

```bash
vendor/bin/pint --dirty --format agent
git status --short
```

No `app/Filament` file is in scope, so Filacheck is not required. If implementation unexpectedly touches `app/Filament`, run `vendor/bin/filacheck --fix` and fix every remaining report.

- [ ] Apply the migration to the local development database and inspect the real schema.

```bash
php artisan migrate --no-interaction
php artisan truss:diff
php artisan truss:export --format=llm --focus=workspace_ingredient_guidances --depth=1 --compact
```

Expected schema: one composite unique key, cascading workspace/ingredient foreign keys, nullable null-on-delete audit foreign keys, and a text guidance column.

- [ ] Refresh the local architecture graph after all code changes.

```bash
graphify update .
```

Inspect `graphify-out/GRAPH_REPORT.md` and ensure the new model/service are connected to the ingredient editor and consolidation service. Commit tracked graph artifacts only if this repository normally tracks the generated changes.

- [ ] Inspect the final diff and scan for incomplete plan artifacts.

```bash
git diff --check
git status --short
git diff --stat main...HEAD
rg -n "TODO|TBD|FIXME|placeholder" app/Models/WorkspaceIngredientGuidance.php app/Services/WorkspaceIngredientGuidanceService.php app/Livewire/Dashboard/IngredientEditor.php resources/views/livewire/dashboard/ingredient-editor.blade.php tests/Feature/WorkspaceIngredientGuidanceTest.php
```

Expected: no whitespace errors, no incomplete markers, no unrelated files.

- [ ] Review the implementation against every product invariant at the top of this plan, then commit any formatting or graph updates.

```bash
git add -u
git commit -m "chore: finalize workspace ingredient guidance overrides"
```

Skip this commit when there are no remaining tracked changes.

- [ ] Ask the user to run the complete suite, as required by the repository test policy.

```bash
php artisan test --compact
```

Do not claim merge readiness until the user reports the full suite result or explicitly authorizes this implementation task to run it.
