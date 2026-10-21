# DIP Phase 2b — Structured Document Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record what each supplier document states — allergen %, impurities in ppm, restricted substances, maximum use levels per IFRA category or product class, peroxide value, microbiology specification — and resolve, per ingredient, the worst case across validated suppliers with a flagged fallback to the platform composition. Also let a document uploaded by mistake be withdrawn without a replacement.

**Architecture:** Document facts live in two child tables of `dossier_evidence_items` (`dossier_evidence_constituents`, `dossier_evidence_use_limits`) plus a small typed `facts` JSON column. One action (`SaveDossierEvidenceData`) replaces a document's facts atomically under the workspace lock, with per-type rules. A read-only resolver (`SupplierConstituentResolver`) gives phase 3 its inputs: for each allergen or impurity of an ingredient, the highest value declared by any validated supplier, which supplier documents it came from, which validated suppliers declared nothing, and — only when no supplier document gives a value — the platform composition marked « À justifier ».

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4, Filament 5 schemas in public Livewire components, Pest 4.

**Scope (agreed 2026-10-21):** Phase 2 is now 2a (done), **2b (this plan)**, 2c (one-time import of the existing PDF folders). The generated annexes b/l/o/t move to phase 4, where dossier versions exist. Data-source rule chosen by the owner: **supplier documents first** (worst case across validated suppliers), platform composition only as a flagged fallback.

---

## Before you start

- Read `.ai/rules/index.md` and the rule files for the paths you touch (`app.md`, `actions.md`, `models.md`, `migrations.md`, `livewire.md`, `lang.md`, `tests.md`, `services.md`).
- `export LANG=en_US.UTF-8` before running tests. Pint after PHP changes. Do not migrate the owner's local database during the tasks; Task 7 does it at the end with the owner present.

## Decisions taken while planning

1. **Units are fixed per kind**, so values compare without conversion: allergens and restricted substances in **%**, impurities in **ppm**. The form shows the unit; the action rejects a mismatch.
2. **A constituent points to exactly one catalogue entry:** an allergen (`allergen_catalog`, 82 entries) or a substance (`substance_catalog`). Allergens use the allergen catalogue, as `ingredient_allergen_entries` already does; impurities and restricted substances use substances (the admin adds impurities such as lead as substances when first needed).
3. **Which facts each document type may carry:**

| Document type | Constituents | Use limits | Facts |
| --- | --- | --- | --- |
| Allergen declaration | allergens (%) | — | — |
| Technical sheet, certificate of analysis | impurities (ppm) | — | microbiology specification |
| RCE (cosmetic regulation certificate) | restricted substances (%) | per product class (free label, e.g. « Autres produits à rincer ») | peroxide value |
| IFRA certificate | — | per IFRA category | peroxide value |
| Supplier safety assessment, others | — | — | — |

4. **Document facts are editable** (who/when recorded on the item) until a dossier version snapshots them in phase 4; the file and its type never change.
5. **Withdrawal:** « Retirer (téléversé par erreur) » sets `retired_at`, records the reason and who withdrew it, and keeps file and record. It is refused when the item has already been replaced.
6. **Resolver output per ingredient and catalogue entry:** `max_value`, `unit`, the evidence items that declared it, `suppliers_without_declaration` (validated qualifications with no active document of the relevant type), `source` (`supplier` or `platform`) and `needs_justification` (true for the platform fallback and whenever a validated supplier has no declaration).

## File structure

| Area | Files |
| --- | --- |
| Withdrawal | migration `add_withdrawal_to_dossier_evidence_items`, modify `app/Models/DossierEvidenceItem.php`, create `app/Actions/Dossiers/WithdrawDossierEvidence.php`, test `tests/Feature/Dossiers/DossierEvidenceWithdrawalTest.php` |
| Data model | `app/Enums/ConstituentKind.php`, migration `create_dossier_evidence_data_tables`, `app/Models/DossierEvidenceConstituent.php`, `app/Models/DossierEvidenceUseLimit.php`, factories, modify `app/Enums/EvidenceType.php`, `app/Models/DossierEvidenceItem.php` |
| Save action | `app/Actions/Dossiers/SaveDossierEvidenceData.php`, test `tests/Feature/Dossiers/DossierEvidenceDataTest.php` |
| Resolver | `app/Services/Dossiers/SupplierConstituentResolver.php`, test `tests/Feature/Dossiers/SupplierConstituentResolverTest.php` |
| Page | modify `app/Livewire/Dossiers/RawMaterialDetail.php`, `resources/views/livewire/dossiers/raw-material-detail.blade.php`, `lang/en/dossiers.php`, catalogue JSON; test additions in `tests/Feature/Dossiers/RawMaterialPagesTest.php` |

---

### Task 1: Withdraw a document uploaded by mistake

**Files:**
- Create: `database/migrations/<timestamp>_add_withdrawal_to_dossier_evidence_items.php`
- Modify: `app/Models/DossierEvidenceItem.php`
- Create: `app/Actions/Dossiers/WithdrawDossierEvidence.php`
- Test: `tests/Feature/Dossiers/DossierEvidenceWithdrawalTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Actions\Dossiers\WithdrawDossierEvidence;
use App\Models\DossierEvidenceItem;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace, 2: DossierEvidenceItem}
 */
function withdrawableEvidence(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $item = DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => SupplierQualification::factory()->create(['workspace_id' => $workspace->id])->id,
    ]);

    return [$owner, $workspace, $item];
}

it('withdraws a document uploaded by mistake and keeps its record and file', function (): void {
    [$owner, $workspace, $item] = withdrawableEvidence();

    app(WithdrawDossierEvidence::class)->handle($owner, $workspace, $item, 'Wrong document type');

    $item->refresh();

    expect($item->isActive())->toBeFalse()
        ->and($item->withdrawn_reason)->toBe('Wrong document type')
        ->and($item->withdrawn_by_user_id)->toBe($owner->id)
        ->and($item->replaced_by_id)->toBeNull()
        ->and($item->file)->not->toBeNull();
});

it('requires a reason and refuses an item that is no longer current', function (): void {
    [$owner, $workspace, $item] = withdrawableEvidence();

    expect(fn () => app(WithdrawDossierEvidence::class)->handle($owner, $workspace, $item, '  '))->toThrow(ValidationException::class);

    $item->update(['retired_at' => now()]);

    expect(fn () => app(WithdrawDossierEvidence::class)->handle($owner, $workspace, $item, 'Duplicate'))->toThrow(ValidationException::class);
});
```

Run: `php artisan test --compact tests/Feature/Dossiers/DossierEvidenceWithdrawalTest.php` — Expected: FAIL (action missing).

