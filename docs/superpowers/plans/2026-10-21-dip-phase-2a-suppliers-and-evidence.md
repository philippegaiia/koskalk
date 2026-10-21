# DIP Phase 2a — Suppliers, Qualification and Evidence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a DIP workspace qualify suppliers per ingredient and keep their documents as retained, fingerprinted evidence — from a DIP « Matières premières » page, without requiring Production Bench — and let a supplier's safety assessment support a private ingredient's coverage.

**Architecture:** One workspace supplier directory is shared by Production Bench and the DIP module: a `SupplierDirectoryAccess` service allows supplier creation to anyone who may edit either module, and `SaveSupplier` uses it; purchasing (listings, prices, orders, stock) stays behind Production Bench. Supplier *qualification* (ingredient × supplier × supplied grade/reference, with a status) is a DIP record. Evidence files are stored on a private disk in their own append-only tables (never the media library), fingerprinted with SHA-256, replaced rather than deleted, and downloaded through an authorized controller.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4, Filament 5 schemas in public Livewire components, Pest 4.

**Spec:** `docs/superpowers/specs/2026-10-21-product-information-file-dip-design.md` §3 (and §2.2 supporting evidence). Phase 2 is split: **2a** (this plan) and **2b** (structured summaries of documents, generated annexes b/l/o/t, one-time folder importer).

---

## Before you start

- Read `.ai/rules/index.md` and every rule file matching the paths you touch (`app.md`, `actions.md`, `actions-services.md`, `models.md`, `models-services.md`, `migrations.md`, `livewire.md`, `forms.md`, `lang.md`, `routes.md`, `controllers.md`, `http.md`, `views.md`, `tests.md`, `services.md`).
- `export LANG=en_US.UTF-8` before running tests.
- After PHP changes: `vendor/bin/pint --dirty --format agent`. After `app/Filament` changes: `vendor/bin/filacheck --fix`.
- Do **not** run migrations against the owner's local database during the tasks; tests use SQLite. Task 9 migrates the local database at the end.

## Decisions taken while planning

1. **Shared supplier directory** (agreed with the owner, 2026-10-21): `SupplierDirectoryAccess::canManage()` = Production Bench writable **or** DIP module editable. Supplier *creation* from DIP reuses the same fields (`App\Forms\Components\SupplierFields`) and the same `SaveSupplier` action. Supplier deletion stays a Production Bench action; it now deactivates instead of deleting when a qualification references the supplier.
2. **Evidence storage is not the media library.** The media library processes images, caps PDFs at 180 KB, counts against plan media allowances and allows deletion. Evidence files: private disk from `config('dossiers.evidence_disk')` (default `local`), max 10 MB, PDF/JPEG/PNG, SHA-256, never deleted by the application; a newer upload *replaces* an item (the old one is retired and points to its replacement).
3. **Evidence types carry their annex letter and attachment level** (supplier qualification, product, or workspace). Phase 2a's UI handles supplier-level documents only; product-level and workspace-level evidence are supported by the model and service and get their screens with the dossier (phase 4).
4. **Supplier assessments as coverage evidence apply to private ingredients only.** Platform coverage is platform-owned and cannot rest on one workspace's supplier documents. A supplier assessment counts when its item is of type `supplier_safety_assessment`, not retired, and attached to a **validated** qualification of the same ingredient.
5. **The « Matières premières » page lists** the workspace's ingredients that appear in any of its formulas or already have a qualification. The sidebar's "Compliance — coming soon" entry becomes a link to it when the DIP module is available.

## File structure

| Area | Files |
| --- | --- |
| Shared directory | Create `app/Services/SupplierDirectoryAccess.php`, `app/Forms/Components/SupplierFields.php`; modify `app/Actions/Purchasing/SaveSupplier.php`, `app/Actions/Purchasing/DeleteSupplier.php`, `app/Livewire/ProductionBench/Purchasing/SupplierCreate.php`; test `tests/Feature/Dossiers/SharedSupplierDirectoryTest.php` |
| Qualification | `app/Enums/SupplierQualificationStatus.php`, migration, `app/Models/SupplierQualification.php`, factory, `app/Actions/Dossiers/SaveSupplierQualification.php`; modify `app/Models/Supplier.php`; test `tests/Feature/Dossiers/SupplierQualificationTest.php` |
| Evidence | `config/dossiers.php`, `app/Enums/EvidenceType.php`, `app/Enums/EvidenceLevel.php`, migration, `app/Models/DossierEvidenceFile.php`, `app/Models/DossierEvidenceItem.php`, factories, `app/Actions/Dossiers/StoreDossierEvidence.php`, `app/Actions/Dossiers/ReplaceDossierEvidence.php`, `app/Services/Dossiers/DossierEvidenceStorage.php`; test `tests/Feature/Dossiers/DossierEvidenceTest.php` |
| Download | `app/Http/Controllers/DossierEvidenceDownloadController.php`, `routes/web.php`; test `tests/Feature/Dossiers/DossierEvidenceDownloadTest.php` |
| Coverage evidence | migration, modify `app/Models/MaterialAssessmentCoverage.php`, `app/Services/Dossiers/MaterialAssessmentCoverageService.php`; test additions in `tests/Feature/Dossiers/MaterialAssessmentCoverageTest.php` |
| Pages | `app/Livewire/Dossiers/RawMaterialsIndex.php`, `app/Livewire/Dossiers/RawMaterialDetail.php`, `app/Livewire/Dossiers/QuickSupplierCreate.php`, views under `resources/views/livewire/dossiers/`, page views `resources/views/dossiers/raw-materials.blade.php`, `resources/views/dossiers/raw-material.blade.php`, `routes/web.php`, `resources/views/layouts/app-shell.blade.php`, `lang/en/dossiers.php`, `database/seeders/data/interface-translations.json`; test `tests/Feature/Dossiers/RawMaterialPagesTest.php` |

---

### Task 1: Shared supplier directory access

**Files:**
- Create: `app/Services/SupplierDirectoryAccess.php`
- Modify: `app/Actions/Purchasing/SaveSupplier.php`
- Test: `tests/Feature/Dossiers/SharedSupplierDirectoryTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Dossiers/SharedSupplierDirectoryTest.php`:

```php
<?php

use App\Actions\Purchasing\SaveSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function sharedSupplierAttributes(string $code = 'OLISUD'): array
{
    return ['code' => $code, 'name' => 'Olisud', 'default_currency' => 'EUR', 'is_active' => true];
}

it('lets a DIP-only workspace create a supplier without Production Bench', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['default_currency' => 'EUR']);
    WorkspaceDossierGrant::factory()->for($workspace)->create();

    $supplier = app(SaveSupplier::class)->handle($owner, $workspace, sharedSupplierAttributes());

    expect($supplier->workspace_id)->toBe($workspace->id)
        ->and(Supplier::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('keeps supplier creation working for Production Bench workspaces without DIP', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['default_currency' => 'EUR']);
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    expect(app(SaveSupplier::class)->handle($owner, $workspace, sharedSupplierAttributes())->exists)->toBeTrue();
});

it('refuses supplier creation when neither module is available', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['default_currency' => 'EUR']);

    app(SaveSupplier::class)->handle($owner, $workspace, sharedSupplierAttributes());
})->throws(ValidationException::class);

it('refuses supplier creation to a non-member even with both modules', function (): void {
    $workspace = Workspace::factory()->create(['default_currency' => 'EUR']);
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    app(SaveSupplier::class)->handle(User::factory()->create(), $workspace, sharedSupplierAttributes());
})->throws(AuthorizationException::class);
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Dossiers/SharedSupplierDirectoryTest.php`
Expected: the first test FAILS with a `ValidationException` (« production_bench » inactive); the others pass.

- [ ] **Step 3: The access service**

`app/Services/SupplierDirectoryAccess.php`:

```php
<?php

namespace App\Services;

use App\Enums\WorkspaceModule;
use App\Models\User;
use App\Models\Workspace;

/**
 * One supplier directory per workspace, shared by Production Bench and the DIP module.
 * Purchasing (listings, prices, orders, stock) stays behind Production Bench.
 */
class SupplierDirectoryAccess
{
    public function __construct(
        private readonly ProductionBenchAccess $productionBench,
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    public function canManage(User $actor, Workspace $workspace): bool
    {
        return $this->authorization->canEditModule($actor, $workspace->id, WorkspaceModule::ProductDossiers)
            || $this->productionBench->canWrite($actor, $workspace);
    }

    /**
     * Passes for DIP editors; otherwise defers to Production Bench so its messages and exceptions are unchanged.
     */
    public function assertCanManage(User $actor, Workspace $workspace): void
    {
        if ($this->authorization->canEditModule($actor, $workspace->id, WorkspaceModule::ProductDossiers)) {
            return;
        }

        $this->productionBench->assertWritable($actor, $workspace);
    }
}
```

- [ ] **Step 4: Use it in `SaveSupplier`**

In `app/Actions/Purchasing/SaveSupplier.php`, replace the `ProductionBenchAccess $access` constructor dependency with `SupplierDirectoryAccess $access` (update the `use` line: `use App\Services\SupplierDirectoryAccess;` instead of `use App\Services\ProductionBenchAccess;`), and replace both calls `$this->access->assertWritable($actor, $workspace)` / `$this->access->assertWritable($actor, $lockedWorkspace)` with `$this->access->assertCanManage(...)` on the same arguments. Nothing else changes.

- [ ] **Step 5: Run the new and existing supplier tests**

Run: `php artisan test --compact tests/Feature/Dossiers/SharedSupplierDirectoryTest.php tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/SupplierCodeTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/SupplierDirectoryAccess.php app/Actions/Purchasing/SaveSupplier.php tests/Feature/Dossiers/SharedSupplierDirectoryTest.php
git commit -m "Share the workspace supplier directory between Production Bench and DIP"
```

---

### Task 2: Shared supplier fields

**Files:**
- Create: `app/Forms/Components/SupplierFields.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierCreate.php`

- [ ] **Step 1: Extract the fields**

Create `app/Forms/Components/SupplierFields.php` containing the four sections currently returned by `SupplierCreate::formComponents()`, unchanged, behind a static method:

```php
<?php

namespace App\Forms\Components;

use App\Services\CurrencyCatalog;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;

/**
 * The supplier fields shared by every place that creates a supplier (Production Bench, DIP raw materials).
 */
class SupplierFields
{
    /** @return array<int, Section> */
    public static function make(CurrencyCatalog $currencyCatalog): array
    {
        $currencyOptions = collect($currencyCatalog->options(app()->getLocale()))
            ->mapWithKeys(fn (string $name, string $code): array => [$code => $code.' · '.$name])
            ->all();

        return [
            // Paste the four Section::make(...) blocks from SupplierCreate::formComponents() here verbatim,
            // replacing ->options($this->currencyOptions()) with ->options($currencyOptions).
        ];
    }
}
```

Then paste the four `Section::make(...)` blocks from `SupplierCreate::formComponents()` into the returned array **verbatim** (the comment above marks the only substitution) and delete the comment.

- [ ] **Step 2: Use it in `SupplierCreate`**

In `SupplierCreate`, make `formComponents()` return `SupplierFields::make($this->currencyCatalog);`, delete the now-unused `currencyOptions()` method and any imports that become unused, and add `use App\Forms\Components\SupplierFields;`.

- [ ] **Step 3: Run the supplier page tests**

Run: `php artisan test --compact tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierPagesTest.php`
Expected: PASS (behaviour unchanged).

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Forms/Components/SupplierFields.php app/Livewire/ProductionBench/Purchasing/SupplierCreate.php
git commit -m "Extract the shared supplier creation fields"
```

---

### Task 3: Supplier qualification

**Files:**
- Create: `app/Enums/SupplierQualificationStatus.php`
- Create: `database/migrations/<timestamp>_create_supplier_qualifications_table.php`
- Create: `app/Models/SupplierQualification.php`, `database/factories/SupplierQualificationFactory.php`
- Create: `app/Actions/Dossiers/SaveSupplierQualification.php`
- Modify: `app/Models/Supplier.php`, `app/Actions/Purchasing/DeleteSupplier.php`
- Test: `tests/Feature/Dossiers/SupplierQualificationTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Dossiers/SupplierQualificationTest.php`:

```php
<?php

use App\Actions\Dossiers\SaveSupplierQualification;
use App\Actions\Purchasing\DeleteSupplier;
use App\Enums\OwnerType;
use App\Enums\SupplierQualificationStatus;
use App\Enums\Visibility;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace, 2: Supplier}
 */
function qualificationWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $supplier = Supplier::factory()->create(['workspace_id' => $workspace->id]);

    return [$owner, $workspace, $supplier];
}

it('qualifies a supplier for a platform ingredient and records who validated it', function (): void {
    [$owner, $workspace, $supplier] = qualificationWorkspace();
    $olive = Ingredient::factory()->create();

    $qualification = app(SaveSupplierQualification::class)->handle($owner, $workspace, [
        'ingredient_id' => $olive->id,
        'supplier_id' => $supplier->id,
        'supplier_reference' => 'HV OLIVE RAFF BIO',
        'status' => 'valide',
    ]);

    expect($qualification->status)->toBe(SupplierQualificationStatus::Valide)
        ->and($qualification->validated_by_user_id)->toBe($owner->id)
        ->and($qualification->validated_at)->not->toBeNull();
});

it('keeps one qualification per ingredient, supplier and reference', function (): void {
    [$owner, $workspace, $supplier] = qualificationWorkspace();
    $olive = Ingredient::factory()->create();
    $attributes = ['ingredient_id' => $olive->id, 'supplier_id' => $supplier->id, 'supplier_reference' => 'REF-1', 'status' => 'en_evaluation'];

    app(SaveSupplierQualification::class)->handle($owner, $workspace, $attributes);
    app(SaveSupplierQualification::class)->handle($owner, $workspace, [...$attributes, 'status' => 'retire']);

    expect(SupplierQualification::query()->sole()->status)->toBe(SupplierQualificationStatus::Retire)
        ->and(SupplierQualification::query()->sole()->validated_at)->toBeNull();
});

it('refuses another workspace supplier or private ingredient', function (string $case): void {
    [$owner, $workspace, $supplier] = qualificationWorkspace();
    $other = Workspace::factory()->create();
    $attributes = match ($case) {
        'supplier' => ['ingredient_id' => Ingredient::factory()->create()->id, 'supplier_id' => Supplier::factory()->create(['workspace_id' => $other->id])->id],
        'ingredient' => ['ingredient_id' => Ingredient::factory()->create([
            'owner_type' => OwnerType::Workspace, 'owner_id' => $other->id, 'workspace_id' => $other->id, 'visibility' => Visibility::Private,
        ])->id, 'supplier_id' => $supplier->id],
    };

    app(SaveSupplierQualification::class)->handle($owner, $workspace, [...$attributes, 'supplier_reference' => null, 'status' => 'valide']);
})->with(['supplier', 'ingredient'])->throws(ValidationException::class);

it('refuses qualification without the DIP module', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    app(SaveSupplierQualification::class)->handle($owner, $workspace, [
        'ingredient_id' => Ingredient::factory()->create()->id,
        'supplier_id' => Supplier::factory()->create(['workspace_id' => $workspace->id])->id,
        'status' => 'valide',
    ]);
})->throws(AuthorizationException::class);

it('deactivates instead of deleting a supplier referenced by a qualification', function (): void {
    [$owner, $workspace, $supplier] = qualificationWorkspace();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'supplier_id' => $supplier->id]);

    expect(app(DeleteSupplier::class)->handle($owner, $workspace, $supplier))->toBeFalse()
        ->and($supplier->refresh()->is_active)->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Dossiers/SupplierQualificationTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Enum**

`app/Enums/SupplierQualificationStatus.php`:

```php
<?php

namespace App\Enums;

enum SupplierQualificationStatus: string
{
    case EnEvaluation = 'en_evaluation';
    case Valide = 'valide';
    case Retire = 'retire';
}
```

- [ ] **Step 4: Migration**

Run `php artisan make:migration create_supplier_qualifications_table --no-interaction`, then:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('ingredient_id');
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->restrictOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
            $table->string('supplier_reference')->nullable();
            $table->string('status', 24);
            $table->foreignId('validated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'ingredient_id', 'supplier_id', 'supplier_reference'], 'supplier_qualifications_identity_unique');
            $table->index(['workspace_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_qualifications');
    }
};
```

Note: a `NULL` `supplier_reference` is not unique in PostgreSQL; the action below normalizes blank references to `''` so the unique index holds.

- [ ] **Step 5: Model and factory**

Run `php artisan make:model SupplierQualification --factory --no-interaction`.

`app/Models/SupplierQualification.php`:

```php
<?php

namespace App\Models;

use App\Enums\SupplierQualificationStatus;
use Database\Factories\SupplierQualificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "This supplier's grade of this ingredient is validated for our dossiers" — a DIP fact, separate from purchasing listings.
 */
#[Fillable([
    'workspace_id',
    'ingredient_id',
    'supplier_id',
    'supplier_reference',
    'status',
    'validated_by_user_id',
    'validated_at',
    'notes',
])]
class SupplierQualification extends Model
{
    /** @use HasFactory<SupplierQualificationFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function evidenceItems(): HasMany
    {
        return $this->hasMany(DossierEvidenceItem::class);
    }

    protected function casts(): array
    {
        return [
            'status' => SupplierQualificationStatus::class,
            'validated_at' => 'datetime',
        ];
    }
}
```

`database/factories/SupplierQualificationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\SupplierQualificationStatus;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierQualification>
 */
class SupplierQualificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'ingredient_id' => Ingredient::factory(),
            'supplier_id' => fn (array $attributes): int => Supplier::factory()->create(['workspace_id' => $attributes['workspace_id']])->id,
            'supplier_reference' => '',
            'status' => SupplierQualificationStatus::Valide,
            'validated_by_user_id' => null,
            'validated_at' => now(),
            'notes' => null,
        ];
    }
}
```

In `app/Models/Supplier.php` add:

```php
    public function qualifications(): HasMany
    {
        return $this->hasMany(SupplierQualification::class);
    }
```

- [ ] **Step 6: Action**

`app/Actions/Dossiers/SaveSupplierQualification.php`:

```php
<?php

namespace App\Actions\Dossiers;