- [ ] **Step 2: Migration**

`php artisan make:migration add_withdrawal_to_dossier_evidence_items --no-interaction`, then:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_evidence_items', function (Blueprint $table) {
            $table->text('withdrawn_reason')->nullable()->after('replaced_by_id');
            $table->foreignId('withdrawn_by_user_id')->nullable()->after('withdrawn_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dossier_evidence_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawn_by_user_id');
            $table->dropColumn('withdrawn_reason');
        });
    }
};
```

- [ ] **Step 3: Model**

In `DossierEvidenceItem`, add `'withdrawn_reason', 'withdrawn_by_user_id'` to `#[Fillable]`.

- [ ] **Step 4: Action** — `app/Actions/Dossiers/WithdrawDossierEvidence.php`:

```php
<?php

namespace App\Actions\Dossiers;

use App\Enums\WorkspaceModule;
use App\Models\DossierEvidenceItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawDossierEvidence
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    /**
     * Retires a current document without a replacement (e.g. uploaded by mistake). Record and file are kept.
     */
    public function handle(User $actor, Workspace $workspace, DossierEvidenceItem $item, string $reason): DossierEvidenceItem
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['withdrawn_reason' => __('dossiers.evidence.withdrawal_reason_required')]);
        }

        return DB::transaction(function () use ($actor, $workspace, $item, $reason): DossierEvidenceItem {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $freshActor = $actor->fresh();

            if (! $freshActor instanceof User
                || ! $this->authorization->canEditModule($freshActor, $workspace->id, WorkspaceModule::ProductDossiers)) {
                throw new AuthorizationException;
            }

            $current = DossierEvidenceItem::query()->where('workspace_id', $workspace->id)->whereKey($item->getKey())->lockForUpdate()->first();

            if (! $current instanceof DossierEvidenceItem || ! $current->isActive()) {
                throw ValidationException::withMessages(['evidence' => __('dossiers.evidence.not_withdrawable')]);
            }

            $current->update([
                'retired_at' => now(),
                'withdrawn_reason' => trim($reason),
                'withdrawn_by_user_id' => $freshActor->id,
            ]);

            return $current;
        }, attempts: 5);
    }
}
```

- [ ] **Step 5: Strings** — add to `dossiers.evidence` in `lang/en/dossiers.php`: `'withdrawal_reason_required' => 'Say why this document is withdrawn.'`, `'not_withdrawable' => 'Only a current document of this workspace can be withdrawn.'`.

- [ ] **Step 6: Run and commit**

Run the test file — Expected: PASS (2). Commit: `Allow withdrawing a DIP document uploaded by mistake`.

---

### Task 2: Document data model

**Files:** `app/Enums/ConstituentKind.php`; migration `create_dossier_evidence_data_tables`; `app/Models/DossierEvidenceConstituent.php`, `app/Models/DossierEvidenceUseLimit.php`, their factories; modify `app/Enums/EvidenceType.php`, `app/Models/DossierEvidenceItem.php`.

- [ ] **Step 1: Enum** — `app/Enums/ConstituentKind.php`:

```php
<?php

namespace App\Enums;

enum ConstituentKind: string
{
    case Allergen = 'allergen';
    case Impurity = 'impurity';
    case RestrictedSubstance = 'restricted_substance';

    /** Fixed unit so values compare without conversion. */
    public function unit(): string
    {
        return $this === self::Impurity ? 'ppm' : 'percent';
    }

    public function usesAllergenCatalogue(): bool
    {
        return $this === self::Allergen;
    }
}
```

- [ ] **Step 2: What each document type may carry** — add to `EvidenceType`:

```php
    /**
     * @return list<ConstituentKind>
     */
    public function constituentKinds(): array
    {
        return match ($this) {
            self::AllergenDeclaration => [ConstituentKind::Allergen],
            self::TechnicalSheet, self::CertificateOfAnalysis => [ConstituentKind::Impurity],
            self::RegulatoryConformityCertificate => [ConstituentKind::RestrictedSubstance],
            default => [],
        };
    }

    /** 'ifra_category', 'product_class' or null. */
    public function useLimitBasis(): ?string
    {
        return match ($this) {
            self::IfraCertificate => 'ifra_category',
            self::RegulatoryConformityCertificate => 'product_class',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public function factKeys(): array
    {
        return match ($this) {
            self::TechnicalSheet, self::CertificateOfAnalysis => ['microbiology_specification'],
            self::RegulatoryConformityCertificate, self::IfraCertificate => ['peroxide_value_mmol_l'],
            default => [],
        };
    }
```

- [ ] **Step 3: Migration** — `php artisan make:migration create_dossier_evidence_data_tables --no-interaction`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_evidence_constituents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_evidence_item_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->unsignedBigInteger('allergen_id')->nullable();
            $table->foreign('allergen_id')->references('id')->on('allergen_catalog')->restrictOnDelete();
            $table->unsignedBigInteger('substance_id')->nullable();
            $table->foreign('substance_id')->references('id')->on('substance_catalog')->restrictOnDelete();
            $table->decimal('max_value', 20, 6);
            $table->string('unit', 16);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['allergen_id']);
            $table->index(['substance_id']);
        });

        Schema::create('dossier_evidence_use_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_evidence_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('ifra_product_category_id')->nullable();
            $table->foreign('ifra_product_category_id')->references('id')->on('ifra_product_categories')->restrictOnDelete();
            $table->string('product_class_label')->nullable();
            $table->decimal('max_percent', 12, 6);
            $table->timestamps();
        });

        Schema::table('dossier_evidence_items', function (Blueprint $table) {
            $table->json('facts')->nullable()->after('notes');
            $table->foreignId('data_updated_by_user_id')->nullable()->after('facts')->constrained('users')->nullOnDelete();
            $table->timestamp('data_updated_at')->nullable()->after('data_updated_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('dossier_evidence_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('data_updated_by_user_id');
            $table->dropColumn(['facts', 'data_updated_at']);
        });
        Schema::dropIfExists('dossier_evidence_use_limits');
        Schema::dropIfExists('dossier_evidence_constituents');
    }
};
```

- [ ] **Step 4: Models**

`app/Models/DossierEvidenceConstituent.php`:

```php
<?php

namespace App\Models;

use App\Enums\ConstituentKind;
use Database\Factories\DossierEvidenceConstituentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A maximum content declared by a supplier document: allergen (%), impurity (ppm) or restricted substance (%).
 */
#[Fillable(['dossier_evidence_item_id', 'kind', 'allergen_id', 'substance_id', 'max_value', 'unit', 'note'])]
class DossierEvidenceConstituent extends Model
{
    /** @use HasFactory<DossierEvidenceConstituentFactory> */
    use HasFactory;

    public function item(): BelongsTo
    {
        return $this->belongsTo(DossierEvidenceItem::class, 'dossier_evidence_item_id');
    }

    public function allergen(): BelongsTo
    {
        return $this->belongsTo(Allergen::class);
    }

    public function substance(): BelongsTo
    {
        return $this->belongsTo(Substance::class);
    }

    protected function casts(): array
    {
        return ['kind' => ConstituentKind::class, 'max_value' => 'decimal:6'];
    }
}
```

`app/Models/DossierEvidenceUseLimit.php`:

```php
<?php

namespace App\Models;

use Database\Factories\DossierEvidenceUseLimitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A maximum use level stated by an IFRA certificate (per IFRA category) or an RCE (per product class).
 */
#[Fillable(['dossier_evidence_item_id', 'ifra_product_category_id', 'product_class_label', 'max_percent'])]
class DossierEvidenceUseLimit extends Model
{
    /** @use HasFactory<DossierEvidenceUseLimitFactory> */
    use HasFactory;

    public function item(): BelongsTo
    {
        return $this->belongsTo(DossierEvidenceItem::class, 'dossier_evidence_item_id');
    }

    public function ifraProductCategory(): BelongsTo
    {
        return $this->belongsTo(IfraProductCategory::class);
    }

    protected function casts(): array
    {
        return ['max_percent' => 'decimal:6'];
    }
}
```

Factories (`database/factories/DossierEvidenceConstituentFactory.php`, `DossierEvidenceUseLimitFactory.php`):

```php
<?php

namespace Database\Factories;

use App\Enums\ConstituentKind;
use App\Models\Allergen;
use App\Models\DossierEvidenceConstituent;
use App\Models\DossierEvidenceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierEvidenceConstituent>
 */
class DossierEvidenceConstituentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dossier_evidence_item_id' => DossierEvidenceItem::factory(),
            'kind' => ConstituentKind::Allergen,
            'allergen_id' => Allergen::factory(),
            'substance_id' => null,
            'max_value' => '0.5',
            'unit' => 'percent',
            'note' => null,
        ];
    }
}
```

```php
<?php

namespace Database\Factories;

use App\Models\DossierEvidenceItem;
use App\Models\DossierEvidenceUseLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierEvidenceUseLimit>
 */
class DossierEvidenceUseLimitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dossier_evidence_item_id' => DossierEvidenceItem::factory(),
            'ifra_product_category_id' => null,
            'product_class_label' => 'Autres produits à rincer',
            'max_percent' => '100',
        ];
    }
}
```

Check `Allergen::factory()` exists (`ls database/factories | grep Allergen`); if not, create the allergen with explicit attributes matching `allergen_catalog`'s required columns in tests.

In `DossierEvidenceItem` add `'facts', 'data_updated_by_user_id', 'data_updated_at'` to Fillable, `'facts' => 'array', 'data_updated_at' => 'datetime'` to casts, and:

```php
    public function constituents(): HasMany
    {
        return $this->hasMany(DossierEvidenceConstituent::class);
    }

    public function useLimits(): HasMany
    {
        return $this->hasMany(DossierEvidenceUseLimit::class);
    }
```

- [ ] **Step 5: Run the existing DIP tests** — `php artisan test --compact tests/Feature/Dossiers` — Expected: PASS. Commit: `Add structured data tables for DIP supplier documents`.

---

### Task 3: Save a document's data

**Files:** `app/Actions/Dossiers/SaveDossierEvidenceData.php`; test `tests/Feature/Dossiers/DossierEvidenceDataTest.php`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Actions\Dossiers\SaveDossierEvidenceData;
use App\Enums\EvidenceType;
use App\Models\Allergen;
use App\Models\DossierEvidenceItem;
use App\Models\IfraProductCategory;
use App\Models\Substance;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace}
 */
function evidenceDataWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();

    return [$owner, $workspace];
}

function evidenceOfType(Workspace $workspace, EvidenceType $type): DossierEvidenceItem
{
    return DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => SupplierQualification::factory()->create(['workspace_id' => $workspace->id])->id,
        'type' => $type,
    ]);
}

it('records an allergen declaration and replaces it on the next save', function (): void {
    [$owner, $workspace] = evidenceDataWorkspace();
    $item = evidenceOfType($workspace, EvidenceType::AllergenDeclaration);
    $linalool = Allergen::factory()->create(['inci_name' => 'Linalool']);
    $limonene = Allergen::factory()->create(['inci_name' => 'Limonene']);

    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $item, [
        'constituents' => [
            ['kind' => 'allergen', 'allergen_id' => $linalool->id, 'max_value' => '35.5'],
            ['kind' => 'allergen', 'allergen_id' => $limonene->id, 'max_value' => '0.8'],
        ],
    ]);
    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $item, [
        'constituents' => [['kind' => 'allergen', 'allergen_id' => $linalool->id, 'max_value' => '36']],
    ]);

    $item->refresh();

    expect($item->constituents)->toHaveCount(1)
        ->and($item->constituents->first()->max_value)->toBe('36.000000')
        ->and($item->constituents->first()->unit)->toBe('percent')
        ->and($item->data_updated_by_user_id)->toBe($owner->id);
});

it('records impurities in ppm, IFRA limits per category and RCE facts', function (): void {
    [$owner, $workspace] = evidenceDataWorkspace();
    $lead = Substance::factory()->create(['name' => 'Lead']);
    $sheet = evidenceOfType($workspace, EvidenceType::TechnicalSheet);
    $ifra = evidenceOfType($workspace, EvidenceType::IfraCertificate);
    $rce = evidenceOfType($workspace, EvidenceType::RegulatoryConformityCertificate);
    $category9 = IfraProductCategory::factory()->create(['code' => '9']);

    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $sheet, [
        'constituents' => [['kind' => 'impurity', 'substance_id' => $lead->id, 'max_value' => '2']],
        'facts' => ['microbiology_specification' => 'TAMC < 100 CFU/g'],
    ]);
    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $ifra, [
        'use_limits' => [['ifra_product_category_id' => $category9->id, 'max_percent' => '12.5']],
        'facts' => ['peroxide_value_mmol_l' => '4.2'],
    ]);
    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $rce, [
        'use_limits' => [['product_class_label' => 'Autres produits à rincer', 'max_percent' => '100']],
    ]);

    expect($sheet->constituents()->sole()->unit)->toBe('ppm')
        ->and($sheet->refresh()->facts['microbiology_specification'])->toBe('TAMC < 100 CFU/g')
        ->and($ifra->useLimits()->sole()->ifra_product_category_id)->toBe($category9->id)
        ->and($rce->useLimits()->sole()->product_class_label)->toBe('Autres produits à rincer');
});

it('rejects data the document type cannot carry', function (EvidenceType $type, array $data, string $errorKey): void {
    [$owner, $workspace] = evidenceDataWorkspace();
    $item = evidenceOfType($workspace, $type);
    $data = json_decode(str_replace(
        ['"ALLERGEN"', '"SUBSTANCE"'],
        [(string) Allergen::factory()->create()->id, (string) Substance::factory()->create()->id],
        json_encode($data),
    ), true);

    try {
        app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $item, $data);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toContain($errorKey);
    }
})->with([
    'impurity on an allergen declaration' => [EvidenceType::AllergenDeclaration, ['constituents' => [['kind' => 'impurity', 'substance_id' => 'SUBSTANCE', 'max_value' => '1']]], 'constituents.0.kind'],
    'allergen without allergen reference' => [EvidenceType::AllergenDeclaration, ['constituents' => [['kind' => 'allergen', 'substance_id' => 'SUBSTANCE', 'max_value' => '1']]], 'constituents.0.allergen_id'],
    'percentage above 100' => [EvidenceType::AllergenDeclaration, ['constituents' => [['kind' => 'allergen', 'allergen_id' => 'ALLERGEN', 'max_value' => '120']]], 'constituents.0.max_value'],
    'use limits on a technical sheet' => [EvidenceType::TechnicalSheet, ['use_limits' => [['product_class_label' => 'x', 'max_percent' => '1']]], 'use_limits'],
    'IFRA limit without category' => [EvidenceType::IfraCertificate, ['use_limits' => [['product_class_label' => 'x', 'max_percent' => '1']]], 'use_limits.0.ifra_product_category_id'],
    'unknown fact' => [EvidenceType::AllergenDeclaration, ['facts' => ['peroxide_value_mmol_l' => '1']], 'facts'],
]);

it('refuses data on a retired document or without the module', function (): void {
    [$owner, $workspace] = evidenceDataWorkspace();
    $retired = evidenceOfType($workspace, EvidenceType::AllergenDeclaration);
    $retired->update(['retired_at' => now()]);

    expect(fn () => app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $retired, ['constituents' => []]))
        ->toThrow(ValidationException::class);

    $outsider = User::factory()->create();
    $plain = Workspace::factory()->for($outsider, 'owner')->create();

    expect(fn () => app(SaveDossierEvidenceData::class)->handle($outsider, $plain, evidenceOfType($plain, EvidenceType::AllergenDeclaration), ['constituents' => []]))
        ->toThrow(AuthorizationException::class);
});
```