use App\Enums\SupplierQualificationStatus;
use App\Enums\WorkspaceModule;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveSupplierQualification
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    /**
     * Creates or updates the qualification identified by ingredient, supplier and supplier reference.
     *
     * @param  array{ingredient_id: int, supplier_id: int, supplier_reference?: string|null, status: string, notes?: string|null}  $attributes
     */
    public function handle(User $actor, Workspace $workspace, array $attributes): SupplierQualification
    {
        $data = Validator::make($attributes, [
            'ingredient_id' => ['required', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'supplier_reference' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(SupplierQualificationStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $workspace, $data): SupplierQualification {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $freshActor = $actor->fresh();

            if (! $freshActor instanceof User
                || ! $this->authorization->canEditModule($freshActor, $workspace->id, WorkspaceModule::ProductDossiers)) {
                throw new AuthorizationException;
            }

            $supplierBelongs = Supplier::query()->where('workspace_id', $workspace->id)->whereKey($data['supplier_id'])->exists();
            $ingredient = Ingredient::query()->find($data['ingredient_id']);
            $ingredientUsable = $ingredient instanceof Ingredient
                && ($ingredient->workspace_id === null || $ingredient->workspace_id === $workspace->id);

            if (! $supplierBelongs || ! $ingredientUsable) {
                throw ValidationException::withMessages(array_filter([
                    'supplier_id' => $supplierBelongs ? null : __('dossiers.qualification.supplier_not_in_workspace'),
                    'ingredient_id' => $ingredientUsable ? null : __('dossiers.qualification.ingredient_not_usable'),
                ]));
            }

            $status = SupplierQualificationStatus::from($data['status']);
            $identity = [
                'workspace_id' => $workspace->id,
                'ingredient_id' => (int) $data['ingredient_id'],
                'supplier_id' => (int) $data['supplier_id'],
                'supplier_reference' => trim((string) ($data['supplier_reference'] ?? '')),
            ];
            $existing = SupplierQualification::query()->where($identity)->lockForUpdate()->first();
            $becomesValid = $status === SupplierQualificationStatus::Valide && $existing?->status !== SupplierQualificationStatus::Valide;

            return SupplierQualification::query()->updateOrCreate($identity, [
                'status' => $status,
                'notes' => $data['notes'] ?? $existing?->notes,
                'validated_by_user_id' => $status === SupplierQualificationStatus::Valide
                    ? ($becomesValid ? $freshActor->id : $existing?->validated_by_user_id)
                    : null,
                'validated_at' => $status === SupplierQualificationStatus::Valide
                    ? ($becomesValid ? now() : $existing?->validated_at)
                    : null,
            ]);
        }, attempts: 5);
    }
}
```

- [ ] **Step 7: Protect referenced suppliers**

In `app/Actions/Purchasing/DeleteSupplier.php`, extend the condition that deactivates instead of deleting:

```php
            if ($lockedSupplier->purchaseOrders()->exists()
                || $lockedSupplier->listings()->exists()
                || $lockedSupplier->qualifications()->exists()) {
```

- [ ] **Step 8: English strings used by the action**

Append to the `return [...]` of `lang/en/dossiers.php` (new top-level key):

```php
    'qualification' => [
        'supplier_not_in_workspace' => 'This supplier does not belong to this workspace.',
        'ingredient_not_usable' => 'This ingredient is not available in this workspace.',
    ],
```

(Catalogue rows for all new `dossiers.*` keys are added in Task 8, Step 9.)

- [ ] **Step 9: Run and commit**

Run: `php artisan test --compact tests/Feature/Dossiers/SupplierQualificationTest.php tests/Feature/ProductionBenchSupplierPagesTest.php`
Expected: PASS (6 + existing). `InterfaceTranslationCatalogueTest` will fail until Task 8 adds the catalogue rows — do not run it yet.

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/SupplierQualificationStatus.php app/Models/SupplierQualification.php app/Models/Supplier.php app/Actions/Dossiers/SaveSupplierQualification.php app/Actions/Purchasing/DeleteSupplier.php database/migrations/*_create_supplier_qualifications_table.php database/factories/SupplierQualificationFactory.php lang/en/dossiers.php tests/Feature/Dossiers/SupplierQualificationTest.php
git commit -m "Add DIP supplier qualification per ingredient, supplier and supplied grade"
```

---

### Task 4: Retained evidence files and items

**Files:**
- Create: `config/dossiers.php`, `app/Enums/EvidenceLevel.php`, `app/Enums/EvidenceType.php`
- Create: `database/migrations/<timestamp>_create_dossier_evidence_tables.php`
- Create: `app/Models/DossierEvidenceFile.php`, `app/Models/DossierEvidenceItem.php`, factories
- Create: `app/Services/Dossiers/DossierEvidenceStorage.php`, `app/Actions/Dossiers/StoreDossierEvidence.php`, `app/Actions/Dossiers/ReplaceDossierEvidence.php`
- Test: `tests/Feature/Dossiers/DossierEvidenceTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Dossiers/DossierEvidenceTest.php`:

```php
<?php

use App\Actions\Dossiers\ReplaceDossierEvidence;
use App\Actions\Dossiers\StoreDossierEvidence;
use App\Enums\EvidenceType;
use App\Models\DossierEvidenceFile;
use App\Models\DossierEvidenceItem;
use App\Models\Recipe;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(config('dossiers.evidence_disk'));
});

/**
 * @return array{0: User, 1: Workspace, 2: SupplierQualification}
 */
function evidenceWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();

    return [$owner, $workspace, SupplierQualification::factory()->create(['workspace_id' => $workspace->id])];
}

it('stores a supplier document with its fingerprint on the private evidence disk', function (): void {
    [$owner, $workspace, $qualification] = evidenceWorkspace();
    $pdf = UploadedFile::fake()->createWithContent('FDS HE Cannelle.pdf', '%PDF-1.4 safety data sheet');

    $item = app(StoreDossierEvidence::class)->handle($owner, $workspace, $pdf, [
        'type' => 'safety_data_sheet',
        'issued_on' => '2021-03-01',
        'revision_due_on' => '2026-03-01',
        'supplier_qualification_id' => $qualification->id,
    ]);

    expect($item->type)->toBe(EvidenceType::SafetyDataSheet)
        ->and($item->type->annexLetter())->toBe('a')
        ->and($item->file->sha256)->toBe(hash('sha256', '%PDF-1.4 safety data sheet'))
        ->and($item->file->original_filename)->toBe('FDS HE Cannelle.pdf');

    Storage::disk(config('dossiers.evidence_disk'))->assertExists($item->file->path);
});

it('rejects files that are too large or of the wrong kind', function (UploadedFile $file): void {
    [$owner, $workspace, $qualification] = evidenceWorkspace();

    app(StoreDossierEvidence::class)->handle($owner, $workspace, $file, [
        'type' => 'technical_sheet',
        'supplier_qualification_id' => $qualification->id,
    ]);
})->with([
    'too large' => [fn () => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')],
    'wrong kind' => [fn () => UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
])->throws(ValidationException::class);

it('requires the attachment that matches the evidence level', function (): void {
    [$owner, $workspace] = evidenceWorkspace();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);

    expect(fn () => app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->create('ft.pdf', 10, 'application/pdf'), [
        'type' => 'technical_sheet',
        'recipe_ids' => [$recipe->id],
    ]))->toThrow(ValidationException::class);

    $stability = app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->create('stab.pdf', 10, 'application/pdf'), [
        'type' => 'stability_test',
        'recipe_ids' => [$recipe->id],
    ]);

    expect($stability->recipes->pluck('id')->all())->toBe([$recipe->id])
        ->and($stability->type->annexLetter())->toBe('c');
});

it('replaces evidence instead of deleting it', function (): void {
    [$owner, $workspace, $qualification] = evidenceWorkspace();
    $original = app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->createWithContent('ft-v1.pdf', '%PDF-1.4 version 1'), [
        'type' => 'technical_sheet',
        'supplier_qualification_id' => $qualification->id,
    ]);

    $replacement = app(ReplaceDossierEvidence::class)->handle($owner, $workspace, $original, UploadedFile::fake()->createWithContent('ft-v2.pdf', '%PDF-1.4 version 2'), [
        'issued_on' => '2024-01-10',
    ]);

    expect($original->refresh()->retired_at)->not->toBeNull()
        ->and($original->replaced_by_id)->toBe($replacement->id)
        ->and($replacement->type)->toBe(EvidenceType::TechnicalSheet)
        ->and($replacement->supplier_qualification_id)->toBe($qualification->id)
        ->and(DossierEvidenceFile::query()->count())->toBe(2)
        ->and(fn () => $original->delete())->toThrow(LogicException::class)
        ->and(fn () => $original->file->delete())->toThrow(LogicException::class);
});

it('refuses evidence for another workspace qualification or without the module', function (): void {
    [$owner, $workspace] = evidenceWorkspace();
    $foreign = SupplierQualification::factory()->create();

    expect(fn () => app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), [
        'type' => 'technical_sheet',
        'supplier_qualification_id' => $foreign->id,
    ]))->toThrow(ValidationException::class);

    $outsider = User::factory()->create();
    $plain = Workspace::factory()->for($outsider, 'owner')->create();

    expect(fn () => app(StoreDossierEvidence::class)->handle($outsider, $plain, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), [
        'type' => 'gmp_declaration',
    ]))->toThrow(AuthorizationException::class)
        ->and(DossierEvidenceItem::query()->count())->toBe(0);
});
```

Check `Recipe::factory()` accepts `workspace_id` directly (open `database/factories/RecipeFactory.php`); if it sets workspace through an owner relation instead, create the recipe the way `tests/Feature/RecipeWorkbench*` tests do.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Dossiers/DossierEvidenceTest.php`
Expected: FAIL — classes and config not found.

- [ ] **Step 3: Config**

`config/dossiers.php`:

```php
<?php

return [
    'evidence_disk' => env('DOSSIER_EVIDENCE_DISK', 'local'),
    'evidence_directory' => 'dossier-evidence',
    'evidence_max_kb' => 10240,
    'evidence_mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
];
```

- [ ] **Step 4: Enums**

`app/Enums/EvidenceLevel.php`:

```php
<?php

namespace App\Enums;

enum EvidenceLevel: string
{
    case SupplierQualification = 'supplier_qualification';
    case Product = 'product';
    case Workspace = 'workspace';
}
```

`app/Enums/EvidenceType.php`:

```php
<?php

namespace App\Enums;

/**
 * Kinds of DIP evidence, with the annex each belongs to (Regulation 1223/2009 Annex I layout used by the DIP)
 * and what it attaches to.
 */
enum EvidenceType: string
{
    case TechnicalSheet = 'technical_sheet';
    case SafetyDataSheet = 'safety_data_sheet';
    case IfraCertificate = 'ifra_certificate';
    case AllergenDeclaration = 'allergen_declaration';
    case RegulatoryConformityCertificate = 'regulatory_conformity_certificate';
    case CertificateOfAnalysis = 'certificate_of_analysis';
    case OrganicCertificate = 'organic_certificate';
    case RawMaterialAnimalTestingStatement = 'raw_material_animal_testing_statement';
    case SupplierSafetyAssessment = 'supplier_safety_assessment';
    case OtherSupplierDocument = 'other_supplier_document';
    case FinishedProductSpecification = 'finished_product_specification';
    case StabilityTest = 'stability_test';
    case PaoAssessment = 'pao_assessment';
    case ChallengeTest = 'challenge_test';
    case SkinIrritationTest = 'skin_irritation_test';
    case MucosaIrritationTest = 'mucosa_irritation_test';
    case OtherSafetyTest = 'other_safety_test';
    case PrimaryPackaging = 'primary_packaging';
    case SecondaryPackaging = 'secondary_packaging';
    case Labelling = 'labelling';
    case ManufacturingMethod = 'manufacturing_method';
    case ClaimEvidence = 'claim_evidence';
    case ProductAnimalTestingStatement = 'product_animal_testing_statement';
    case CpnpNotification = 'cpnp_notification';
    case GmpDeclaration = 'gmp_declaration';
    case AssessorQualification = 'assessor_qualification';

    public function annexLetter(): string
    {
        return match ($this) {
            self::FinishedProductSpecification => 'b',
            self::StabilityTest => 'c',
            self::PaoAssessment => 'd',
            self::ChallengeTest => 'e',
            self::SkinIrritationTest => 'f',
            self::MucosaIrritationTest => 'g',
            self::OtherSafetyTest => 'h',
            self::PrimaryPackaging => 'j',
            self::SecondaryPackaging => 'k',
            self::Labelling => 'l',
            self::AssessorQualification => 'n',
            self::ManufacturingMethod => 'o',
            self::GmpDeclaration => 'p',
            self::ClaimEvidence => 'q',
            self::ProductAnimalTestingStatement => 'r',
            self::CpnpNotification => 's',
            default => 'a',
        };
    }

    public function level(): EvidenceLevel
    {
        return match ($this) {
            self::GmpDeclaration, self::AssessorQualification => EvidenceLevel::Workspace,
            self::FinishedProductSpecification, self::StabilityTest, self::PaoAssessment, self::ChallengeTest,
            self::SkinIrritationTest, self::MucosaIrritationTest, self::OtherSafetyTest, self::PrimaryPackaging,
            self::SecondaryPackaging, self::Labelling, self::ManufacturingMethod, self::ClaimEvidence,
            self::ProductAnimalTestingStatement, self::CpnpNotification => EvidenceLevel::Product,
            default => EvidenceLevel::SupplierQualification,
        };
    }

    /**
     * @return list<self>
     */
    public static function forLevel(EvidenceLevel $level): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->level() === $level));
    }
}
```

- [ ] **Step 5: Migration**

Run `php artisan make:migration create_dossier_evidence_tables --no-interaction`, then:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_evidence_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('dossier_evidence_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('dossier_evidence_file_id');
            $table->foreign('dossier_evidence_file_id')->references('id')->on('dossier_evidence_files')->restrictOnDelete();
            $table->string('type', 64);
            $table->unsignedBigInteger('supplier_qualification_id')->nullable();
            $table->foreign('supplier_qualification_id')->references('id')->on('supplier_qualifications')->restrictOnDelete();
            $table->date('issued_on')->nullable();
            $table->date('revision_due_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedBigInteger('replaced_by_id')->nullable();
            $table->foreign('replaced_by_id')->references('id')->on('dossier_evidence_items')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'type']);
        });

        Schema::create('dossier_evidence_item_recipe', function (Blueprint $table) {
            $table->foreignId('dossier_evidence_item_id')->constrained(indexName: 'deir_item_id_foreign')->cascadeOnDelete();
            $table->unsignedBigInteger('recipe_id');
            $table->foreign('recipe_id', 'deir_recipe_id_foreign')->references('id')->on('recipes')->restrictOnDelete();
            $table->primary(['dossier_evidence_item_id', 'recipe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_evidence_item_recipe');
        Schema::dropIfExists('dossier_evidence_items');
        Schema::dropIfExists('dossier_evidence_files');
    }
};
```