Run: Expected FAIL (action missing).

- [ ] **Step 2: Action** — `app/Actions/Dossiers/SaveDossierEvidenceData.php`:

```php
<?php

namespace App\Actions\Dossiers;

use App\Enums\ConstituentKind;
use App\Enums\WorkspaceModule;
use App\Models\DossierEvidenceItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveDossierEvidenceData
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    /**
     * Replaces everything a document states (constituents, use limits, facts) in one transaction.
     *
     * @param  array{constituents?: list<array<string, mixed>>, use_limits?: list<array<string, mixed>>, facts?: array<string, mixed>}  $data
     */
    public function handle(User $actor, Workspace $workspace, DossierEvidenceItem $item, array $data): DossierEvidenceItem
    {
        return DB::transaction(function () use ($actor, $workspace, $item, $data): DossierEvidenceItem {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $freshActor = $actor->fresh();

            if (! $freshActor instanceof User
                || ! $this->authorization->canEditModule($freshActor, $workspace->id, WorkspaceModule::ProductDossiers)) {
                throw new AuthorizationException;
            }

            $current = DossierEvidenceItem::query()->where('workspace_id', $workspace->id)->whereKey($item->getKey())->lockForUpdate()->first();

            if (! $current instanceof DossierEvidenceItem || ! $current->isActive()) {
                throw ValidationException::withMessages(['evidence' => __('dossiers.evidence.not_editable')]);
            }

            $validated = $this->validate($current, $data);

            $current->constituents()->delete();
            $current->useLimits()->delete();

            foreach ($validated['constituents'] as $row) {
                $kind = ConstituentKind::from($row['kind']);
                $current->constituents()->create([
                    'kind' => $kind,
                    'allergen_id' => $kind->usesAllergenCatalogue() ? $row['allergen_id'] : null,
                    'substance_id' => $kind->usesAllergenCatalogue() ? null : $row['substance_id'],
                    'max_value' => $row['max_value'],
                    'unit' => $kind->unit(),
                    'note' => $row['note'] ?? null,
                ]);
            }

            foreach ($validated['use_limits'] as $row) {
                $current->useLimits()->create([
                    'ifra_product_category_id' => $current->type->useLimitBasis() === 'ifra_category' ? $row['ifra_product_category_id'] : null,
                    'product_class_label' => $current->type->useLimitBasis() === 'product_class' ? trim((string) $row['product_class_label']) : null,
                    'max_percent' => $row['max_percent'],
                ]);
            }

            $current->update([
                'facts' => $validated['facts'] === [] ? null : $validated['facts'],
                'data_updated_by_user_id' => $freshActor->id,
                'data_updated_at' => now(),
            ]);

            return $current->load(['constituents', 'useLimits']);
        }, attempts: 5);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{constituents: list<array<string, mixed>>, use_limits: list<array<string, mixed>>, facts: array<string, mixed>}
     */
    private function validate(DossierEvidenceItem $item, array $data): array
    {
        $type = $item->type;
        $kinds = array_map(fn (ConstituentKind $kind): string => $kind->value, $type->constituentKinds());
        $basis = $type->useLimitBasis();
        $constituents = array_values($data['constituents'] ?? []);
        $useLimits = array_values($data['use_limits'] ?? []);
        $facts = array_filter($data['facts'] ?? [], fn (mixed $value): bool => filled($value));

        $errors = [];

        if ($kinds === [] && $constituents !== []) {
            $errors['constituents'] = __('dossiers.evidence.no_constituents_for_type');
        }

        if ($basis === null && $useLimits !== []) {
            $errors['use_limits'] = __('dossiers.evidence.no_use_limits_for_type');
        }

        if (array_diff(array_keys($facts), $type->factKeys()) !== []) {
            $errors['facts'] = __('dossiers.evidence.unknown_facts_for_type');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $rules = [
            'constituents' => ['array'],
            'constituents.*.kind' => ['required', Rule::in($kinds)],
            'constituents.*.allergen_id' => ['required_if:constituents.*.kind,allergen', 'nullable', 'integer', 'exists:allergen_catalog,id'],
            'constituents.*.substance_id' => ['exclude_if:constituents.*.kind,allergen', 'required', 'integer', 'exists:substance_catalog,id'],
            'constituents.*.max_value' => ['required', 'numeric', 'gt:0'],
            'constituents.*.note' => ['nullable', 'string', 'max:500'],
            'use_limits' => ['array'],
            'use_limits.*.max_percent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'use_limits.*.ifra_product_category_id' => $basis === 'ifra_category'
                ? ['required', 'integer', 'exists:ifra_product_categories,id']
                : ['nullable'],
            'use_limits.*.product_class_label' => $basis === 'product_class'
                ? ['required', 'string', 'max:255']
                : ['nullable'],
            'facts.peroxide_value_mmol_l' => ['nullable', 'numeric', 'gte:0'],
            'facts.microbiology_specification' => ['nullable', 'string', 'max:2000'],
        ];

        $validator = Validator::make(['constituents' => $constituents, 'use_limits' => $useLimits, 'facts' => $facts], $rules);
        $validator->after(function ($validator) use ($constituents): void {
            foreach ($constituents as $index => $row) {
                $isPercent = ($row['kind'] ?? null) !== ConstituentKind::Impurity->value;

                if ($isPercent && is_numeric($row['max_value'] ?? null) && (float) $row['max_value'] > 100) {
                    $validator->errors()->add("constituents.{$index}.max_value", __('dossiers.evidence.percent_above_100'));
                }
            }
        });
        $validator->validate();

        return ['constituents' => $constituents, 'use_limits' => $useLimits, 'facts' => $facts];
    }
}
```

Note on the `allergen_id` rule: an allergen row given only a `substance_id` must fail on `constituents.N.allergen_id` (the test expects that key); `required_if` provides it.

- [ ] **Step 3: Strings** — add to `dossiers.evidence`: `not_editable` « Only a current document of this workspace can be edited. », `no_constituents_for_type` « This document type does not declare constituents. », `no_use_limits_for_type` « This document type does not state use limits. », `unknown_facts_for_type` « This document type does not carry that information. », `percent_above_100` « A percentage cannot exceed 100. ».

- [ ] **Step 4: Run and commit** — Expected PASS (4 tests + 6 dataset cases). Commit: `Record what DIP supplier documents state`.

---

### Task 4: Worst-case resolver with platform fallback

**Files:** `app/Services/Dossiers/SupplierConstituentResolver.php`; test `tests/Feature/Dossiers/SupplierConstituentResolverTest.php`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\ConstituentKind;
use App\Enums\EvidenceType;
use App\Models\Allergen;
use App\Models\DossierEvidenceConstituent;
use App\Models\DossierEvidenceItem;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use App\Services\Dossiers\SupplierConstituentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function declaredAllergen(SupplierQualification $qualification, Allergen $allergen, string $value, bool $retired = false): DossierEvidenceItem
{
    $item = DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => $qualification->id,
        'type' => EvidenceType::AllergenDeclaration,
        'retired_at' => $retired ? now() : null,
    ]);
    DossierEvidenceConstituent::factory()->create([
        'dossier_evidence_item_id' => $item->id,
        'kind' => ConstituentKind::Allergen,
        'allergen_id' => $allergen->id,
        'max_value' => $value,
    ]);

    return $item;
}

it('takes the worst case across validated suppliers and ignores retired documents and withdrawn suppliers', function (): void {
    $workspace = Workspace::factory()->create();
    $lavandin = Ingredient::factory()->create();
    $linalool = Allergen::factory()->create();
    $a = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id]);
    $b = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id]);
    $withdrawn = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id, 'status' => 'retire']);
    declaredAllergen($a, $linalool, '30');
    $worst = declaredAllergen($b, $linalool, '36');
    declaredAllergen($b, $linalool, '50', retired: true);
    declaredAllergen($withdrawn, $linalool, '80');

    $row = app(SupplierConstituentResolver::class)->allergens($workspace, $lavandin)->sole();

    expect($row['allergen_id'])->toBe($linalool->id)
        ->and($row['max_value'])->toBe('36.000000')
        ->and($row['source'])->toBe('supplier')
        ->and($row['evidence_item_ids'])->toContain($worst->id)
        ->and($row['suppliers_without_declaration'])->toBe([])
        ->and($row['needs_justification'])->toBeFalse();
});

it('flags a validated supplier that has no allergen declaration', function (): void {
    $workspace = Workspace::factory()->create();
    $lavandin = Ingredient::factory()->create();
    $declared = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id]);
    $silent = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id]);
    declaredAllergen($declared, Allergen::factory()->create(), '30');

    $row = app(SupplierConstituentResolver::class)->allergens($workspace, $lavandin)->sole();

    expect($row['suppliers_without_declaration'])->toBe([$silent->id])
        ->and($row['needs_justification'])->toBeTrue();
});

it('falls back to the platform composition only where no supplier document gives a value, flagged', function (): void {
    $workspace = Workspace::factory()->create();
    $lavandin = Ingredient::factory()->create();
    $linalool = Allergen::factory()->create();
    $limonene = Allergen::factory()->create();
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id]);
    declaredAllergen($qualification, $linalool, '30');
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $lavandin->id, 'allergen_id' => $linalool->id, 'concentration_percent' => 45]);
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $lavandin->id, 'allergen_id' => $limonene->id, 'concentration_percent' => 0.5]);

    $rows = app(SupplierConstituentResolver::class)->allergens($workspace, $lavandin)->keyBy('allergen_id');

    expect($rows[$linalool->id]['source'])->toBe('supplier')
        ->and($rows[$linalool->id]['max_value'])->toBe('30.000000')
        ->and($rows[$limonene->id]['source'])->toBe('platform')
        ->and($rows[$limonene->id]['max_value'])->toBe('0.500000')
        ->and($rows[$limonene->id]['needs_justification'])->toBeTrue();
});

it('resolves impurities in ppm the same way', function (): void {
    $workspace = Workspace::factory()->create();
    $olive = Ingredient::factory()->create();
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $olive->id]);
    $sheet = DossierEvidenceItem::factory()->create(['supplier_qualification_id' => $qualification->id, 'type' => EvidenceType::TechnicalSheet]);
    $lead = DossierEvidenceConstituent::factory()->create([
        'dossier_evidence_item_id' => $sheet->id, 'kind' => ConstituentKind::Impurity,
        'allergen_id' => null, 'substance_id' => App\Models\Substance::factory(), 'max_value' => '0.1', 'unit' => 'ppm',
    ]);

    $row = app(SupplierConstituentResolver::class)->impurities($workspace, $olive)->sole();

    expect($row['substance_id'])->toBe($lead->substance_id)
        ->and($row['unit'])->toBe('ppm')
        ->and($row['max_value'])->toBe('0.100000');
});
```

Check `IngredientAllergenEntry::factory()` exists and accepts these attributes.

- [ ] **Step 2: Resolver** — `app/Services/Dossiers/SupplierConstituentResolver.php`:

```php
<?php