- [ ] **Step 6: Models and factories**

Run `php artisan make:model DossierEvidenceFile --factory --no-interaction` and `php artisan make:model DossierEvidenceItem --factory --no-interaction`.

`app/Models/DossierEvidenceFile.php`:

```php
<?php

namespace App\Models;

use Database\Factories\DossierEvidenceFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A stored evidence file. Immutable and never deleted by the application (10-year retention).
 */
#[Fillable(['workspace_id', 'disk', 'path', 'original_filename', 'mime_type', 'size_bytes', 'sha256', 'uploaded_by_user_id'])]
class DossierEvidenceFile extends Model
{
    /** @use HasFactory<DossierEvidenceFileFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Evidence files are immutable.'));
        static::deleting(fn () => throw new LogicException('Evidence files are retained and cannot be deleted.'));
    }
}
```

`app/Models/DossierEvidenceItem.php`:

```php
<?php

namespace App\Models;

use App\Enums\EvidenceType;
use Database\Factories\DossierEvidenceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

/**
 * What a stored file proves, which annex it belongs to and what it is attached to. Replaced, never deleted.
 */
#[Fillable([
    'workspace_id', 'dossier_evidence_file_id', 'type', 'supplier_qualification_id',
    'issued_on', 'revision_due_on', 'notes', 'retired_at', 'replaced_by_id', 'created_by_user_id',
])]
class DossierEvidenceItem extends Model
{
    /** @use HasFactory<DossierEvidenceItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Evidence items are retained; replace them instead.'));
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(DossierEvidenceFile::class, 'dossier_evidence_file_id');
    }

    public function supplierQualification(): BelongsTo
    {
        return $this->belongsTo(SupplierQualification::class);
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'dossier_evidence_item_recipe');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function isActive(): bool
    {
        return $this->retired_at === null;
    }

    /**
     * @param  Builder<DossierEvidenceItem>  $query
     * @return Builder<DossierEvidenceItem>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    protected function casts(): array
    {
        return [
            'type' => EvidenceType::class,
            'issued_on' => 'date',
            'revision_due_on' => 'date',
            'retired_at' => 'datetime',
        ];
    }
}
```

`database/factories/DossierEvidenceFileFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\DossierEvidenceFile;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierEvidenceFile>
 */
class DossierEvidenceFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'disk' => config('dossiers.evidence_disk'),
            'path' => 'dossier-evidence/test/'.fake()->uuid().'.pdf',
            'original_filename' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', fake()->uuid()),
            'uploaded_by_user_id' => null,
        ];
    }
}
```

`database/factories/DossierEvidenceItemFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\EvidenceType;
use App\Models\DossierEvidenceFile;
use App\Models\DossierEvidenceItem;
use App\Models\SupplierQualification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierEvidenceItem>
 */
class DossierEvidenceItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_qualification_id' => SupplierQualification::factory(),
            'workspace_id' => fn (array $attributes): int => SupplierQualification::query()->findOrFail($attributes['supplier_qualification_id'])->workspace_id,
            'dossier_evidence_file_id' => fn (array $attributes): int => DossierEvidenceFile::factory()->create(['workspace_id' => $attributes['workspace_id']])->id,
            'type' => EvidenceType::TechnicalSheet,
            'issued_on' => null,
            'revision_due_on' => null,
            'notes' => null,
            'retired_at' => null,
            'replaced_by_id' => null,
            'created_by_user_id' => null,
        ];
    }
}
```

- [ ] **Step 7: Storage service**

`app/Services/Dossiers/DossierEvidenceStorage.php`:

```php
<?php

namespace App\Services\Dossiers;

use App\Models\DossierEvidenceFile;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class DossierEvidenceStorage
{
    public function assertAcceptable(UploadedFile $file): void
    {
        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'max:'.config('dossiers.evidence_max_kb'), 'mimes:'.implode(',', config('dossiers.evidence_mimes'))],
        ])->validate();
    }

    /**
     * Writes the file to the private evidence disk under a random name and records its SHA-256.
     */
    public function store(Workspace $workspace, UploadedFile $file, ?User $uploader): DossierEvidenceFile
    {
        $disk = (string) config('dossiers.evidence_disk');
        $contents = (string) file_get_contents($file->getRealPath());
        $extension = Str::lower($file->getClientOriginalExtension() ?: (string) $file->extension());
        $path = config('dossiers.evidence_directory').'/'.$workspace->id.'/'.Str::uuid().'.'.$extension;

        if (! Storage::disk($disk)->put($path, $contents)) {
            throw new RuntimeException('The evidence file could not be stored.');
        }

        return DossierEvidenceFile::query()->create([
            'workspace_id' => $workspace->id,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'uploaded_by_user_id' => $uploader?->id,
        ]);
    }
}
```

- [ ] **Step 8: Store action**

`app/Actions/Dossiers/StoreDossierEvidence.php`:

```php
<?php

namespace App\Actions\Dossiers;

use App\Enums\EvidenceLevel;
use App\Enums\EvidenceType;
use App\Enums\WorkspaceModule;
use App\Models\DossierEvidenceItem;
use App\Models\Recipe;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Dossiers\DossierEvidenceStorage;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreDossierEvidence
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly DossierEvidenceStorage $storage,
    ) {}

    /**
     * @param  array{type: string, issued_on?: string|null, revision_due_on?: string|null, notes?: string|null,
     *               supplier_qualification_id?: int|null, recipe_ids?: list<int>}  $attributes
     */
    public function handle(User $actor, Workspace $workspace, UploadedFile $file, array $attributes): DossierEvidenceItem
    {
        $data = Validator::make($attributes, [
            'type' => ['required', Rule::enum(EvidenceType::class)],
            'issued_on' => ['nullable', 'date'],
            'revision_due_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'supplier_qualification_id' => ['nullable', 'integer'],
            'recipe_ids' => ['nullable', 'array'],
            'recipe_ids.*' => ['integer'],
        ])->validate();
        $this->storage->assertAcceptable($file);

        return DB::transaction(function () use ($actor, $workspace, $file, $data): DossierEvidenceItem {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $freshActor = $actor->fresh();

            if (! $freshActor instanceof User
                || ! $this->authorization->canEditModule($freshActor, $workspace->id, WorkspaceModule::ProductDossiers)) {
                throw new AuthorizationException;
            }

            $type = EvidenceType::from($data['type']);
            $recipeIds = collect($data['recipe_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->unique()->values();
            $this->assertAttachment($workspace, $type, $data['supplier_qualification_id'] ?? null, $recipeIds->all());

            $item = DossierEvidenceItem::query()->create([
                'workspace_id' => $workspace->id,
                'dossier_evidence_file_id' => $this->storage->store($workspace, $file, $freshActor)->id,
                'type' => $type,
                'supplier_qualification_id' => $type->level() === EvidenceLevel::SupplierQualification ? $data['supplier_qualification_id'] : null,
                'issued_on' => $data['issued_on'] ?? null,
                'revision_due_on' => $data['revision_due_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $freshActor->id,
            ]);

            if ($type->level() === EvidenceLevel::Product) {
                $item->recipes()->sync($recipeIds->all());
            }

            return $item->load(['file', 'recipes']);
        }, attempts: 5);
    }

    /**
     * @param  list<int>  $recipeIds
     */
    private function assertAttachment(Workspace $workspace, EvidenceType $type, mixed $qualificationId, array $recipeIds): void
    {
        $error = match ($type->level()) {
            EvidenceLevel::SupplierQualification => $qualificationId !== null
                && SupplierQualification::query()->where('workspace_id', $workspace->id)->whereKey($qualificationId)->exists()
                    ? null : ['supplier_qualification_id', __('dossiers.evidence.needs_qualification')],
            EvidenceLevel::Product => $recipeIds !== []
                && Recipe::withoutGlobalScopes()->where('workspace_id', $workspace->id)->whereIn('id', $recipeIds)->count() === count($recipeIds)
                    ? null : ['recipe_ids', __('dossiers.evidence.needs_products')],
            EvidenceLevel::Workspace => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages([$error[0] => $error[1]]);
        }
    }
}
```

- [ ] **Step 9: Replace action**

`app/Actions/Dossiers/ReplaceDossierEvidence.php`:

```php
<?php

namespace App\Actions\Dossiers;

use App\Models\DossierEvidenceItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReplaceDossierEvidence
{
    public function __construct(private readonly StoreDossierEvidence $store) {}

    /**
     * Stores a newer version with the same type and attachments, then retires the old item and links it forward.
     *
     * @param  array{issued_on?: string|null, revision_due_on?: string|null, notes?: string|null}  $attributes
     */
    public function handle(User $actor, Workspace $workspace, DossierEvidenceItem $item, UploadedFile $file, array $attributes): DossierEvidenceItem
    {
        return DB::transaction(function () use ($actor, $workspace, $item, $file, $attributes): DossierEvidenceItem {
            $current = DossierEvidenceItem::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->first();

            if (! $current instanceof DossierEvidenceItem || ! $current->isActive()) {
                throw ValidationException::withMessages(['evidence' => __('dossiers.evidence.not_replaceable')]);
            }

            $replacement = $this->store->handle($actor, $workspace, $file, [
                ...$attributes,
                'type' => $current->type->value,
                'supplier_qualification_id' => $current->supplier_qualification_id,
                'recipe_ids' => $current->recipes()->pluck('recipes.id')->all(),
            ]);

            $current->update(['retired_at' => now(), 'replaced_by_id' => $replacement->id]);

            return $replacement;
        }, attempts: 5);
    }
}
```

- [ ] **Step 10: Strings**

Add to `lang/en/dossiers.php`:

```php
    'evidence' => [
        'needs_qualification' => 'Attach this supplier document to a qualified supplier of this workspace.',
        'needs_products' => 'Attach this document to at least one product of this workspace.',
        'not_replaceable' => 'Only a current document of this workspace can be replaced.',
    ],
```