namespace App\Services\Dossiers;

use App\Enums\ConstituentKind;
use App\Enums\EvidenceType;
use App\Enums\SupplierQualificationStatus;
use App\Models\DossierEvidenceConstituent;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Inputs for the DIP calculations: per allergen or impurity of an ingredient, the worst case declared by the
 * workspace's validated suppliers. Supplier documents come first; the platform composition is only a flagged
 * fallback where no supplier document gives a value (owner decision, 2026-10-21).
 */
class SupplierConstituentResolver
{
    /**
     * @return Collection<int, array{allergen_id: int, max_value: string, unit: string, source: string,
     *                               evidence_item_ids: list<int>, suppliers_without_declaration: list<int>, needs_justification: bool}>
     */
    public function allergens(Workspace $workspace, Ingredient $ingredient): Collection
    {
        $silent = $this->qualificationsWithout($workspace, $ingredient, EvidenceType::AllergenDeclaration);
        $declared = $this->worstCase($workspace, $ingredient, ConstituentKind::Allergen, 'allergen_id', $silent);

        $fallback = IngredientAllergenEntry::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereNotIn('allergen_id', $declared->keys()->all())
            ->get()
            ->map(fn (IngredientAllergenEntry $entry): array => [
                'allergen_id' => $entry->allergen_id,
                'max_value' => number_format((float) $entry->concentration_percent, 6, '.', ''),
                'unit' => 'percent',
                'source' => 'platform',
                'evidence_item_ids' => [],
                'suppliers_without_declaration' => $silent,
                'needs_justification' => true,
            ]);

        return $declared->values()->concat($fallback)->values();
    }

    /**
     * @return Collection<int, array{substance_id: int, max_value: string, unit: string, source: string,
     *                               evidence_item_ids: list<int>, suppliers_without_declaration: list<int>, needs_justification: bool}>
     */
    public function impurities(Workspace $workspace, Ingredient $ingredient): Collection
    {
        $silent = $this->qualificationsWithout($workspace, $ingredient, EvidenceType::TechnicalSheet, EvidenceType::CertificateOfAnalysis);

        return $this->worstCase($workspace, $ingredient, ConstituentKind::Impurity, 'substance_id', $silent)->values();
    }

    /**
     * @param  list<int>  $silent
     * @return Collection<int, array<string, mixed>>
     */
    private function worstCase(Workspace $workspace, Ingredient $ingredient, ConstituentKind $kind, string $key, array $silent): Collection
    {
        return DossierEvidenceConstituent::query()
            ->where('kind', $kind->value)
            ->whereHas('item', fn ($query) => $query
                ->where('workspace_id', $workspace->id)
                ->whereNull('retired_at')
                ->whereHas('supplierQualification', fn ($qualification) => $qualification
                    ->where('ingredient_id', $ingredient->id)
                    ->where('status', SupplierQualificationStatus::Valide->value)))
            ->get()
            ->groupBy($key)
            ->map(function (Collection $rows, int|string $id) use ($key, $kind, $silent): array {
                $worst = $rows->sortByDesc(fn (DossierEvidenceConstituent $row): float => (float) $row->max_value)->first();

                return [
                    $key => (int) $id,
                    'max_value' => (string) $worst->max_value,
                    'unit' => $kind->unit(),
                    'source' => 'supplier',
                    'evidence_item_ids' => $rows->pluck('dossier_evidence_item_id')->unique()->values()->all(),
                    'suppliers_without_declaration' => $silent,
                    'needs_justification' => $silent !== [],
                ];
            });
    }

    /**
     * Validated qualifications of the ingredient with no current document of the given types.
     *
     * @return list<int>
     */
    private function qualificationsWithout(Workspace $workspace, Ingredient $ingredient, EvidenceType ...$types): array
    {
        return SupplierQualification::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $ingredient->id)
            ->where('status', SupplierQualificationStatus::Valide->value)
            ->whereDoesntHave('evidenceItems', fn ($query) => $query
                ->whereNull('retired_at')
                ->whereIn('type', array_map(fn (EvidenceType $type): string => $type->value, $types)))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
}
```

- [ ] **Step 3: Run and commit** — Expected PASS (4). Commit: `Resolve worst-case supplier constituents with flagged platform fallback`.

---

### Task 5: Data panel and withdrawal on the raw material page

**Files:** modify `app/Livewire/Dossiers/RawMaterialDetail.php`, `resources/views/livewire/dossiers/raw-material-detail.blade.php`, `lang/en/dossiers.php`; tests appended to `tests/Feature/Dossiers/RawMaterialPagesTest.php`.

- [ ] **Step 1: Failing tests** (append; add imports `App\Enums\EvidenceType`, `App\Models\Allergen`):

```php
it('records an allergen declaration from the document data panel', function (): void {
    [$owner, $workspace] = rawMaterialWorkspace();
    $olive = ingredientUsedIn($workspace, 'Lavandin');
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $olive->id]);
    $item = DossierEvidenceItem::factory()->create(['supplier_qualification_id' => $qualification->id, 'type' => EvidenceType::AllergenDeclaration]);
    $linalool = Allergen::factory()->create(['inci_name' => 'Linalool']);
    $this->actingAs($owner);

    Livewire::test(RawMaterialDetail::class, ['ingredient' => $olive->public_id])
        ->call('startEditData', $item->id)
        ->fillForm(['constituents' => [['kind' => 'allergen', 'allergen_id' => $linalool->id, 'max_value' => '35']]], 'evidenceDataForm')
        ->call('saveEvidenceData')
        ->assertHasNoFormErrors([], 'evidenceDataForm')
        ->assertSee('Linalool');

    expect($item->constituents()->sole()->max_value)->toBe('35.000000');
});