- [ ] **Step 11: Run and commit**

Run: `php artisan test --compact tests/Feature/Dossiers/DossierEvidenceTest.php`
Expected: PASS (6 tests). Uploaded fakes must carry real PDF magic bytes (`%PDF-`) because the `mimes` rule inspects content.

```bash
vendor/bin/pint --dirty --format agent
git add config/dossiers.php app/Enums/EvidenceLevel.php app/Enums/EvidenceType.php app/Models/DossierEvidenceFile.php app/Models/DossierEvidenceItem.php app/Services/Dossiers/DossierEvidenceStorage.php app/Actions/Dossiers/StoreDossierEvidence.php app/Actions/Dossiers/ReplaceDossierEvidence.php database/migrations/*_create_dossier_evidence_tables.php database/factories/DossierEvidenceFileFactory.php database/factories/DossierEvidenceItemFactory.php lang/en/dossiers.php tests/Feature/Dossiers/DossierEvidenceTest.php
git commit -m "Store DIP evidence as retained, fingerprinted files with annex types"
```

---

### Task 5: Authorized evidence download

**Files:**
- Create: `app/Http/Controllers/DossierEvidenceDownloadController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Dossiers/DossierEvidenceDownloadTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Dossiers\StoreDossierEvidence;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('downloads evidence for the workspace owner and hides it from everyone else', function (): void {
    Storage::fake(config('dossiers.evidence_disk'));
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $item = app(StoreDossierEvidence::class)->handle($owner, $workspace, UploadedFile::fake()->createWithContent('FT olive.pdf', '%PDF olive'), [
        'type' => 'technical_sheet',
        'supplier_qualification_id' => SupplierQualification::factory()->create(['workspace_id' => $workspace->id])->id,
    ]);

    $this->actingAs($owner)->get(route('dossiers.evidence.download', $item))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('FT olive.pdf');

    $this->actingAs(User::factory()->create())->get(route('dossiers.evidence.download', $item))->assertNotFound();
});
```

Save as `tests/Feature/Dossiers/DossierEvidenceDownloadTest.php`.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Dossiers/DossierEvidenceDownloadTest.php`
Expected: FAIL — route not defined.

- [ ] **Step 3: Controller**

`app/Http/Controllers/DossierEvidenceDownloadController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceModule;
use App\Models\DossierEvidenceItem;
use App\Models\User;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DossierEvidenceDownloadController extends Controller
{
    public function __invoke(Request $request, DossierEvidenceItem $item, WorkspaceAuthorization $authorization): StreamedResponse
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $authorization->canViewModule($user, $item->workspace_id, WorkspaceModule::ProductDossiers),
            404,
        );

        $file = $item->file;
        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);

        return $disk->response($file->path, $file->original_filename, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $request->boolean('inline') ? 'inline' : 'attachment');
    }
}
```

- [ ] **Step 4: Route**

In `routes/web.php`, inside the `Route::middleware(['auth', 'verified'])->group(...)` block, next to the media download route, add:

```php
    Route::get('/dashboard/dossiers/evidence/{item}/download', DossierEvidenceDownloadController::class)
        ->middleware('throttle:60,1')
        ->name('dossiers.evidence.download');
```

and the import `use App\Http\Controllers\DossierEvidenceDownloadController;`.

- [ ] **Step 5: Run and commit**

Run: `php artisan test --compact tests/Feature/Dossiers/DossierEvidenceDownloadTest.php`
Expected: PASS.

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/DossierEvidenceDownloadController.php routes/web.php tests/Feature/Dossiers/DossierEvidenceDownloadTest.php
git commit -m "Serve DIP evidence through an authorized private download"
```

---

### Task 6: Supplier assessments as coverage evidence

**Files:**
- Create: `database/migrations/<timestamp>_create_material_assessment_coverage_evidence_table.php`
- Modify: `app/Models/MaterialAssessmentCoverage.php`, `app/Services/Dossiers/MaterialAssessmentCoverageService.php`
- Test: append to `tests/Feature/Dossiers/MaterialAssessmentCoverageTest.php`

- [ ] **Step 1: Append the failing tests**

Add `use App\Enums\EvidenceType;`, `use App\Models\DossierEvidenceItem;` and `use App\Models\SupplierQualification;` to the imports, then append:

```php
it('accepts a validated supplier safety assessment as whole-material evidence for a private ingredient', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $perfume = privateIngredientFor($workspace);
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $perfume->id]);
    $assessment = DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => $qualification->id,
        'type' => EvidenceType::SupplierSafetyAssessment,
    ]);

    $coverage = app(MaterialAssessmentCoverageService::class)->review($owner, $perfume, [
        'review_state' => 'sufficient',
        'covers_whole_material' => true,
        'supporting_evidence_item_ids' => [$assessment->id],
    ]);

    expect($coverage->supportingEvidenceItems->pluck('id')->all())->toBe([$assessment->id]);
});

it('refuses supplier evidence that is not a valid assessment for this ingredient', function (string $case): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $perfume = privateIngredientFor($workspace);
    $qualification = SupplierQualification::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $case === 'other ingredient' ? privateIngredientFor($workspace)->id : $perfume->id,
        'status' => $case === 'not validated' ? 'en_evaluation' : 'valide',
    ]);
    $item = DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => $qualification->id,
        'type' => $case === 'wrong type' ? EvidenceType::TechnicalSheet : EvidenceType::SupplierSafetyAssessment,
        'retired_at' => $case === 'retired' ? now() : null,
    ]);

    try {
        app(MaterialAssessmentCoverageService::class)->review($owner, $perfume, [
            'review_state' => 'sufficient',
            'covers_whole_material' => true,
            'supporting_evidence_item_ids' => [$item->id],
        ]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('supporting_evidence_item_ids');
    }
})->with(['wrong type', 'not validated', 'retired', 'other ingredient']);

it('does not accept supplier evidence for platform coverage', function (): void {
    $item = DossierEvidenceItem::factory()->create(['type' => EvidenceType::SupplierSafetyAssessment]);

    try {
        app(MaterialAssessmentCoverageService::class)->review(User::factory()->admin()->create(), Ingredient::factory()->create(), [
            'review_state' => 'sufficient',
            'covers_whole_material' => true,
            'supporting_evidence_item_ids' => [$item->id],
        ]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('supporting_evidence_item_ids');
    }
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Dossiers/MaterialAssessmentCoverageTest.php`
Expected: the new tests FAIL.

- [ ] **Step 3: Migration**

Run `php artisan make:migration create_material_assessment_coverage_evidence_table --no-interaction`, then:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_assessment_coverage_evidence', function (Blueprint $table) {
            $table->foreignId('material_assessment_coverage_id')->constrained(indexName: 'mace_coverage_id_foreign')->cascadeOnDelete();
            $table->unsignedBigInteger('dossier_evidence_item_id');
            $table->foreign('dossier_evidence_item_id', 'mace_item_id_foreign')->references('id')->on('dossier_evidence_items')->restrictOnDelete();
            $table->primary(['material_assessment_coverage_id', 'dossier_evidence_item_id'], 'mace_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_assessment_coverage_evidence');
    }
};
```

- [ ] **Step 4: Model relation**

In `app/Models/MaterialAssessmentCoverage.php` add:

```php
    public function supportingEvidenceItems(): BelongsToMany
    {
        return $this->belongsToMany(DossierEvidenceItem::class, 'material_assessment_coverage_evidence');
    }
```

- [ ] **Step 5: Service changes**

In `MaterialAssessmentCoverageService::review()`:

1. Read the new input: `$evidenceIds = collect($review['supporting_evidence_item_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->unique()->values();` and add `supporting_evidence_item_ids?: list<int>` to the method's array-shape docblock.
2. Inside the transaction, after loading `$currentProfiles`, validate the evidence with a new private method and pass whether any valid supplier assessment exists into `assertConsistent()`:

```php
            $evidenceError = $this->supplierEvidenceError($material, $evidenceIds);

            if ($evidenceError !== null) {
                throw ValidationException::withMessages(['supporting_evidence_item_ids' => $evidenceError]);
            }
```

```php
    /**
     * Supplier safety assessments may support a private ingredient's coverage when each is current and attached to a
     * validated qualification of that same ingredient.
     *
     * @param  Collection<int, int>  $evidenceIds
     */
    private function supplierEvidenceError(Ingredient|Substance $material, Collection $evidenceIds): ?string
    {
        if ($evidenceIds->isEmpty()) {
            return null;
        }

        if (! $material instanceof Ingredient || $material->workspace_id === null) {
            return 'Supplier assessments can only support the coverage of a private ingredient.';
        }

        $valid = DossierEvidenceItem::query()
            ->whereIn('id', $evidenceIds->all())
            ->where('workspace_id', $material->workspace_id)
            ->where('type', EvidenceType::SupplierSafetyAssessment->value)
            ->whereNull('retired_at')
            ->whereHas('supplierQualification', fn ($query) => $query
                ->where('ingredient_id', $material->id)
                ->where('status', SupplierQualificationStatus::Valide->value))
            ->count();

        return $valid === $evidenceIds->count()
            ? null
            : 'Each supplier assessment must be current and belong to a validated supplier of this ingredient.';
    }
```

3. Change the evidence rules in `assertConsistent()` so a whole-material or sufficient review accepts **either** profile references **or** supplier assessments: add a `bool $hasSupplierEvidence` parameter (pass `$evidenceIds->isNotEmpty()`), and replace the two `$substanceIds->isEmpty()` conditions with `$substanceIds->isEmpty() && ! $hasSupplierEvidence`.
4. After syncing `supportingSubstances`, add `$coverage->supportingEvidenceItems()->sync($evidenceIds->all());` and load it: `return $coverage->load(['supportingSubstances', 'supportingEvidenceItems']);`.
5. Add the imports `App\Enums\EvidenceType`, `App\Enums\SupplierQualificationStatus`, `App\Models\DossierEvidenceItem`.

- [ ] **Step 6: Run and commit**

Run: `php artisan test --compact tests/Feature/Dossiers/MaterialAssessmentCoverageTest.php tests/Feature/Filament/MaterialAssessmentCoverageResourceTest.php`
Expected: PASS.

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/*_create_material_assessment_coverage_evidence_table.php app/Models/MaterialAssessmentCoverage.php app/Services/Dossiers/MaterialAssessmentCoverageService.php tests/Feature/Dossiers/MaterialAssessmentCoverageTest.php
git commit -m "Accept validated supplier safety assessments as private-ingredient coverage evidence"
```

---

### Task 7: Quick supplier creation component

**Files:**
- Create: `app/Livewire/Dossiers/QuickSupplierCreate.php`, `resources/views/livewire/dossiers/quick-supplier-create.blade.php`
- Test: first part of `tests/Feature/Dossiers/RawMaterialPagesTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Dossiers/RawMaterialPagesTest.php`:

```php
<?php

use App\Livewire\Dossiers\QuickSupplierCreate;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace}
 */
function rawMaterialWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['default_currency' => 'EUR']);
    WorkspaceDossierGrant::factory()->for($workspace)->create();

    return [$owner, $workspace];
}