it('withdraws a document from the page with a reason', function (): void {
    [$owner, $workspace] = rawMaterialWorkspace();
    $olive = ingredientUsedIn($workspace, 'Olive');
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $olive->id]);
    $item = DossierEvidenceItem::factory()->create(['supplier_qualification_id' => $qualification->id]);
    $this->actingAs($owner);

    Livewire::test(RawMaterialDetail::class, ['ingredient' => $olive->public_id])
        ->call('startWithdraw', $item->id)
        ->set('withdrawReason', 'Uploaded by mistake')
        ->call('withdrawEvidence')
        ->assertHasNoErrors();

    expect($item->refresh()->withdrawn_reason)->toBe('Uploaded by mistake');
});
```

- [ ] **Step 2: Component changes** — in `RawMaterialDetail`:

1. Add properties: `public ?int $dataItemId = null;`, `/** @var array<string, mixed> */ public array $evidenceDataState = [];`, `public ?int $withdrawItemId = null;`, `public string $withdrawReason = '';`.
2. Add the third form:

```php
    public function evidenceDataForm(Schema $schema): Schema
    {
        $item = $this->dataItemId !== null
            ? DossierEvidenceItem::query()->where('workspace_id', $this->workspaceId)->find($this->dataItemId)
            : null;
        $type = $item?->type;
        $kinds = $type?->constituentKinds() ?? [];
        $basis = $type?->useLimitBasis();
        $facts = $type?->factKeys() ?? [];

        return $schema
            ->components(array_values(array_filter([
                $kinds === [] ? null : Repeater::make('constituents')
                    ->label(__('dossiers.evidence_data.constituents'))
                    ->schema([
                        Select::make('kind')
                            ->label(__('dossiers.evidence_data.kind'))
                            ->options(collect($kinds)->mapWithKeys(fn (ConstituentKind $kind): array => [$kind->value => __('dossiers.evidence_data.kinds.'.$kind->value)])->all())
                            ->default($kinds[0]->value)
                            ->live()
                            ->required(),
                        Select::make('allergen_id')
                            ->label(__('dossiers.evidence_data.allergen'))
                            ->options(fn (): array => Allergen::query()->orderBy('inci_name')->pluck('inci_name', 'id')->all())
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('kind') === ConstituentKind::Allergen->value)
                            ->required(fn (Get $get): bool => $get('kind') === ConstituentKind::Allergen->value),
                        Select::make('substance_id')
                            ->label(__('dossiers.evidence_data.substance'))
                            ->options(fn (): array => Substance::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('kind') !== ConstituentKind::Allergen->value)
                            ->required(fn (Get $get): bool => $get('kind') !== ConstituentKind::Allergen->value),
                        TextInput::make('max_value')
                            ->label(__('dossiers.evidence_data.max_value'))
                            ->numeric()
                            ->suffix(fn (Get $get): string => ConstituentKind::tryFrom((string) $get('kind'))?->unit() === 'ppm' ? 'ppm' : '%')
                            ->required(),
                    ])
                    ->columns(['md' => 3])
                    ->defaultItems(0),
                $basis === null ? null : Repeater::make('use_limits')
                    ->label(__('dossiers.evidence_data.use_limits'))
                    ->schema([
                        $basis === 'ifra_category'
                            ? Select::make('ifra_product_category_id')
                                ->label(__('dossiers.evidence_data.ifra_category'))
                                ->options(fn (): array => IfraProductCategory::query()->orderBy('code')->get()->mapWithKeys(fn (IfraProductCategory $category): array => [$category->id => $category->code.' — '.$category->localizedName()])->all())
                                ->required()
                            : TextInput::make('product_class_label')->label(__('dossiers.evidence_data.product_class'))->required()->maxLength(255),
                        TextInput::make('max_percent')->label(__('dossiers.evidence_data.max_percent'))->numeric()->suffix('%')->required(),
                    ])
                    ->columns(['md' => 2])
                    ->defaultItems(0),
                in_array('peroxide_value_mmol_l', $facts, true)
                    ? TextInput::make('facts.peroxide_value_mmol_l')->label(__('dossiers.evidence_data.peroxide_value'))->numeric()->suffix('mmol/L')
                    : null,
                in_array('microbiology_specification', $facts, true)
                    ? Textarea::make('facts.microbiology_specification')->label(__('dossiers.evidence_data.microbiology'))->rows(2)
                    : null,
            ])))
            ->statePath('evidenceDataState');
    }
```

Check that `IfraProductCategory::localizedName()` exists (models rule says taxonomy rows provide it); otherwise use `name`.

3. Actions:

```php
    public function startEditData(int $itemId): void
    {
        $item = DossierEvidenceItem::query()->where('workspace_id', $this->workspaceId)->with(['constituents', 'useLimits'])->findOrFail($itemId);
        $this->dataItemId = $item->id;
        $this->evidenceDataForm->fill([
            'constituents' => $item->constituents->map(fn ($row): array => $row->only(['kind', 'allergen_id', 'substance_id', 'max_value']) + ['kind' => $row->kind->value])->all(),
            'use_limits' => $item->useLimits->map(fn ($row): array => $row->only(['ifra_product_category_id', 'product_class_label', 'max_percent']))->all(),
            'facts' => $item->facts ?? [],
        ]);
    }

    public function saveEvidenceData(SaveDossierEvidenceData $saveData): void
    {
        $state = $this->evidenceDataForm->getState();
        $item = DossierEvidenceItem::query()->where('workspace_id', $this->workspaceId)->findOrFail($this->dataItemId);

        $this->withFormErrors('evidenceDataState', fn () => $saveData->handle($this->user(), $this->lockedWorkspace(), $item, [
            'constituents' => array_values($state['constituents'] ?? []),
            'use_limits' => array_values($state['use_limits'] ?? []),
            'facts' => $state['facts'] ?? [],
        ]));

        $this->dataItemId = null;
        $this->status = __('dossiers.raw_materials.saved');
    }

    public function startWithdraw(int $itemId): void
    {
        $this->withdrawItemId = DossierEvidenceItem::query()->where('workspace_id', $this->workspaceId)->findOrFail($itemId)->id;
        $this->withdrawReason = '';
    }

    public function withdrawEvidence(WithdrawDossierEvidence $withdraw): void
    {
        $item = DossierEvidenceItem::query()->where('workspace_id', $this->workspaceId)->findOrFail($this->withdrawItemId);
        $withdraw->handle($this->user(), $this->lockedWorkspace(), $item, $this->withdrawReason);
        $this->reset('withdrawItemId', 'withdrawReason');
        $this->status = __('dossiers.raw_materials.withdrawn');
    }
```

Validation errors thrown by `WithdrawDossierEvidence` use the key `withdrawn_reason`; map it in the view as `@error('withdrawn_reason')`.

4. In `mount()`, add `$this->evidenceDataForm->fill();`. In `render()`, eager-load `'evidenceItems' => fn ($query) => $query->active()->with(['file', 'constituents.allergen', 'constituents.substance', 'useLimits.ifraProductCategory'])->latest()`.
5. Imports: `App\Actions\Dossiers\SaveDossierEvidenceData`, `App\Actions\Dossiers\WithdrawDossierEvidence`, `App\Enums\ConstituentKind`, `App\Models\Allergen`, `App\Models\IfraProductCategory`, `App\Models\Substance`, `Filament\Forms\Components\Repeater`, `Filament\Forms\Components\Textarea`, `Filament\Schemas\Components\Utilities\Get`.

- [ ] **Step 3: View** — in `raw-material-detail.blade.php`, replace the `<span class="flex gap-2">…</span>` button group of each document `<li>` with the block below, and add the panels right after the `<li>`'s first `<span>` (still inside the `<li>`, which becomes `flex-col` on narrow screens):

```blade
                                <span class="flex flex-wrap gap-2">
                                    <a href="{{ route('dossiers.evidence.download', $item) }}" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap"><x-action-icon name="download" />{{ __('dossiers.raw_materials.download') }}</a>
                                    @if ($item->type->constituentKinds() !== [] || $item->type->useLimitBasis() !== null || $item->type->factKeys() !== [])
                                        <button type="button" wire:click="startEditData({{ $item->id }})" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">{{ __('dossiers.evidence_data.edit') }}</button>
                                    @endif
                                    <button type="button" wire:click="startReplace({{ $item->id }})" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">{{ __('dossiers.raw_materials.replace') }}</button>
                                    <button type="button" wire:click="startWithdraw({{ $item->id }})" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">{{ __('dossiers.raw_materials.withdraw') }}</button>
                                </span>
                                <p class="w-full text-xs text-[var(--color-ink-soft)]">
                                    @forelse ($item->constituents as $constituent)
                                        {{ $constituent->allergen?->inci_name ?? $constituent->substance?->name }} ≤ {{ rtrim(rtrim($constituent->max_value, '0'), '.') }} {{ $constituent->unit === 'ppm' ? 'ppm' : '%' }}@if (! $loop->last) · @endif
                                    @empty
                                        @if ($item->useLimits->isEmpty() && blank($item->facts)) {{ __('dossiers.evidence_data.none') }} @endif
                                    @endforelse
                                    @foreach ($item->useLimits as $limit)
                                        · {{ $limit->ifraProductCategory?->code ?? $limit->product_class_label }} ≤ {{ rtrim(rtrim($limit->max_percent, '0'), '.') }} %
                                    @endforeach
                                </p>
                                @if ($dataItemId === $item->id)
                                    <form wire:submit="saveEvidenceData" class="w-full space-y-3 border-t border-[var(--color-line)] pt-3">
                                        {{ $this->evidenceDataForm }}
                                        <div class="flex justify-end">
                                            <button type="submit" class="sk-btn sk-btn-primary min-h-11 py-2.5">{{ __('dossiers.evidence_data.save') }}</button>
                                        </div>
                                    </form>
                                @endif
                                @if ($withdrawItemId === $item->id)
                                    <div class="w-full space-y-2 border-t border-[var(--color-line)] pt-3">
                                        <label class="block text-sm font-medium text-[var(--color-ink-strong)]">
                                            {{ __('dossiers.raw_materials.withdraw_reason') }}
                                            <textarea wire:model="withdrawReason" rows="2" aria-label="{{ __('dossiers.raw_materials.withdraw_reason') }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]"></textarea>
                                        </label>
                                        @error('withdrawn_reason')
                                            <p role="alert" class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                        @enderror
                                        <div class="flex justify-end">
                                            <button type="button" wire:click="withdrawEvidence" class="sk-btn sk-btn-outline min-h-11 py-2.5">{{ __('dossiers.raw_materials.withdraw_confirm') }}</button>
                                        </div>
                                    </div>
                                @endif
```

Change the `<li>` class to `flex flex-wrap items-center justify-between gap-3 py-2`.

- [ ] **Step 4: Strings** — add to `lang/en/dossiers.php`:

```php
    'evidence_data' => [
        'edit' => 'Data',
        'constituents' => 'Declared contents',
        'kind' => 'Kind',
        'kinds' => [
            'allergen' => 'Allergen',
            'impurity' => 'Impurity',
            'restricted_substance' => 'Restricted substance',
        ],
        'allergen' => 'Allergen',
        'substance' => 'Substance',
        'max_value' => 'Maximum',
        'use_limits' => 'Maximum use levels',
        'ifra_category' => 'IFRA category',
        'product_class' => 'Product class',
        'max_percent' => 'Maximum use',
        'peroxide_value' => 'Peroxide value',
        'microbiology' => 'Microbiology specification',
        'save' => 'Save data',
        'none' => 'No data recorded.',
    ],
```

and in `raw_materials`: `'withdraw' => 'Withdraw'`, `'withdraw_reason' => 'Why is this document withdrawn?'`, `'withdraw_confirm' => 'Withdraw document'`, `'withdrawn' => 'Document withdrawn.'`.

- [ ] **Step 5: Catalogue rows** — list missing keys with the tinker snippet used in phase 2a, add reviewed `de/es/fr/it/nl/pt_BR` rows (French: « Données », « Contenus déclarés », « Allergène », « Impureté », « Substance restreinte », « Teneur maximale », « Niveaux d’utilisation maximaux », « Catégorie IFRA », « Classe de produits », « Indice de peroxyde », « Spécification microbiologique », « Retirer », « Pourquoi ce document est-il retiré ? », « Document retiré. »), merge sorted, run `InterfaceTranslationCatalogueTest`.

- [ ] **Step 6: Run and commit** — `php artisan test --compact tests/Feature/Dossiers` — Expected PASS. Commit: `Edit document data and withdraw documents from the raw material page`.

---

### Task 6: Verification

- [ ] `php artisan test --compact tests/Feature/Dossiers tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/RecipeVersionPagesTest.php`
- [ ] Migration round trip on a throwaway SQLite file (`migrate`, `migrate:rollback --step=2`, `migrate`).
- [ ] `php artisan test --compact` (full suite), `vendor/bin/pint --format agent`, `graphify update .`.

### Task 7: Local check (owner present)

- [ ] With the owner's go-ahead: `php artisan migrate`, `php artisan translations:catalogue:import --mode=preserve-existing`.
- [ ] Withdraw the mistaken test record #2? It is already retired (replaced), so withdrawal is refused by design; leave it in the history.
- [ ] On Olive Oil: open « Données » on the technical sheet, record an impurity (create « Lead » as a substance in the admin first if needed) and the microbiology specification; confirm the summary line; confirm `SupplierConstituentResolver::impurities()` returns it via tinker.
- [ ] Upload one document by hand through the operating system's file dialog (the last unverified step from phase 2a).