it('creates a supplier from the DIP pages and announces it', function (): void {
    [$owner, $workspace] = rawMaterialWorkspace();
    $this->actingAs($owner);

    Livewire::test(QuickSupplierCreate::class)
        ->fillForm(['code' => 'akemia', 'name' => 'Akemia', 'default_currency' => 'EUR', 'is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertDispatched('dossier-supplier-created');

    expect(Supplier::query()->where('workspace_id', $workspace->id)->sole()->code)->toBe('AKEMIA');
});

it('refuses the quick supplier form without the DIP module', function (): void {
    $owner = User::factory()->create();
    Workspace::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test(QuickSupplierCreate::class)->assertForbidden();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Dossiers/RawMaterialPagesTest.php`
Expected: FAIL — component not found.

- [ ] **Step 3: Component**

`app/Livewire/Dossiers/QuickSupplierCreate.php`:

```php
<?php

namespace App\Livewire\Dossiers;

use App\Actions\Purchasing\SaveSupplier;
use App\Enums\WorkspaceModule;
use App\Forms\Components\SupplierFields;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrencyCatalog;
use App\Services\WorkspaceAuthorization;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Creates a supplier in the shared workspace directory from the DIP pages.
 */
class QuickSupplierCreate extends Component implements HasForms
{
    use InteractsWithForms;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public int $workspaceId;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $workspace = $this->workspace();
        $this->workspaceId = $workspace->id;
        $this->form->fill(['default_currency' => $workspace->default_currency, 'is_active' => true]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(SupplierFields::make(app(CurrencyCatalog::class)))->statePath('data');
    }

    public function save(SaveSupplier $saveSupplier): void
    {
        /** @var array<string, mixed> $state */
        $state = $this->form->getState();

        try {
            $supplier = $saveSupplier->handle($this->user(), Workspace::withoutGlobalScopes()->findOrFail($this->workspaceId), $state);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('data.'.$field, $message);
                }
            }

            return;
        }

        $this->dispatch('dossier-supplier-created', supplierId: $supplier->id);
        $this->form->fill(['default_currency' => $supplier->default_currency, 'is_active' => true]);
    }

    public function render(): View
    {
        return view('livewire.dossiers.quick-supplier-create');
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->user()->company(fresh: true);

        abort_unless(
            $workspace instanceof Workspace
                && app(WorkspaceAuthorization::class)->canEditModule($this->user(), $workspace->id, WorkspaceModule::ProductDossiers),
            403,
        );

        return $workspace;
    }
}
```

`resources/views/livewire/dossiers/quick-supplier-create.blade.php`:

```blade
<section class="sk-card space-y-4 p-5" aria-labelledby="quick-supplier-heading">
    <h3 id="quick-supplier-heading" class="text-base font-semibold text-[var(--color-ink-strong)]">{{ __('dossiers.raw_materials.new_supplier') }}</h3>
    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}
        <div class="flex justify-end">
            <button type="submit" class="sk-btn sk-btn-primary min-h-11 py-2.5">{{ __('dossiers.raw_materials.create_supplier') }}</button>
        </div>
    </form>
</section>
```

- [ ] **Step 4: Strings**

Add to `lang/en/dossiers.php`:

```php
    'raw_materials' => [
        'title' => 'Raw materials',
        'intro' => 'Ingredients used in your formulas, with the suppliers validated for your dossiers and their documents.',
        'empty' => 'No ingredient is used in a formula of this workspace yet.',
        'ingredient' => 'Ingredient',
        'inci' => 'INCI',
        'validated_suppliers' => 'Validated suppliers',
        'documents' => 'Documents',
        'coverage' => 'Safety review',
        'open' => 'Open',
        'back' => 'All raw materials',
        'suppliers_heading' => 'Qualified suppliers',
        'supplier' => 'Supplier',
        'supplier_reference' => 'Supplier reference or grade',
        'status' => 'Status',
        'add_qualification' => 'Add supplier for this ingredient',
        'save_qualification' => 'Save',
        'new_supplier' => 'New supplier',
        'create_supplier' => 'Create supplier',
        'documents_heading' => 'Documents',
        'document_type' => 'Document type',
        'issued_on' => 'Issued on',
        'revision_due_on' => 'Revision or expiry date',
        'file' => 'File (PDF, JPEG or PNG, up to 10 MB)',
        'upload' => 'Upload document',
        'replace' => 'Replace',
        'download' => 'Download',
        'fingerprint' => 'Fingerprint',
        'no_documents' => 'No documents yet.',
        'saved' => 'Saved.',
        'uploaded' => 'Document stored.',
        'statuses' => [
            'en_evaluation' => 'Under evaluation',
            'valide' => 'Validated',
            'retire' => 'Withdrawn',
        ],
        'coverage_states' => [
            'not_reviewed' => 'Not reviewed',
            'sufficient' => 'Sufficient',
            'insufficient' => 'Insufficient',
        ],
    ],
```

- [ ] **Step 5: Run and commit**

Run: `php artisan test --compact tests/Feature/Dossiers/RawMaterialPagesTest.php`
Expected: PASS (2 tests).

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Dossiers/QuickSupplierCreate.php resources/views/livewire/dossiers/quick-supplier-create.blade.php lang/en/dossiers.php tests/Feature/Dossiers/RawMaterialPagesTest.php
git commit -m "Create suppliers from the DIP pages through the shared supplier action"
```

---

### Task 8: « Matières premières » pages

**Files:**
- Create: `app/Livewire/Dossiers/RawMaterialsIndex.php`, `app/Livewire/Dossiers/RawMaterialDetail.php`
- Create: `resources/views/livewire/dossiers/raw-materials-index.blade.php`, `resources/views/livewire/dossiers/raw-material-detail.blade.php`
- Create: `resources/views/dossiers/raw-materials.blade.php`, `resources/views/dossiers/raw-material.blade.php`
- Modify: `routes/web.php`, `resources/views/layouts/app-shell.blade.php`, `lang/en/dossiers.php`, `lang/en/navigation.php`, `database/seeders/data/interface-translations.json`
- Test: append to `tests/Feature/Dossiers/RawMaterialPagesTest.php`

- [ ] **Step 1: Append the failing tests**

Add the imports `use App\Livewire\Dossiers\RawMaterialDetail;`, `use App\Livewire\Dossiers\RawMaterialsIndex;`, `use App\Models\DossierEvidenceItem;`, `use App\Models\Ingredient;`, `use App\Models\Recipe;`, `use App\Models\RecipeItem;`, `use App\Models\RecipeVersion;`, `use App\Models\SupplierQualification;`, `use Illuminate\Http\UploadedFile;`, `use Illuminate\Support\Facades\Storage;`, then append:

```php
function ingredientUsedIn(Workspace $workspace, string $name): Ingredient
{
    $ingredient = Ingredient::factory()->create(['display_name' => $name]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'workspace_id' => $workspace->id]);
    RecipeItem::factory()->create(['recipe_version_id' => $version->id, 'workspace_id' => $workspace->id, 'ingredient_id' => $ingredient->id]);

    return $ingredient;
}

it('lists the ingredients used in the workspace formulas', function (): void {
    [$owner, $workspace] = rawMaterialWorkspace();
    ingredientUsedIn($workspace, 'Huile d’olive raffinée bio');
    Ingredient::factory()->create(['display_name' => 'Unused butter']);
    $this->actingAs($owner);

    Livewire::test(RawMaterialsIndex::class)
        ->assertSee('Huile d’olive raffinée bio')
        ->assertDontSee('Unused butter');
});

it('qualifies a supplier and stores a document from the detail page', function (): void {
    Storage::fake(config('dossiers.evidence_disk'));
    [$owner, $workspace] = rawMaterialWorkspace();
    $olive = ingredientUsedIn($workspace, 'Huile d’olive');
    $supplier = Supplier::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Olisud']);
    $this->actingAs($owner);

    $component = Livewire::test(RawMaterialDetail::class, ['ingredient' => $olive->public_id])
        ->fillForm(['supplier_id' => $supplier->id, 'supplier_reference' => 'HV OLIVE', 'status' => 'valide'], 'qualificationForm')
        ->call('saveQualification')
        ->assertHasNoFormErrors([], 'qualificationForm');

    $qualification = SupplierQualification::query()->sole();

    $component
        ->set('uploadQualificationId', $qualification->id)
        ->fillForm([
            'type' => 'technical_sheet',
            'issued_on' => '2021-03-01',
            'file' => UploadedFile::fake()->createWithContent('FT olive.pdf', '%PDF olive'),
        ], 'evidenceForm')
        ->call('uploadEvidence')
        ->assertHasNoFormErrors([], 'evidenceForm')
        ->assertSee('FT olive.pdf');

    expect(DossierEvidenceItem::query()->sole()->supplier_qualification_id)->toBe($qualification->id);
});

it('shows the raw materials link only to workspaces with the DIP module', function (): void {
    [$owner] = rawMaterialWorkspace();
    $this->actingAs($owner)->get(route('dossiers.raw-materials'))->assertOk()->assertSee(__('navigation.items.raw_materials'));

    $plain = User::factory()->create();
    Workspace::factory()->for($plain, 'owner')->create();
    $this->actingAs($plain)->get(route('dossiers.raw-materials'))->assertForbidden();
});
```

Before running, check that `RecipeVersion::factory()` and `RecipeItem::factory()` exist and accept these attributes (`ls database/factories | grep Recipe`); if a factory requires extra columns (e.g. `percentage`, `phase`), add them in `ingredientUsedIn()` following an existing test that builds recipe items.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Dossiers/RawMaterialPagesTest.php`
Expected: the new tests FAIL — components and routes missing.

- [ ] **Step 3: Index component**

`app/Livewire/Dossiers/RawMaterialsIndex.php`:

```php
<?php

namespace App\Livewire\Dossiers;

use App\Enums\WorkspaceModule;
use App\Models\Ingredient;
use App\Models\MaterialAssessmentCoverage;
use App\Models\RecipeItem;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class RawMaterialsIndex extends Component
{
    use WithPagination;

    #[Locked]
    public int $workspaceId;

    public function mount(): void
    {
        $this->workspaceId = $this->workspace()->id;
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        abort_unless($workspace->id === $this->workspaceId, 403);

        $usedIds = RecipeItem::withoutGlobalScopes()->where('workspace_id', $workspace->id)->distinct()->pluck('ingredient_id');
        $qualifiedIds = SupplierQualification::query()->where('workspace_id', $workspace->id)->distinct()->pluck('ingredient_id');

        $ingredients = Ingredient::query()
            ->whereIn('id', $usedIds->merge($qualifiedIds)->unique()->all())
            ->withCount([
                'supplierQualifications as validated_suppliers_count' => fn ($query) => $query
                    ->where('workspace_id', $workspace->id)
                    ->where('status', 'valide'),
            ])
            ->orderBy('display_name')
            ->paginate(25);

        $coverageStates = MaterialAssessmentCoverage::query()
            ->where('coverable_type', Ingredient::class)
            ->whereIn('coverable_id', $ingredients->pluck('id'))
            ->pluck('review_state', 'coverable_id');

        return view('livewire.dossiers.raw-materials-index', [
            'ingredients' => $ingredients,
            'coverageStates' => $coverageStates,
        ]);
    }

    private function workspace(): Workspace
    {
        $user = auth()->user();
        $workspace = $user instanceof User ? $user->company(fresh: true) : null;

        abort_unless(
            $workspace instanceof Workspace
                && app(WorkspaceAuthorization::class)->canViewModule($user, $workspace->id, WorkspaceModule::ProductDossiers),
            403,
        );

        return $workspace;
    }
}
```

Add to `app/Models/Ingredient.php`:

```php
    public function supplierQualifications(): HasMany
    {
        return $this->hasMany(SupplierQualification::class);
    }
```

`resources/views/livewire/dossiers/raw-materials-index.blade.php`:

```blade
<div class="mx-auto max-w-5xl space-y-6">
    <p class="text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('dossiers.raw_materials.intro') }}</p>

    <section class="sk-card overflow-hidden" aria-label="{{ __('dossiers.raw_materials.title') }}">
        @if ($ingredients->isEmpty())
            <p class="p-5 text-sm text-[var(--color-ink-soft)]">{{ __('dossiers.raw_materials.empty') }}</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-[var(--color-ink-soft)]">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('dossiers.raw_materials.ingredient') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('dossiers.raw_materials.inci') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('dossiers.raw_materials.validated_suppliers') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('dossiers.raw_materials.coverage') }}</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('dossiers.raw_materials.open') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ingredients as $ingredient)
                        @php($state = $coverageStates[$ingredient->id] ?? null)
                        <tr class="border-t border-[var(--color-line)]">
                            <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ $ingredient->display_name }}</td>
                            <td class="px-4 py-3 text-[var(--color-ink-soft)]">{{ $ingredient->inci_name }}</td>
                            <td class="px-4 py-3">{{ $ingredient->validated_suppliers_count }}</td>
                            <td class="px-4 py-3">{{ __('dossiers.raw_materials.coverage_states.'.($state?->value ?? 'not_reviewed')) }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('dossiers.raw-material', $ingredient) }}" wire:navigate class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">{{ __('dossiers.raw_materials.open') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-4">
                <x-table-pagination :paginator="$ingredients" />
            </div>
        @endif
    </section>
</div>
```

Check `<x-table-pagination>`'s expected attribute name by opening `resources/views/components/table-pagination.blade.php`, and use it the way `ingredients-index.blade.php` does.

- [ ] **Step 4: Detail component**

`app/Livewire/Dossiers/RawMaterialDetail.php`:

```php
<?php

namespace App\Livewire\Dossiers;

use App\Actions\Dossiers\ReplaceDossierEvidence;
use App\Actions\Dossiers\SaveSupplierQualification;
use App\Actions\Dossiers\StoreDossierEvidence;
use App\Enums\EvidenceLevel;
use App\Enums\EvidenceType;
use App\Enums\SupplierQualificationStatus;
use App\Enums\WorkspaceModule;
use App\Models\DossierEvidenceItem;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class RawMaterialDetail extends Component implements HasForms
{
    use InteractsWithForms;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public int $workspaceId;

    #[Locked]
    public int $ingredientId;

    /** @var array<string, mixed> */
    public array $qualificationData = [];

    /** @var array<string, mixed> */
    public array $evidenceData = [];

    public ?int $uploadQualificationId = null;

    public ?int $replaceItemId = null;

    public string $status = '';

    public function mount(string $ingredient): void
    {
        $workspace = $this->workspace();
        $this->workspaceId = $workspace->id;
        $this->ingredientId = Ingredient::query()
            ->where('public_id', $ingredient)
            ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->id))
            ->firstOrFail()
            ->id;
        $this->qualificationForm->fill(['status' => SupplierQualificationStatus::EnEvaluation->value]);
        $this->evidenceForm->fill();
    }

    public function qualificationForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('supplier_id')
                    ->label(__('dossiers.raw_materials.supplier'))
                    ->options(fn (): array => Supplier::query()->where('workspace_id', $this->workspaceId)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('supplier_reference')->label(__('dossiers.raw_materials.supplier_reference'))->maxLength(255),
                Select::make('status')
                    ->label(__('dossiers.raw_materials.status'))
                    ->options(collect(SupplierQualificationStatus::cases())->mapWithKeys(fn (SupplierQualificationStatus $status): array => [
                        $status->value => __('dossiers.raw_materials.statuses.'.$status->value),
                    ])->all())
                    ->required(),
            ])
            ->columns(['md' => 3])
            ->statePath('qualificationData');
    }

    public function evidenceForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label(__('dossiers.raw_materials.document_type'))
                    ->options(collect(EvidenceType::forLevel(EvidenceLevel::SupplierQualification))->mapWithKeys(fn (EvidenceType $type): array => [
                        $type->value => __('dossiers.evidence_types.'.$type->value),
                    ])->all())
                    ->required(fn (): bool => $this->replaceItemId === null)
                    ->hidden(fn (): bool => $this->replaceItemId !== null),
                DatePicker::make('issued_on')->label(__('dossiers.raw_materials.issued_on')),
                DatePicker::make('revision_due_on')->label(__('dossiers.raw_materials.revision_due_on')),
                FileUpload::make('file')
                    ->label(__('dossiers.raw_materials.file'))
                    ->storeFiles(false)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize((int) config('dossiers.evidence_max_kb'))
                    ->required(),
            ])
            ->columns(['md' => 2])
            ->statePath('evidenceData');
    }

    #[On('dossier-supplier-created')]
    public function preselectCreatedSupplier(int $supplierId): void
    {
        $this->qualificationForm->fill([...$this->qualificationData, 'supplier_id' => $supplierId]);
    }

    public function saveQualification(SaveSupplierQualification $saveQualification): void
    {
        $state = $this->qualificationForm->getState();

        $this->withFormErrors('qualificationData', fn () => $saveQualification->handle($this->user(), $this->lockedWorkspace(), [
            ...$state,
            'ingredient_id' => $this->ingredientId,
        ]));

        $this->qualificationForm->fill(['status' => SupplierQualificationStatus::EnEvaluation->value]);
        $this->status = __('dossiers.raw_materials.saved');
    }

    public function uploadEvidence(StoreDossierEvidence $storeEvidence, ReplaceDossierEvidence $replaceEvidence): void
    {
        $state = $this->evidenceForm->getState();
        /** @var UploadedFile $file */
        $file = collect($state['file'])->first();
        $attributes = ['issued_on' => $state['issued_on'] ?? null, 'revision_due_on' => $state['revision_due_on'] ?? null];

        $this->withFormErrors('evidenceData', function () use ($storeEvidence, $replaceEvidence, $file, $state, $attributes): void {
            if ($this->replaceItemId !== null) {
                $item = DossierEvidenceItem::query()->where('workspace_id', $this->workspaceId)->findOrFail($this->replaceItemId);
                $replaceEvidence->handle($this->user(), $this->lockedWorkspace(), $item, $file, $attributes);

                return;
            }

            $storeEvidence->handle($this->user(), $this->lockedWorkspace(), $file, [
                ...$attributes,
                'type' => $state['type'],
                'supplier_qualification_id' => $this->uploadQualificationId,
            ]);
        });

        $this->reset('uploadQualificationId', 'replaceItemId');
        $this->evidenceForm->fill();
        $this->status = __('dossiers.raw_materials.uploaded');
    }

    public function startUpload(int $qualificationId): void
    {
        $this->uploadQualificationId = $qualificationId;
        $this->replaceItemId = null;
        $this->evidenceForm->fill();
    }

    public function startReplace(int $itemId): void
    {
        $this->replaceItemId = $itemId;
        $this->uploadQualificationId = null;
        $this->evidenceForm->fill();
    }

    public function render(): View
    {
        abort_unless($this->workspace()->id === $this->workspaceId, 403);

        return view('livewire.dossiers.raw-material-detail', [
            'ingredient' => Ingredient::query()->findOrFail($this->ingredientId),
            'qualifications' => SupplierQualification::query()
                ->where('workspace_id', $this->workspaceId)
                ->where('ingredient_id', $this->ingredientId)
                ->with(['supplier', 'evidenceItems' => fn ($query) => $query->active()->with('file')->latest()])
                ->orderBy('status')
                ->get(),
        ]);
    }

    private function withFormErrors(string $statePath, callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($statePath.'.'.$field, $message);
                }
            }

            throw $exception;
        }
    }

    private function lockedWorkspace(): Workspace
    {
        return Workspace::withoutGlobalScopes()->findOrFail($this->workspaceId);
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->user()->company(fresh: true);

        abort_unless(
            $workspace instanceof Workspace
                && app(WorkspaceAuthorization::class)->canEditModule($this->user(), $workspace->id, WorkspaceModule::ProductDossiers),
            403,
        );

        return $workspace;
    }
}
```

Note: re-throwing in `withFormErrors()` stops the success path (status message, form reset) after a failure; Livewire still renders the added errors. If the Livewire test reports the re-thrown exception instead of form errors, replace `throw $exception;` with a `return false` and check the result before continuing in the two callers.

`resources/views/livewire/dossiers/raw-material-detail.blade.php`:

```blade
<div class="mx-auto max-w-5xl space-y-6">
    <a href="{{ route('dossiers.raw-materials') }}" wire:navigate class="text-sm text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">← {{ __('dossiers.raw_materials.back') }}</a>

    <div>
        <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ $ingredient->display_name }}</h2>
        <p class="text-sm text-[var(--color-ink-soft)]">{{ $ingredient->inci_name }}</p>
    </div>

    @if ($status)
        <p role="status" class="rounded-lg bg-[var(--color-success-soft)] px-4 py-3 text-sm text-[var(--color-success-strong)]">{{ $status }}</p>
    @endif

    <section class="sk-card space-y-4 p-5" aria-labelledby="qualified-suppliers-heading">
        <h3 id="qualified-suppliers-heading" class="text-base font-semibold text-[var(--color-ink-strong)]">{{ __('dossiers.raw_materials.suppliers_heading') }}</h3>

        @foreach ($qualifications as $qualification)
            <article class="sk-inset space-y-3 p-4" aria-label="{{ $qualification->supplier->name }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="font-medium text-[var(--color-ink-strong)]">{{ $qualification->supplier->name }}</p>
                        <p class="text-xs text-[var(--color-ink-soft)]">{{ $qualification->supplier_reference ?: '—' }} · {{ __('dossiers.raw_materials.statuses.'.$qualification->status->value) }}</p>
                    </div>
                    <button type="button" wire:click="startUpload({{ $qualification->id }})" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">{{ __('dossiers.raw_materials.upload') }}</button>
                </div>

                @if ($qualification->evidenceItems->isEmpty())
                    <p class="text-sm text-[var(--color-ink-soft)]">{{ __('dossiers.raw_materials.no_documents') }}</p>
                @else
                    <ul class="divide-y divide-[var(--color-line)] text-sm">
                        @foreach ($qualification->evidenceItems as $item)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                                <span>
                                    <span class="font-medium">{{ __('dossiers.evidence_types.'.$item->type->value) }}</span>
                                    — {{ $item->file->original_filename }}
                                    @if ($item->issued_on) · {{ $item->issued_on->toDateString() }} @endif
                                    <span class="font-mono text-xs text-[var(--color-ink-soft)]" title="{{ __('dossiers.raw_materials.fingerprint') }}">{{ substr($item->file->sha256, 0, 12) }}</span>
                                </span>
                                <span class="flex gap-2">
                                    <a href="{{ route('dossiers.evidence.download', $item) }}" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap"><x-action-icon name="download" />{{ __('dossiers.raw_materials.download') }}</a>
                                    <button type="button" wire:click="startReplace({{ $item->id }})" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">{{ __('dossiers.raw_materials.replace') }}</button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($uploadQualificationId === $qualification->id || ($replaceItemId !== null && $qualification->evidenceItems->contains('id', $replaceItemId)))
                    <form wire:submit="uploadEvidence" class="space-y-4 border-t border-[var(--color-line)] pt-4">
                        {{ $this->evidenceForm }}
                        <div class="flex justify-end">
                            <button type="submit" class="sk-btn sk-btn-primary min-h-11 py-2.5">{{ __('dossiers.raw_materials.upload') }}</button>
                        </div>
                    </form>
                @endif
            </article>
        @endforeach

        <form wire:submit="saveQualification" class="space-y-4 border-t border-[var(--color-line)] pt-4" aria-label="{{ __('dossiers.raw_materials.add_qualification') }}">
            <h4 class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('dossiers.raw_materials.add_qualification') }}</h4>
            {{ $this->qualificationForm }}
            <div class="flex justify-end">
                <button type="submit" class="sk-btn sk-btn-primary min-h-11 py-2.5">{{ __('dossiers.raw_materials.save_qualification') }}</button>
            </div>
        </form>
    </section>

    <livewire:dossiers.quick-supplier-create :key="'quick-supplier-'.$workspaceId" />
</div>
```

Check `<x-action-icon name="download" />` exists (`resources/views/components/action-icon.blade.php`) — `views.md` references it.

- [ ] **Step 5: Evidence type labels**

Add to `lang/en/dossiers.php`:

```php
    'evidence_types' => [
        'technical_sheet' => 'Technical sheet (FT)',
        'safety_data_sheet' => 'Safety data sheet (FDS)',
        'ifra_certificate' => 'IFRA certificate',
        'allergen_declaration' => 'Allergen declaration',
        'regulatory_conformity_certificate' => 'Cosmetic regulation certificate (RCE)',
        'certificate_of_analysis' => 'Certificate of analysis',
        'organic_certificate' => 'Organic certificate',
        'raw_material_animal_testing_statement' => 'Animal testing statement',
        'supplier_safety_assessment' => 'Supplier safety assessment',
        'other_supplier_document' => 'Other supplier document',
        'finished_product_specification' => 'Finished product specification',
        'stability_test' => 'Stability and compatibility test',
        'pao_assessment' => 'PAO assessment',
        'challenge_test' => 'Challenge test',
        'skin_irritation_test' => 'Skin irritation test',
        'mucosa_irritation_test' => 'Mucosa irritation test',
        'other_safety_test' => 'Other safety test',
        'primary_packaging' => 'Primary packaging',
        'secondary_packaging' => 'Secondary packaging',
        'labelling' => 'Labelling',
        'manufacturing_method' => 'Manufacturing and packing method',
        'claim_evidence' => 'Claim evidence',
        'product_animal_testing_statement' => 'Animal testing statement (product)',
        'cpnp_notification' => 'CPNP notification',
        'gmp_declaration' => 'GMP declaration',
        'assessor_qualification' => 'Safety assessor qualification',
    ],
```

- [ ] **Step 6: Page views and routes**

`resources/views/dossiers/raw-materials.blade.php`:

```blade
@extends('layouts.app-shell')

@section('title', __('dossiers.raw_materials.title').' · '.config('app.name'))
@section('page_heading', __('dossiers.raw_materials.title'))

@section('content')
    <livewire:dossiers.raw-materials-index />
@endsection
```

`resources/views/dossiers/raw-material.blade.php`:

```blade
@extends('layouts.app-shell')

@section('title', __('dossiers.raw_materials.title').' · '.config('app.name'))
@section('page_heading', __('dossiers.raw_materials.title'))

@section('content')
    <livewire:dossiers.raw-material-detail :ingredient="$ingredient" />
@endsection
```

In `routes/web.php`, inside the `['auth', 'verified']` group, add:

```php
    Route::prefix('/dashboard/dossiers')
        ->name('dossiers.')
        ->group(function (): void {
            Route::view('/raw-materials', 'dossiers.raw-materials')->name('raw-materials');
            Route::get('/raw-materials/{ingredient}', fn (string $ingredient) => view('dossiers.raw-material', ['ingredient' => $ingredient]))
                ->name('raw-material');
        });
```

`routes.md` prefers controller classes; the second route needs the route parameter in the view, so a one-line closure is the acceptable "trivial one-off" the rule allows. `route('dossiers.raw-material', $ingredient)` resolves through `HasPublicId` to the ingredient's `public_id`.

- [ ] **Step 7: Navigation**

In `lang/en/navigation.php`, add `'raw_materials' => 'Product dossiers',` under `items`. In `resources/views/layouts/app-shell.blade.php`, replace the "Compliance — coming soon" `<div>` (the block containing `navigation.items.compliance` and `navigation.status.coming_soon`) with:

```blade
                    @php($dossierUser = auth()->user())
                    @if ($dossierUser && $dossierUser->company() && app(\App\Services\WorkspaceAuthorization::class)->canViewModule($dossierUser, $dossierUser->company()->id, \App\Enums\WorkspaceModule::ProductDossiers))
                        <a href="{{ route('dossiers.raw-materials') }}" wire:navigate data-sidebar-mobile-close @if (request()->routeIs('dossiers.*')) aria-current="page" @endif class="{{ request()->routeIs('dossiers.*') ? 'border-l-[var(--color-sidebar-active-text)] font-semibold text-[var(--color-sidebar-active-text)]' : 'border-l-transparent text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} border-l-2 rounded-r-md px-4 py-3 lg:py-2 lg:pointer-coarse:py-3 transition">{{ __('navigation.items.raw_materials') }}</a>
                    @else
                        {{-- keep the existing "Compliance — coming soon" <div> here unchanged --}}
                    @endif
```

Paste the original `<div>` in the `@else` branch in place of the comment.

- [ ] **Step 8: Run the page tests**

Run: `php artisan test --compact tests/Feature/Dossiers/RawMaterialPagesTest.php`
Expected: PASS (5 tests). If Filament's test helper cannot fill a `FileUpload` with `storeFiles(false)` from an `UploadedFile`, use `->set('evidenceData.file', [$file])` instead of passing it through `fillForm()`; keep everything else.

- [ ] **Step 9: Six-locale catalogue rows**

Generate the list of new keys that need catalogue rows:

```bash
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php 2>&1 | grep -o "Missing [a-z_]* translation for [a-z_.]*" | sort -u
```

For every reported `dossiers.*` and `navigation.items.raw_materials` key, add a row with reviewed `de`, `es`, `fr`, `it`, `nl`, `pt_BR` text to a temporary JSON file outside the repository (same shape as phase 1, Task 5, Step 5), then merge it with the same Python snippet used there (sorted by group then key, 2-space indent, trailing newline). French wording to use where it matters: « Matières premières », « Fournisseurs validés », « En évaluation », « Validé », « Retiré », « Fiche technique (FT) », « Fiche de données de sécurité (FDS) », « Certificat règlement cosmétique (RCE) », « Empreinte », « Remplacer », « Télécharger ». The navigation item in French is « Dossiers produit ».

Run: `php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Dossiers app/Models/Ingredient.php resources/views/livewire/dossiers resources/views/dossiers routes/web.php resources/views/layouts/app-shell.blade.php lang/en/dossiers.php lang/en/navigation.php database/seeders/data/interface-translations.json tests/Feature/Dossiers/RawMaterialPagesTest.php
git commit -m "Add the DIP raw materials pages with supplier qualification and documents"
```

---

### Task 9: Phase verification

- [ ] **Step 1: Phase and neighbouring tests**

Run: `php artisan test --compact tests/Feature/Dossiers tests/Feature/Filament tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/SupplierCodeTest.php tests/Feature/SupplierListingManagementTest.php tests/Feature/InterfaceTranslationCatalogueTest.php`
Expected: all PASS.

- [ ] **Step 2: Migration round trip on a throwaway SQLite database**

```bash
rm -f /tmp/dip-2a.sqlite && touch /tmp/dip-2a.sqlite
DB_CONNECTION=sqlite DB_DATABASE=/tmp/dip-2a.sqlite php artisan migrate --force --no-interaction
DB_CONNECTION=sqlite DB_DATABASE=/tmp/dip-2a.sqlite php artisan migrate:rollback --step=3 --force --no-interaction
DB_CONNECTION=sqlite DB_DATABASE=/tmp/dip-2a.sqlite php artisan migrate --force --no-interaction
```

Expected: all succeed.

- [ ] **Step 3: Full suite, static checks, graph**

```bash
php artisan test --compact
vendor/bin/pint --format agent
vendor/bin/filacheck --fix
graphify update .
```

- [ ] **Step 4: Local database and browser (owner present)**

With the owner's go-ahead: `php artisan migrate --no-interaction`, then `php artisan translations:catalogue:import --mode=preserve-existing --no-interaction`. In the browser: the sidebar shows « Dossiers produit »; the raw materials list shows the workspace's ingredients; on one ingredient, create a supplier, qualify it as « Validé », upload a PDF, download it and confirm the fingerprint shown matches `shasum -a 256` of the file. PostgreSQL-specific checks: the qualification unique index with an empty reference, and the supplier `Select` options query.
