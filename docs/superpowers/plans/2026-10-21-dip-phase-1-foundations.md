# DIP Phase 1 — Foundations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lay the platform and workspace foundations of the DIP module: per-workspace activation, dossier settings, versioned toxicological profiles and assessment parameters, material assessment coverage, and reviewed EU annex legal entries — enough for an admin to enter everything the pilot soap needs, while a workspace without activation sees nothing.

**Architecture:** A new `WorkspaceModule::ProductDossiers` gates every workspace-facing piece through the existing `WorkspaceAuthorization` (Owner/Admin only, active grant required). Platform reference data lives in append-only versioned tables (a shared `HasAppendOnlyVersions` concern + `AppendOnlyVersionPublisher`) edited only through new Filament resources in a "Product dossiers" navigation group. Coverage and legal entries are small dedicated models with services that enforce the spec's rules; nothing in the workbench or existing compliance services changes in this phase.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4, Filament 5, Pest 4, SQLite (tests) / PostgreSQL (target).

**Spec:** `docs/superpowers/specs/2026-10-21-product-information-file-dip-design.md` (§1, §2, §8, §10 phase 1).

---

## Before you start

- Read `.ai/rules/index.md` and every rule file matching the paths you touch: `app.md`, `models.md`, `models-services.md`, `migrations.md`, `services.md`, `actions.md`, `actions-services.md`, `livewire.md`, `lang.md`, `data.md`, `tests.md`, `views.md`, `policies-views.md`.
- Run tests with a UTF-8 locale: `export LANG=en_US.UTF-8`.
- After any change under `app/Filament`, run `vendor/bin/filacheck --fix` and fix anything it reports.
- After PHP changes, run `vendor/bin/pint --dirty --format agent`.

## Scope decisions (made while planning, consistent with the spec)

1. `WorkspaceCapabilities::allowsProductDossiers()` takes a workspace **id** (`int`) so `WorkspaceAuthorization` can reuse it without loading the model.
2. **No numeric reference data is seeded.** The toilet-soap exposure preset, the assessment policy and QRA2 parameters are entered by the admin in Filament, each with its source. DIP.GA.007 used 1.00 g × 10 uses/day, R = 0.01; check that against the current SCCS Notes of Guidance table before entering it, and record the source you used.
3. **Supplier assessments as coverage evidence arrive in phase 2** (evidence items do not exist yet). In phase 1, whole-material coverage is supported by toxicological profile references only.
4. **Private-ingredient coverage has no workspace UI yet** (it arrives with the dossier screens in phase 4). The service already enforces workspace authorization for it and is tested.
5. The dossier settings tab reuses the plain-input markup of its host page (`settings-index`) so the Settings page stays consistent; it does not introduce Filament forms into that page.
6. New Filament create/view page helpers live in `app/Filament/Concerns/` (a sub-folder of the existing `app/Filament`, outside the discovered `Resources`/`Pages` folders).

## File structure

| Area | Files |
| --- | --- |
| Rule exception | Modify `.ai/rules/app.md` |
| Activation | `app/Enums/WorkspaceModule.php` (modify), `database/migrations/*_create_workspace_dossier_grants_table.php`, `app/Models/WorkspaceDossierGrant.php`, `database/factories/WorkspaceDossierGrantFactory.php`, `app/Models/Workspace.php` (modify), `app/Services/WorkspaceCapabilities.php` (modify), `app/Services/WorkspaceAuthorization.php` (modify), `tests/Feature/Dossiers/DossierModuleAccessTest.php` |
| Grant admin | `app/Filament/Resources/WorkspaceDossierGrants/**`, `tests/Feature/Filament/WorkspaceDossierGrantResourceTest.php` |
| Settings | `database/migrations/*_create_workspace_dossier_settings_table.php`, `app/Models/WorkspaceDossierSetting.php`, `database/factories/WorkspaceDossierSettingFactory.php`, `app/Services/Dossiers/DossierSettingsService.php`, `app/Actions/Dossiers/SaveDossierSettings.php`, `app/Livewire/Dashboard/DossierSettings.php`, `resources/views/livewire/dashboard/dossier-settings.blade.php`, `app/Livewire/Dashboard/SettingsIndex.php` (modify), `resources/views/livewire/dashboard/settings-index.blade.php` (modify), `lang/en/dossiers.php`, `config/interface-translations.php` (modify), `database/seeders/data/interface-translations.json` (modify), `tests/Feature/Dossiers/DossierSettingsTest.php` |
| Versioning | `app/Models/Concerns/HasAppendOnlyVersions.php`, `app/Services/Dossiers/AppendOnlyVersionPublisher.php`, `app/Filament/Concerns/PublishesAppendOnlyVersions.php`, `app/Filament/Concerns/OffersNewVersion.php` |
| Toxicology | `app/Enums/NoaelRoute.php`, migration, `app/Models/ToxicologyProfile.php`, factory, `app/Models/Substance.php` (modify), `app/Services/Dossiers/ToxicologyProfilePublisher.php`, `app/Filament/Resources/ToxicologyProfiles/**`, `tests/Feature/Dossiers/ToxicologyProfilePublisherTest.php`, `tests/Feature/Filament/ToxicologyProfileResourceTest.php` |
| Parameters | migrations, `app/Models/{AssessmentPolicy,ExposurePreset,Qra2Parameter}.php`, factories, `app/Filament/Resources/{AssessmentPolicies,ExposurePresets,Qra2Parameters}/**`, `tests/Feature/Dossiers/AssessmentParameterVersioningTest.php`, `tests/Feature/Filament/AssessmentParameterResourcesTest.php` |
| Coverage | `app/Enums/AssessmentReviewState.php`, migration, `app/Models/MaterialAssessmentCoverage.php`, factory, `app/Services/Dossiers/MaterialAssessmentCoverageService.php`, `app/Filament/Resources/MaterialAssessmentCoverages/**`, `tests/Feature/Dossiers/MaterialAssessmentCoverageTest.php`, `tests/Feature/Filament/MaterialAssessmentCoverageResourceTest.php` |
| Legal entries | `app/Enums/{LegalAnnex,LegalEntryProductScope,LegalEntryReviewStatus}.php`, migration, `app/Models/RegulatoryLegalEntry.php`, factory, `app/Services/Dossiers/RegulatoryLegalEntryReviewer.php`, `app/Filament/Resources/RegulatoryLegalEntries/**`, `tests/Feature/Dossiers/RegulatoryLegalEntryTest.php`, `tests/Feature/Filament/RegulatoryLegalEntryResourceTest.php` |

---

### Task 1: Record the DIP exception to the live-guidance rule

**Files:**
- Modify: `.ai/rules/app.md` (section "Treat compliance output as live guidance")

- [ ] **Step 1: Append the exception paragraph**

In `.ai/rules/app.md`, directly after the paragraph that ends with "…must not be presented or persisted as a compliance certification or toxicological assessment.", add:

```markdown

Exception — Product Information File (DIP) versions: a submitted or approved DIP version persists a frozen snapshot of computed Part A data and an unsigned Part B draft, because a signed DIP is a legal record. Koskalk still does not author the safety assessment: the assessment is the document a qualified safety assessor signs, and Koskalk only records which version was signed and stores that signed file. Everything outside the DIP module keeps the live-guidance rule. See docs/superpowers/specs/2026-10-21-product-information-file-dip-design.md §8.
```

- [ ] **Step 2: Commit**

```bash
git add .ai/rules/app.md
git commit -m "Document the DIP exception to the live compliance guidance rule"
```

---

### Task 2: Per-workspace activation of the DIP module

**Files:**
- Modify: `app/Enums/WorkspaceModule.php`
- Create: `database/migrations/<timestamp>_create_workspace_dossier_grants_table.php`
- Create: `app/Models/WorkspaceDossierGrant.php`
- Create: `database/factories/WorkspaceDossierGrantFactory.php`
- Modify: `app/Models/Workspace.php`
- Modify: `app/Services/WorkspaceCapabilities.php`
- Modify: `app/Services/WorkspaceAuthorization.php`
- Test: `tests/Feature/Dossiers/DossierModuleAccessTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Dossiers/DossierModuleAccessTest.php`:

```php
<?php

use App\Enums\WorkspaceMemberRole;
use App\Enums\WorkspaceModule;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Models\WorkspaceMember;
use App\Services\WorkspaceAuthorization;
use App\Services\WorkspaceCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace, 2: User}
 */
function dossierAccessWorkspaceWithMember(WorkspaceMemberRole $role): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($owner)->for($plan)->create();
    $member = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($member)->create(['role' => $role]);

    return [$owner, $workspace, $member];
}

it('hides the dossier module from a workspace without a grant', function (): void {
    [$owner, $workspace] = dossierAccessWorkspaceWithMember(WorkspaceMemberRole::Admin);
    $authorization = app(WorkspaceAuthorization::class);

    expect(app(WorkspaceCapabilities::class)->allowsProductDossiers($workspace->id))->toBeFalse()
        ->and($authorization->canViewModule($owner, $workspace->id, WorkspaceModule::ProductDossiers))->toBeFalse()
        ->and($authorization->canEditModule($owner, $workspace->id, WorkspaceModule::ProductDossiers))->toBeFalse();
});

it('opens the dossier module to the owner and admins of a granted workspace only', function (WorkspaceMemberRole $role, bool $allowed): void {
    [$owner, $workspace, $member] = dossierAccessWorkspaceWithMember($role);
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $authorization = app(WorkspaceAuthorization::class);

    expect(app(WorkspaceCapabilities::class)->allowsProductDossiers($workspace->id))->toBeTrue()
        ->and($authorization->canEditModule($owner, $workspace->id, WorkspaceModule::ProductDossiers))->toBeTrue()
        ->and($authorization->canViewModule($member, $workspace->id, WorkspaceModule::ProductDossiers))->toBe($allowed)
        ->and($authorization->canEditModule($member, $workspace->id, WorkspaceModule::ProductDossiers))->toBe($allowed);
})->with([
    'admin' => [WorkspaceMemberRole::Admin, true],
    'editor' => [WorkspaceMemberRole::Editor, false],
    'viewer' => [WorkspaceMemberRole::Viewer, false],
]);

it('closes the dossier module when the grant is revoked', function (): void {
    [$owner, $workspace] = dossierAccessWorkspaceWithMember(WorkspaceMemberRole::Admin);
    WorkspaceDossierGrant::factory()->for($workspace)->revoked()->create();

    expect(app(WorkspaceCapabilities::class)->allowsProductDossiers($workspace->id))->toBeFalse()
        ->and(app(WorkspaceAuthorization::class)->canEditModule($owner, $workspace->id, WorkspaceModule::ProductDossiers))->toBeFalse();
});

it('does not let a grant on one workspace open another', function (): void {
    [$owner, $workspace] = dossierAccessWorkspaceWithMember(WorkspaceMemberRole::Admin);
    WorkspaceDossierGrant::factory()->create();

    expect(app(WorkspaceAuthorization::class)->canEditModule($owner, $workspace->id, WorkspaceModule::ProductDossiers))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Dossiers/DossierModuleAccessTest.php`
Expected: FAIL — `Class "App\Models\WorkspaceDossierGrant" not found` / undefined constant `WorkspaceModule::ProductDossiers`.

- [ ] **Step 3: Add the module case**

Replace `app/Enums/WorkspaceModule.php` with:

```php
<?php

namespace App\Enums;

enum WorkspaceModule: string
{
    case Formulation = 'formulation';
    case ProductionBench = 'production_bench';
    case ProductDossiers = 'product_dossiers';
}
```

- [ ] **Step 4: Create the migration**

Run: `php artisan make:migration create_workspace_dossier_grants_table --no-interaction`

Replace the generated file's content with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_dossier_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_dossier_grants');
    }
};
```

- [ ] **Step 5: Create the model**

Run: `php artisan make:model WorkspaceDossierGrant --factory --no-interaction`

Replace `app/Models/WorkspaceDossierGrant.php` with:

```php
<?php

namespace App\Models;

use Database\Factories\WorkspaceDossierGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform-admin grant that turns the Product Information File (DIP) module on for one workspace.
 */
#[Fillable([
    'workspace_id',
    'granted_by_user_id',
    'granted_at',
    'revoked_at',
])]
class WorkspaceDossierGrant extends Model
{
    /** @use HasFactory<WorkspaceDossierGrantFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @param  Builder<WorkspaceDossierGrant>  $query
     * @return Builder<WorkspaceDossierGrant>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
```

Replace `database/factories/WorkspaceDossierGrantFactory.php` with:

```php
<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceDossierGrant>
 */
class WorkspaceDossierGrantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'granted_by_user_id' => null,
            'granted_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
```

- [ ] **Step 6: Add the workspace relation**

In `app/Models/Workspace.php`, next to `productionEntitlement()`, add:

```php
    public function dossierGrant(): HasOne
    {
        return $this->hasOne(WorkspaceDossierGrant::class);
    }
```

- [ ] **Step 7: Add the capability**

In `app/Services/WorkspaceCapabilities.php`, add the import `use App\Models\WorkspaceDossierGrant;` and this method after `allowsCollaboration()`:

```php
    public function allowsProductDossiers(int $workspaceId): bool
    {
        return WorkspaceDossierGrant::query()
            ->where('workspace_id', $workspaceId)
            ->active()
            ->exists();
    }
```

- [ ] **Step 8: Gate the module in `WorkspaceAuthorization`**

In `app/Services/WorkspaceAuthorization.php`, add a `ProductDossiers` arm to both `match` expressions. `canViewModule()` becomes:

```php
    public function canViewModule(User $user, int $workspaceId, WorkspaceModule $module): bool
    {
        return $this->canView($user, $workspaceId) && match ($module) {
            WorkspaceModule::Formulation => true,
            WorkspaceModule::ProductionBench => WorkspaceProductionEntitlement::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('status', [ProductionBenchEntitlementStatus::Active, ProductionBenchEntitlementStatus::Cancelled])
                ->exists(),
            WorkspaceModule::ProductDossiers => $this->canManage($user, $workspaceId)
                && $this->capabilities->allowsProductDossiers($workspaceId),
        };
    }
```

and `canEditModule()` becomes:

```php
    public function canEditModule(User $user, int $workspaceId, WorkspaceModule $module): bool
    {
        return $this->canEdit($user, $workspaceId) && match ($module) {
            WorkspaceModule::Formulation => true,
            WorkspaceModule::ProductionBench => WorkspaceProductionEntitlement::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', ProductionBenchEntitlementStatus::Active)
                ->exists(),
            WorkspaceModule::ProductDossiers => $this->canManage($user, $workspaceId)
                && $this->capabilities->allowsProductDossiers($workspaceId),
        };
    }
```

- [ ] **Step 9: Migrate and run the test**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/Dossiers/DossierModuleAccessTest.php`
Expected: PASS (6 tests).

- [ ] **Step 10: Run the existing authorization tests**

Run: `php artisan test --compact tests/Feature/WorkspaceCapabilitiesTest.php tests/Feature/SettingsSecurityTest.php`
Expected: PASS.

- [ ] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/WorkspaceModule.php app/Models/WorkspaceDossierGrant.php app/Models/Workspace.php app/Services/WorkspaceCapabilities.php app/Services/WorkspaceAuthorization.php database/migrations/*_create_workspace_dossier_grants_table.php database/factories/WorkspaceDossierGrantFactory.php tests/Feature/Dossiers/DossierModuleAccessTest.php
git commit -m "Gate the DIP module per workspace for owners and admins"
```

---

### Task 3: Admin resource to grant and revoke the module

**Files:**
- Create: `app/Filament/Resources/WorkspaceDossierGrants/WorkspaceDossierGrantResource.php`
- Create: `app/Filament/Resources/WorkspaceDossierGrants/Schemas/WorkspaceDossierGrantForm.php`
- Create: `app/Filament/Resources/WorkspaceDossierGrants/Tables/WorkspaceDossierGrantsTable.php`
- Create: `app/Filament/Resources/WorkspaceDossierGrants/Pages/ListWorkspaceDossierGrants.php`
- Create: `app/Filament/Resources/WorkspaceDossierGrants/Pages/CreateWorkspaceDossierGrant.php`
- Test: `tests/Feature/Filament/WorkspaceDossierGrantResourceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/WorkspaceDossierGrantResourceTest.php`:

```php
<?php

use App\Filament\Resources\WorkspaceDossierGrants\Pages\CreateWorkspaceDossierGrant;
use App\Filament\Resources\WorkspaceDossierGrants\Pages\ListWorkspaceDossierGrants;
use App\Filament\Resources\WorkspaceDossierGrants\WorkspaceDossierGrantResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lets an admin grant the dossier module to a workspace', function (): void {
    $admin = User::factory()->admin()->create();
    $workspace = Workspace::factory()->create();
    $this->actingAs($admin);

    Livewire::test(CreateWorkspaceDossierGrant::class)
        ->fillForm(['workspace_id' => $workspace->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $grant = WorkspaceDossierGrant::query()->sole();

    expect($grant->workspace_id)->toBe($workspace->id)
        ->and($grant->granted_by_user_id)->toBe($admin->id)
        ->and($grant->granted_at)->not->toBeNull()
        ->and($grant->isActive())->toBeTrue();
});

it('revokes and restores a grant from the list', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $grant = WorkspaceDossierGrant::factory()->create();

    Livewire::test(ListWorkspaceDossierGrants::class)
        ->callAction(TestAction::make('revoke')->table($grant));

    expect($grant->refresh()->isActive())->toBeFalse();

    Livewire::test(ListWorkspaceDossierGrants::class)
        ->callAction(TestAction::make('restore')->table($grant));

    expect($grant->refresh()->isActive())->toBeTrue();
});

it('keeps the grant resource out of reach of non-admin users', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(WorkspaceDossierGrantResource::getUrl(panel: 'admin'))->assertForbidden();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Filament/WorkspaceDossierGrantResourceTest.php`
Expected: FAIL — resource classes not found.

- [ ] **Step 3: Create the resource**

`app/Filament/Resources/WorkspaceDossierGrants/WorkspaceDossierGrantResource.php`:

```php
<?php

namespace App\Filament\Resources\WorkspaceDossierGrants;

use App\Filament\Resources\WorkspaceDossierGrants\Pages\CreateWorkspaceDossierGrant;
use App\Filament\Resources\WorkspaceDossierGrants\Pages\ListWorkspaceDossierGrants;
use App\Filament\Resources\WorkspaceDossierGrants\Schemas\WorkspaceDossierGrantForm;
use App\Filament\Resources\WorkspaceDossierGrants\Tables\WorkspaceDossierGrantsTable;
use App\Models\WorkspaceDossierGrant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WorkspaceDossierGrantResource extends Resource
{
    protected static ?string $model = WorkspaceDossierGrant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Key;

    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'DIP module grant';
    }

    public static function getPluralModelLabel(): string
    {
        return 'DIP module grants';
    }

    public static function form(Schema $schema): Schema
    {
        return WorkspaceDossierGrantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkspaceDossierGrantsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkspaceDossierGrants::route('/'),
            'create' => CreateWorkspaceDossierGrant::route('/create'),
        ];
    }
}
```

`app/Filament/Resources/WorkspaceDossierGrants/Schemas/WorkspaceDossierGrantForm.php`:

```php
<?php

namespace App\Filament\Resources\WorkspaceDossierGrants\Schemas;

use App\Models\WorkspaceDossierGrant;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class WorkspaceDossierGrantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Grant the DIP module')
                    ->description('Owners and Admins of this workspace will see the Product Information File module.')
                    ->icon(Heroicon::Key)
                    ->schema([
                        Select::make('workspace_id')
                            ->label('Workspace')
                            ->relationship(
                                name: 'workspace',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->withoutGlobalScopes(),
                            )
                            ->unique(table: WorkspaceDossierGrant::class, column: 'workspace_id')
                            ->searchable()
                            ->preload()
                            ->required(),
                    ]),
            ]);
    }
}
```

`app/Filament/Resources/WorkspaceDossierGrants/Tables/WorkspaceDossierGrantsTable.php`:

```php
<?php

namespace App\Filament\Resources\WorkspaceDossierGrants\Tables;

use App\Models\WorkspaceDossierGrant;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkspaceDossierGrantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['workspace', 'grantedBy']))
            ->columns([
                TextColumn::make('workspace.name')
                    ->label('Workspace')
                    ->searchable(),
                IconColumn::make('active')
                    ->label('Active')
                    ->state(fn (WorkspaceDossierGrant $record): bool => $record->isActive())
                    ->boolean(),
                TextColumn::make('grantedBy.email')
                    ->label('Granted by')
                    ->placeholder('—'),
                TextColumn::make('granted_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('revoked_at')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (WorkspaceDossierGrant $record): bool => $record->isActive())
                    ->action(fn (WorkspaceDossierGrant $record) => $record->update(['revoked_at' => now()])),
                Action::make('restore')
                    ->label('Restore')
                    ->visible(fn (WorkspaceDossierGrant $record): bool => ! $record->isActive())
                    ->action(fn (WorkspaceDossierGrant $record) => $record->update(['revoked_at' => null])),
            ])
            ->defaultSort('granted_at', 'desc')
            ->emptyStateHeading('No workspace has the DIP module yet');
    }
}
```

`app/Filament/Resources/WorkspaceDossierGrants/Pages/ListWorkspaceDossierGrants.php`:

```php
<?php

namespace App\Filament\Resources\WorkspaceDossierGrants\Pages;

use App\Filament\Resources\WorkspaceDossierGrants\WorkspaceDossierGrantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkspaceDossierGrants extends ListRecords
{
    protected static string $resource = WorkspaceDossierGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
```

`app/Filament/Resources/WorkspaceDossierGrants/Pages/CreateWorkspaceDossierGrant.php`:

```php
<?php

namespace App\Filament\Resources\WorkspaceDossierGrants\Pages;

use App\Filament\Resources\WorkspaceDossierGrants\WorkspaceDossierGrantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkspaceDossierGrant extends CreateRecord
{
    protected static string $resource = WorkspaceDossierGrantResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'granted_by_user_id' => auth()->id(),
            'granted_at' => now(),
            'revoked_at' => null,
        ];
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact tests/Feature/Filament/WorkspaceDossierGrantResourceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Filacheck, format, commit**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
git add app/Filament/Resources/WorkspaceDossierGrants tests/Feature/Filament/WorkspaceDossierGrantResourceTest.php
git commit -m "Add admin resource to grant the DIP module per workspace"
```

---

### Task 4: Dossier settings model, service and action

**Files:**
- Create: `database/migrations/<timestamp>_create_workspace_dossier_settings_table.php`
- Create: `app/Models/WorkspaceDossierSetting.php`
- Create: `database/factories/WorkspaceDossierSettingFactory.php`
- Create: `app/Services/Dossiers/DossierSettingsService.php`
- Create: `app/Actions/Dossiers/SaveDossierSettings.php`
- Test: `tests/Feature/Dossiers/DossierSettingsTest.php` (service/action part)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Dossiers/DossierSettingsTest.php`:

```php
<?php

use App\Actions\Dossiers\SaveDossierSettings;
use App\Enums\WorkspaceMemberRole;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Models\WorkspaceDossierSetting;
use App\Models\WorkspaceMember;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array<string, string|null>
 */
function dossierSettingsPayload(): array
{
    return [
        'responsible_person_name' => 'La Grande Vague SAS',
        'responsible_person_address' => "17 rue des Tilleuls\n26120 Montélier\nFrance",
        'responsible_person_siret' => '12345678901234',
        'manufacturer_name' => null,
        'manufacturer_address' => null,
        'packer_name' => null,
        'packer_address' => null,
        'numbering_prefix' => 'GA',
        'assessor_name' => 'Jane Assessor',
        'assessor_address' => 'Nantes, France',
        'assessor_qualification' => 'PharmD, toxicology',
    ];
}

it('saves and updates the dossier settings of a granted workspace for its owner', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();

    app(SaveDossierSettings::class)->handle($owner, $workspace, dossierSettingsPayload());
    app(SaveDossierSettings::class)->handle($owner, $workspace, [...dossierSettingsPayload(), 'numbering_prefix' => 'GB']);

    $setting = WorkspaceDossierSetting::query()->sole();

    expect($setting->workspace_id)->toBe($workspace->id)
        ->and($setting->numbering_prefix)->toBe('GB')
        ->and($setting->responsible_person_siret)->toBe('12345678901234');
});

it('refuses to save settings without the module grant', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    app(SaveDossierSettings::class)->handle($owner, $workspace, dossierSettingsPayload());
})->throws(AuthorizationException::class);

it('refuses to save settings for an editor of a granted workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    UserEntitlement::factory()->for($owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $editor = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);

    app(SaveDossierSettings::class)->handle($editor, $workspace, dossierSettingsPayload());
})->throws(AuthorizationException::class);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Dossiers/DossierSettingsTest.php`
Expected: FAIL — `Class "App\Actions\Dossiers\SaveDossierSettings" not found`.

- [ ] **Step 3: Migration**

Run: `php artisan make:migration create_workspace_dossier_settings_table --no-interaction`, then replace its content with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_dossier_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('responsible_person_name');
            $table->text('responsible_person_address');
            $table->string('responsible_person_siret', 14)->nullable();
            $table->string('manufacturer_name')->nullable();
            $table->text('manufacturer_address')->nullable();
            $table->string('packer_name')->nullable();
            $table->text('packer_address')->nullable();
            $table->string('numbering_prefix', 8);
            $table->string('assessor_name')->nullable();
            $table->text('assessor_address')->nullable();
            $table->text('assessor_qualification')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_dossier_settings');
    }
};
```

- [ ] **Step 4: Model and factory**

Run: `php artisan make:model WorkspaceDossierSetting --factory --no-interaction`

`app/Models/WorkspaceDossierSetting.php`:

```php
<?php

namespace App\Models;

use Database\Factories\WorkspaceDossierSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Responsible Person, sites, DIP numbering and default assessor of one workspace.
 */
#[Fillable([
    'workspace_id',
    'responsible_person_name',
    'responsible_person_address',
    'responsible_person_siret',
    'manufacturer_name',
    'manufacturer_address',
    'packer_name',
    'packer_address',
    'numbering_prefix',
    'assessor_name',
    'assessor_address',
    'assessor_qualification',
])]
class WorkspaceDossierSetting extends Model
{
    /** @use HasFactory<WorkspaceDossierSettingFactory> */
    use HasFactory;

    public const EDITABLE = [
        'responsible_person_name',
        'responsible_person_address',
        'responsible_person_siret',
        'manufacturer_name',
        'manufacturer_address',
        'packer_name',
        'packer_address',
        'numbering_prefix',
        'assessor_name',
        'assessor_address',
        'assessor_qualification',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }
}
```

`database/factories/WorkspaceDossierSettingFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceDossierSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceDossierSetting>
 */
class WorkspaceDossierSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'responsible_person_name' => fake()->company(),
            'responsible_person_address' => fake()->address(),
            'responsible_person_siret' => fake()->numerify('##############'),
            'manufacturer_name' => null,
            'manufacturer_address' => null,
            'packer_name' => null,
            'packer_address' => null,
            'numbering_prefix' => 'GA',
            'assessor_name' => null,
            'assessor_address' => null,
            'assessor_qualification' => null,
        ];
    }
}
```

- [ ] **Step 5: Service**

`app/Services/Dossiers/DossierSettingsService.php`:

```php
<?php

namespace App\Services\Dossiers;

use App\Models\Workspace;
use App\Models\WorkspaceDossierSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DossierSettingsService
{
    public function forWorkspace(Workspace $workspace): ?WorkspaceDossierSetting
    {
        return WorkspaceDossierSetting::query()->where('workspace_id', $workspace->id)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Workspace $workspace, array $data): WorkspaceDossierSetting
    {
        return DB::transaction(function () use ($workspace, $data): WorkspaceDossierSetting {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            return WorkspaceDossierSetting::query()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                Arr::only($data, WorkspaceDossierSetting::EDITABLE),
            );
        }, attempts: 5);
    }
}
```

- [ ] **Step 6: Action**

`app/Actions/Dossiers/SaveDossierSettings.php`:

```php
<?php

namespace App\Actions\Dossiers;

use App\Enums\WorkspaceModule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierSetting;
use App\Services\Dossiers\DossierSettingsService;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;

class SaveDossierSettings
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly DossierSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Already validated input.
     */
    public function handle(User $actor, Workspace $workspace, array $data): WorkspaceDossierSetting
    {
        if (! $this->authorization->canEditModule($actor, $workspace->id, WorkspaceModule::ProductDossiers)) {
            throw new AuthorizationException;
        }

        return $this->settings->save($workspace, $data);
    }
}
```

- [ ] **Step 7: Migrate and run the test**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/Dossiers/DossierSettingsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/WorkspaceDossierSetting.php app/Services/Dossiers/DossierSettingsService.php app/Actions/Dossiers/SaveDossierSettings.php database/migrations/*_create_workspace_dossier_settings_table.php database/factories/WorkspaceDossierSettingFactory.php tests/Feature/Dossiers/DossierSettingsTest.php
git commit -m "Store per-workspace DIP settings behind the module gate"
```

---

### Task 5: Dossier settings tab in Settings

**Files:**
- Create: `lang/en/dossiers.php`
- Modify: `config/interface-translations.php`
- Modify: `database/seeders/data/interface-translations.json`
- Create: `app/Livewire/Dashboard/DossierSettings.php`
- Create: `resources/views/livewire/dashboard/dossier-settings.blade.php`
- Modify: `app/Livewire/Dashboard/SettingsIndex.php`
- Modify: `resources/views/livewire/dashboard/settings-index.blade.php`
- Test: `tests/Feature/Dossiers/DossierSettingsTest.php` (append)

- [ ] **Step 1: Append the failing tests**

Append to `tests/Feature/Dossiers/DossierSettingsTest.php` (add the imports `use App\Livewire\Dashboard\DossierSettings;`, `use App\Livewire\Dashboard\SettingsIndex;` and `use Livewire\Livewire;` at the top):

```php
it('saves dossier settings from the settings tab', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $this->actingAs($owner);

    Livewire::test(DossierSettings::class)
        ->set('responsible_person_name', 'La Grande Vague SAS')
        ->set('responsible_person_address', 'Montélier, France')
        ->set('responsible_person_siret', '12345678901234')
        ->set('numbering_prefix', 'GA')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee(__('dossiers.settings.saved'));

    expect(WorkspaceDossierSetting::query()->sole()->numbering_prefix)->toBe('GA');
});

it('validates the SIRET and numbering prefix', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $this->actingAs($owner);

    Livewire::test(DossierSettings::class)
        ->set('responsible_person_name', 'La Grande Vague SAS')
        ->set('responsible_person_address', 'Montélier, France')
        ->set('responsible_person_siret', '1234')
        ->set('numbering_prefix', 'ga-1')
        ->call('save')
        ->assertHasErrors(['responsible_person_siret', 'numbering_prefix']);

    expect(WorkspaceDossierSetting::query()->count())->toBe(0);
});

it('refuses to mount the settings tab without the module grant', function (): void {
    $owner = User::factory()->create();
    Workspace::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test(DossierSettings::class)->assertForbidden();
});

it('shows the dossier tab in settings only when the module is available', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test(SettingsIndex::class)->assertDontSee(__('dossiers.settings.tab'));

    WorkspaceDossierGrant::factory()->for($workspace)->create();

    Livewire::test(SettingsIndex::class)->assertSee(__('dossiers.settings.tab'));
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Dossiers/DossierSettingsTest.php`
Expected: FAIL — `DossierSettings` class not found.

- [ ] **Step 3: English strings**

Create `lang/en/dossiers.php`:

```php
<?php

return [
    'settings' => [
        'tab' => 'Product dossiers (DIP)',
        'heading' => 'Product information file settings',
        'description' => 'Used on every DIP this workspace produces. The Responsible Person is the company placing the products on the EU market.',
        'responsible_person' => 'Responsible Person',
        'responsible_person_name' => 'Legal name',
        'responsible_person_address' => 'Address',
        'responsible_person_siret' => 'SIRET',
        'manufacturer' => 'Manufacturing site',
        'packer' => 'Packing site',
        'site_name' => 'Name',
        'site_address' => 'Address',
        'site_help' => 'Leave empty when it is the Responsible Person.',
        'numbering_prefix' => 'DIP numbering prefix',
        'numbering_help' => 'Dossiers are numbered DIP.<prefix>.<product reference>, for example DIP.GA.007.',
        'assessor' => 'Safety assessor',
        'assessor_name' => 'Name',
        'assessor_address' => 'Address',
        'assessor_qualification' => 'Qualification',
        'save' => 'Save dossier settings',
        'saved' => 'Dossier settings saved.',
    ],
    'validation' => [
        'siret' => 'The SIRET must be 14 digits.',
        'numbering_prefix' => 'Use 1 to 8 capital letters or digits.',
    ],
];
```

- [ ] **Step 4: Register the group**

In `config/interface-translations.php`, add `'dossiers' => ['*'],` to `sources` directly after `'dashboard' => ['*'],`.

- [ ] **Step 5: Add the six-locale catalogue rows**

Save this as `/tmp/dossiers-translations.json` (outside the repo):

```json
[
  {"group": "dossiers", "key": "settings.assessor", "text": {"de": "Sicherheitsbewerter", "es": "Evaluador de seguridad", "fr": "Évaluateur de la sécurité", "it": "Valutatore della sicurezza", "nl": "Veiligheidsbeoordelaar", "pt_BR": "Avaliador de segurança"}},
  {"group": "dossiers", "key": "settings.assessor_address", "text": {"de": "Adresse", "es": "Dirección", "fr": "Adresse", "it": "Indirizzo", "nl": "Adres", "pt_BR": "Endereço"}},
  {"group": "dossiers", "key": "settings.assessor_name", "text": {"de": "Name", "es": "Nombre", "fr": "Nom", "it": "Nome", "nl": "Naam", "pt_BR": "Nome"}},
  {"group": "dossiers", "key": "settings.assessor_qualification", "text": {"de": "Qualifikation", "es": "Cualificación", "fr": "Qualification", "it": "Qualifica", "nl": "Kwalificatie", "pt_BR": "Qualificação"}},
  {"group": "dossiers", "key": "settings.description", "text": {"de": "Wird in jeder PIF dieses Arbeitsbereichs verwendet. Die verantwortliche Person ist das Unternehmen, das die Produkte auf dem EU-Markt in Verkehr bringt.", "es": "Se usa en cada PIF de este espacio de trabajo. La persona responsable es la empresa que comercializa los productos en el mercado de la UE.", "fr": "Utilisés dans chaque DIP de cet espace de travail. La personne responsable est l’entreprise qui met les produits sur le marché de l’UE.", "it": "Usati in ogni PIF di questo spazio di lavoro. La persona responsabile è l’azienda che immette i prodotti sul mercato dell’UE.", "nl": "Gebruikt in elk PIF van deze werkruimte. De verantwoordelijke persoon is het bedrijf dat de producten in de EU in de handel brengt.", "pt_BR": "Usadas em cada PIF deste espaço de trabalho. A pessoa responsável é a empresa que coloca os produtos no mercado da UE."}},
  {"group": "dossiers", "key": "settings.heading", "text": {"de": "Einstellungen der Produktinformationsdatei", "es": "Ajustes del expediente de información del producto", "fr": "Paramètres du dossier d’information produit", "it": "Impostazioni del fascicolo informativo sul prodotto", "nl": "Instellingen productinformatiedossier", "pt_BR": "Configurações do dossiê de informações do produto"}},
  {"group": "dossiers", "key": "settings.manufacturer", "text": {"de": "Herstellungsort", "es": "Lugar de fabricación", "fr": "Site de fabrication", "it": "Sito di produzione", "nl": "Productielocatie", "pt_BR": "Local de fabricação"}},
  {"group": "dossiers", "key": "settings.numbering_help", "text": {"de": "Dossiers werden als DIP.<Präfix>.<Produktreferenz> nummeriert, zum Beispiel DIP.GA.007.", "es": "Los expedientes se numeran DIP.<prefijo>.<referencia del producto>, por ejemplo DIP.GA.007.", "fr": "Les dossiers sont numérotés DIP.<préfixe>.<référence produit>, par exemple DIP.GA.007.", "it": "I fascicoli sono numerati DIP.<prefisso>.<riferimento prodotto>, ad esempio DIP.GA.007.", "nl": "Dossiers worden genummerd als DIP.<prefix>.<productreferentie>, bijvoorbeeld DIP.GA.007.", "pt_BR": "Os dossiês são numerados DIP.<prefixo>.<referência do produto>, por exemplo DIP.GA.007."}},
  {"group": "dossiers", "key": "settings.numbering_prefix", "text": {"de": "DIP-Nummernpräfix", "es": "Prefijo de numeración DIP", "fr": "Préfixe de numérotation DIP", "it": "Prefisso di numerazione DIP", "nl": "DIP-nummerprefix", "pt_BR": "Prefixo de numeração DIP"}},
  {"group": "dossiers", "key": "settings.packer", "text": {"de": "Abpackort", "es": "Lugar de envasado", "fr": "Site de conditionnement", "it": "Sito di confezionamento", "nl": "Verpakkingslocatie", "pt_BR": "Local de envase"}},
  {"group": "dossiers", "key": "settings.responsible_person", "text": {"de": "Verantwortliche Person", "es": "Persona responsable", "fr": "Personne responsable", "it": "Persona responsabile", "nl": "Verantwoordelijke persoon", "pt_BR": "Pessoa responsável"}},
  {"group": "dossiers", "key": "settings.responsible_person_address", "text": {"de": "Adresse", "es": "Dirección", "fr": "Adresse", "it": "Indirizzo", "nl": "Adres", "pt_BR": "Endereço"}},
  {"group": "dossiers", "key": "settings.responsible_person_name", "text": {"de": "Firmenname", "es": "Razón social", "fr": "Raison sociale", "it": "Ragione sociale", "nl": "Bedrijfsnaam", "pt_BR": "Razão social"}},
  {"group": "dossiers", "key": "settings.responsible_person_siret", "text": {"de": "SIRET", "es": "SIRET", "fr": "SIRET", "it": "SIRET", "nl": "SIRET", "pt_BR": "SIRET"}},
  {"group": "dossiers", "key": "settings.save", "text": {"de": "Dossier-Einstellungen speichern", "es": "Guardar ajustes de expedientes", "fr": "Enregistrer les paramètres des dossiers", "it": "Salva impostazioni dei fascicoli", "nl": "Dossierinstellingen opslaan", "pt_BR": "Salvar configurações dos dossiês"}},
  {"group": "dossiers", "key": "settings.saved", "text": {"de": "Dossier-Einstellungen gespeichert.", "es": "Ajustes de expedientes guardados.", "fr": "Paramètres des dossiers enregistrés.", "it": "Impostazioni dei fascicoli salvate.", "nl": "Dossierinstellingen opgeslagen.", "pt_BR": "Configurações dos dossiês salvas."}},
  {"group": "dossiers", "key": "settings.site_address", "text": {"de": "Adresse", "es": "Dirección", "fr": "Adresse", "it": "Indirizzo", "nl": "Adres", "pt_BR": "Endereço"}},
  {"group": "dossiers", "key": "settings.site_help", "text": {"de": "Leer lassen, wenn es die verantwortliche Person ist.", "es": "Déjelo vacío si es la persona responsable.", "fr": "Laisser vide s’il s’agit de la personne responsable.", "it": "Lasciare vuoto se coincide con la persona responsabile.", "nl": "Leeg laten als dit de verantwoordelijke persoon is.", "pt_BR": "Deixe vazio quando for a pessoa responsável."}},
  {"group": "dossiers", "key": "settings.site_name", "text": {"de": "Name", "es": "Nombre", "fr": "Nom", "it": "Nome", "nl": "Naam", "pt_BR": "Nome"}},
  {"group": "dossiers", "key": "settings.tab", "text": {"de": "Produktdossiers (PIF)", "es": "Expedientes de producto (PIF)", "fr": "Dossiers produit (DIP)", "it": "Fascicoli prodotto (PIF)", "nl": "Productdossiers (PIF)", "pt_BR": "Dossiês de produto (PIF)"}},
  {"group": "dossiers", "key": "validation.numbering_prefix", "text": {"de": "Verwenden Sie 1 bis 8 Großbuchstaben oder Ziffern.", "es": "Use de 1 a 8 letras mayúsculas o cifras.", "fr": "Utilisez de 1 à 8 lettres majuscules ou chiffres.", "it": "Usa da 1 a 8 lettere maiuscole o cifre.", "nl": "Gebruik 1 tot 8 hoofdletters of cijfers.", "pt_BR": "Use de 1 a 8 letras maiúsculas ou dígitos."}},
  {"group": "dossiers", "key": "validation.siret", "text": {"de": "Die SIRET muss aus 14 Ziffern bestehen.", "es": "El SIRET debe tener 14 cifras.", "fr": "Le SIRET doit comporter 14 chiffres.", "it": "Il SIRET deve avere 14 cifre.", "nl": "Het SIRET-nummer moet uit 14 cijfers bestaan.", "pt_BR": "O SIRET deve ter 14 dígitos."}}
]
```

Merge it into the catalogue, keeping the file sorted by group then key, 2-space indented, with a trailing newline:

```bash
python3 -I - database/seeders/data/interface-translations.json /tmp/dossiers-translations.json <<'EOF'
import json, sys
catalogue_path, rows_path = sys.argv[1], sys.argv[2]
catalogue = json.load(open(catalogue_path, encoding="utf-8"))
existing = {(r["group"], r["key"]) for r in catalogue["translations"]}
new_rows = [r for r in json.load(open(rows_path, encoding="utf-8")) if (r["group"], r["key"]) not in existing]
catalogue["translations"] = sorted(catalogue["translations"] + new_rows, key=lambda r: (r["group"], r["key"]))
with open(catalogue_path, "w", encoding="utf-8") as handle:
    json.dump(catalogue, handle, ensure_ascii=False, indent=2)
    handle.write("\n")
print(f"added {len(new_rows)} rows")
EOF
git diff --stat database/seeders/data/interface-translations.json
```

Expected: `added 22 rows`, and the diff shows only insertions.

- [ ] **Step 6: Livewire component**

`app/Livewire/Dashboard/DossierSettings.php`:

```php
<?php

namespace App\Livewire\Dashboard;

use App\Actions\Dossiers\SaveDossierSettings;
use App\Enums\WorkspaceModule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierSetting;
use App\Services\Dossiers\DossierSettingsService;
use App\Services\WorkspaceAuthorization;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DossierSettings extends Component
{
    #[Locked]
    public int $workspaceId;

    public string $responsible_person_name = '';

    public string $responsible_person_address = '';

    public ?string $responsible_person_siret = null;

    public ?string $manufacturer_name = null;

    public ?string $manufacturer_address = null;

    public ?string $packer_name = null;

    public ?string $packer_address = null;

    public string $numbering_prefix = '';

    public ?string $assessor_name = null;

    public ?string $assessor_address = null;

    public ?string $assessor_qualification = null;

    public string $status = '';

    public function mount(DossierSettingsService $settings): void
    {
        $workspace = $this->workspace();
        $this->workspaceId = $workspace->id;

        $existing = $settings->forWorkspace($workspace);

        if ($existing instanceof WorkspaceDossierSetting) {
            $this->fill($existing->only(WorkspaceDossierSetting::EDITABLE));
        }
    }

    public function save(SaveDossierSettings $saveDossierSettings): void
    {
        $validated = $this->validate([
            'responsible_person_name' => ['required', 'string', 'max:255'],
            'responsible_person_address' => ['required', 'string', 'max:1000'],
            'responsible_person_siret' => ['nullable', 'regex:/^\d{14}$/'],
            'manufacturer_name' => ['nullable', 'string', 'max:255'],
            'manufacturer_address' => ['nullable', 'string', 'max:1000'],
            'packer_name' => ['nullable', 'string', 'max:255'],
            'packer_address' => ['nullable', 'string', 'max:1000'],
            'numbering_prefix' => ['required', 'regex:/^[A-Z0-9]{1,8}$/'],
            'assessor_name' => ['nullable', 'string', 'max:255'],
            'assessor_address' => ['nullable', 'string', 'max:1000'],
            'assessor_qualification' => ['nullable', 'string', 'max:2000'],
        ], [
            'responsible_person_siret.regex' => __('dossiers.validation.siret'),
            'numbering_prefix.regex' => __('dossiers.validation.numbering_prefix'),
        ]);

        $workspace = Workspace::withoutGlobalScopes()->findOrFail($this->workspaceId);
        $saveDossierSettings->handle($this->user(), $workspace, $validated);

        $this->status = __('dossiers.settings.saved');
    }

    public function render(): View
    {
        return view('livewire.dashboard.dossier-settings');
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
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

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'responsible_person_name' => __('dossiers.settings.responsible_person_name'),
            'responsible_person_address' => __('dossiers.settings.responsible_person_address'),
            'responsible_person_siret' => __('dossiers.settings.responsible_person_siret'),
            'numbering_prefix' => __('dossiers.settings.numbering_prefix'),
        ];
    }
}
```

- [ ] **Step 7: View**

`resources/views/livewire/dashboard/dossier-settings.blade.php`:

```blade
<section class="sk-card space-y-5 p-5" aria-labelledby="dossier-settings-heading">
    <div>
        <h3 id="dossier-settings-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('dossiers.settings.heading') }}</h3>
        <p class="mt-1 text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('dossiers.settings.description') }}</p>
    </div>

    @if ($status)
        <p role="status" class="rounded-lg bg-[var(--color-success-soft)] px-4 py-3 text-sm text-[var(--color-success-strong)]">{{ $status }}</p>
    @endif

    <form wire:submit="save" class="space-y-5">
        <fieldset class="sk-inset space-y-3 p-4">
            <legend class="sk-eyebrow">{{ __('dossiers.settings.responsible_person') }}</legend>
            @foreach ([
                'responsible_person_name' => 'text',
                'responsible_person_address' => 'textarea',
                'responsible_person_siret' => 'text',
            ] as $field => $type)
                <label class="block">
                    <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('dossiers.settings.'.$field) }}</span>
                    @if ($type === 'textarea')
                        <textarea wire:model="{{ $field }}" rows="3" aria-label="{{ __('dossiers.settings.'.$field) }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]"></textarea>
                    @else
                        <input wire:model="{{ $field }}" type="text" aria-label="{{ __('dossiers.settings.'.$field) }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]">
                    @endif
                    @error($field)
                        <p role="alert" class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                    @enderror
                </label>
            @endforeach
        </fieldset>

        @foreach (['manufacturer', 'packer'] as $site)
            <fieldset class="sk-inset space-y-3 p-4">
                <legend class="sk-eyebrow">{{ __('dossiers.settings.'.$site) }}</legend>
                <p class="text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('dossiers.settings.site_help') }}</p>
                <label class="block">
                    <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('dossiers.settings.site_name') }}</span>
                    <input wire:model="{{ $site }}_name" type="text" aria-label="{{ __('dossiers.settings.'.$site) }} — {{ __('dossiers.settings.site_name') }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('dossiers.settings.site_address') }}</span>
                    <textarea wire:model="{{ $site }}_address" rows="3" aria-label="{{ __('dossiers.settings.'.$site) }} — {{ __('dossiers.settings.site_address') }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]"></textarea>
                </label>
            </fieldset>
        @endforeach

        <fieldset class="sk-inset space-y-3 p-4">
            <legend class="sk-eyebrow">{{ __('dossiers.settings.numbering_prefix') }}</legend>
            <input wire:model="numbering_prefix" type="text" aria-label="{{ __('dossiers.settings.numbering_prefix') }}" class="w-40 rounded-lg bg-[var(--color-field)] px-3 py-2.5 font-mono text-sm uppercase text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]">
            <p class="text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('dossiers.settings.numbering_help') }}</p>
            @error('numbering_prefix')
                <p role="alert" class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
            @enderror
        </fieldset>

        <fieldset class="sk-inset space-y-3 p-4">
            <legend class="sk-eyebrow">{{ __('dossiers.settings.assessor') }}</legend>
            @foreach (['assessor_name' => 'text', 'assessor_address' => 'textarea', 'assessor_qualification' => 'textarea'] as $field => $type)
                <label class="block">
                    <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('dossiers.settings.'.$field) }}</span>
                    @if ($type === 'textarea')
                        <textarea wire:model="{{ $field }}" rows="3" aria-label="{{ __('dossiers.settings.'.$field) }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]"></textarea>
                    @else
                        <input wire:model="{{ $field }}" type="text" aria-label="{{ __('dossiers.settings.'.$field) }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]">
                    @endif
                </label>
            @endforeach
        </fieldset>

        <div class="flex justify-end">
            <button type="submit" class="sk-btn sk-btn-primary min-h-11 py-2.5">{{ __('dossiers.settings.save') }}</button>
        </div>
    </form>
</section>
```

The save button uses the same `sk-btn sk-btn-primary` classes as the Settings page's own save buttons.

- [ ] **Step 8: Add the tab to Settings**

In `app/Livewire/Dashboard/SettingsIndex.php`, add `use App\Enums\WorkspaceModule;` and, in `render()`, compute the flag directly after the `if (config('workspaces.collaboration_enabled', false) …) { … }` block (so `$user` is already assigned):

```php
        $canManageDossiers = $user instanceof User
            && $this->workspaceId !== null
            && app(WorkspaceAuthorization::class)->canEditModule($user->fresh(), $this->workspaceId, WorkspaceModule::ProductDossiers);
```

and pass `'canManageDossiers' => $canManageDossiers,` in the view data.

In `resources/views/livewire/dashboard/settings-index.blade.php`, after the `@if ($canManageMembers)` tab button block, add:

```blade
        @if ($canManageDossiers)
            <button
                type="button"
                wire:click="$set('activeTab', 'dossiers')"
                @class([
                    '-mb-px rounded-t-lg border-b-2 px-4 py-2.5 text-sm font-medium transition',
                    'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]' => $activeTab === 'dossiers',
                    'border-transparent text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]' => $activeTab !== 'dossiers',
                ])
            >{{ __('dossiers.settings.tab') }}</button>
        @endif
```

and, after the `@if ($activeTab === 'members' && $canManageMembers)` block, add:

```blade
    @if ($activeTab === 'dossiers' && $canManageDossiers)
        <livewire:dashboard.dossier-settings />
    @endif
```

- [ ] **Step 9: Run the tests**

Run: `php artisan test --compact tests/Feature/Dossiers/DossierSettingsTest.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/SettingsLocalizationTest.php tests/Feature/SettingsSecurityTest.php`
Expected: PASS.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add lang/en/dossiers.php config/interface-translations.php database/seeders/data/interface-translations.json app/Livewire/Dashboard/DossierSettings.php resources/views/livewire/dashboard/dossier-settings.blade.php app/Livewire/Dashboard/SettingsIndex.php resources/views/livewire/dashboard/settings-index.blade.php tests/Feature/Dossiers/DossierSettingsTest.php
git commit -m "Add the DIP settings tab for granted workspaces"
```

---

### Task 6: Append-only versioning foundation

**Files:**
- Create: `app/Models/Concerns/HasAppendOnlyVersions.php`
- Create: `app/Services/Dossiers/AppendOnlyVersionPublisher.php`
- Create: `app/Filament/Concerns/PublishesAppendOnlyVersions.php`
- Create: `app/Filament/Concerns/OffersNewVersion.php`

These are exercised by the tests of Tasks 7–8; no test file of their own.

- [ ] **Step 1: The model concern**

`app/Models/Concerns/HasAppendOnlyVersions.php`:

```php
<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Versioned reference data: rows are never edited or deleted, only superseded by a newer version.
 * Tables need `version`, `is_current`, `superseded_at` and `published_by_user_id` columns.
 */
trait HasAppendOnlyVersions
{
    /**
     * Columns that identify one versioned series (empty for a single global series).
     *
     * @return list<string>
     */
    abstract public static function versionScopeColumns(): array;

    protected static function bootHasAppendOnlyVersions(): void
    {
        static::updating(function (Model $model): void {
            $changed = array_diff(array_keys($model->getDirty()), ['is_current', 'superseded_at', 'updated_at']);

            if ($changed !== []) {
                throw new LogicException(class_basename($model).' versions are append-only; publish a new version instead.');
            }
        });

        static::deleting(function (Model $model): void {
            throw new LogicException(class_basename($model).' versions cannot be deleted.');
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }
}
```

- [ ] **Step 2: The publisher**

`app/Services/Dossiers/AppendOnlyVersionPublisher.php`:

```php
<?php

namespace App\Services\Dossiers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AppendOnlyVersionPublisher
{
    /**
     * Publish a new current version in the series identified by the model's scope columns.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function publish(string $modelClass, array $attributes, ?User $publisher): Model
    {
        $scope = collect($modelClass::versionScopeColumns())
            ->mapWithKeys(fn (string $column): array => [$column => $attributes[$column] ?? null])
            ->all();

        foreach ($scope as $column => $value) {
            if ($value === null) {
                throw new InvalidArgumentException("Missing version scope column [{$column}].");
            }
        }

        return DB::transaction(function () use ($modelClass, $attributes, $scope, $publisher): Model {
            $existing = $modelClass::query()->where($scope)->lockForUpdate()->get();

            $existing
                ->filter(fn (Model $version): bool => (bool) $version->getAttribute('is_current'))
                ->each(fn (Model $version) => $version->update(['is_current' => false, 'superseded_at' => now()]));

            return $modelClass::query()->create([
                ...$attributes,
                ...$scope,
                'version' => ((int) $existing->max('version')) + 1,
                'is_current' => true,
                'superseded_at' => null,
                'published_by_user_id' => $publisher?->id,
            ]);
        }, attempts: 5);
    }
}
```

- [ ] **Step 3: Filament create-page trait**

`app/Filament/Concerns/PublishesAppendOnlyVersions.php`:

```php
<?php

namespace App\Filament\Concerns;

use App\Services\Dossiers\AppendOnlyVersionPublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Livewire\Attributes\Url;

/**
 * For CreateRecord pages of append-only versioned resources: creating publishes a new version,
 * and `?from=<id>` pre-fills the form from an existing version.
 */
trait PublishesAppendOnlyVersions
{
    #[Url]
    public ?int $from = null;

    protected function afterFill(): void
    {
        if ($this->from === null) {
            return;
        }

        $modelClass = static::getResource()::getModel();
        $this->form->fill($this->versionFormData($modelClass::query()->findOrFail($this->from)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function versionFormData(Model $source): array
    {
        return Arr::except($source->attributesToArray(), [
            'id', 'version', 'is_current', 'superseded_at', 'published_by_user_id', 'change_note', 'created_at', 'updated_at',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(AppendOnlyVersionPublisher::class)->publish(static::getResource()::getModel(), $data, auth()->user());
    }
}
```

- [ ] **Step 4: Filament view-page trait**

`app/Filament/Concerns/OffersNewVersion.php`:

```php
<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;

/**
 * For ViewRecord pages of append-only versioned resources.
 */
trait OffersNewVersion
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('newVersion')
                ->label('Publish new version')
                ->url(fn (): string => static::getResource()::getUrl('create', ['from' => $this->getRecord()->getKey()])),
        ];
    }
}
```

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Concerns/HasAppendOnlyVersions.php app/Services/Dossiers/AppendOnlyVersionPublisher.php app/Filament/Concerns
git commit -m "Add append-only versioning for DIP reference data"
```

---

### Task 7: Versioned toxicological profiles

**Files:**
- Create: `app/Enums/NoaelRoute.php`
- Create: `database/migrations/<timestamp>_create_toxicology_profiles_table.php`
- Create: `app/Models/ToxicologyProfile.php`, `database/factories/ToxicologyProfileFactory.php`
- Modify: `app/Models/Substance.php`
- Create: `app/Services/Dossiers/ToxicologyProfilePublisher.php`
- Create: `app/Filament/Resources/ToxicologyProfiles/{ToxicologyProfileResource.php, Schemas/ToxicologyProfileForm.php, Tables/ToxicologyProfilesTable.php, Pages/ListToxicologyProfiles.php, Pages/CreateToxicologyProfile.php, Pages/ViewToxicologyProfile.php}`
- Test: `tests/Feature/Dossiers/ToxicologyProfilePublisherTest.php`, `tests/Feature/Filament/ToxicologyProfileResourceTest.php`

- [ ] **Step 1: Write the failing service tests**

`tests/Feature/Dossiers/ToxicologyProfilePublisherTest.php`:

```php
<?php

use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use App\Services\Dossiers\ToxicologyProfilePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function linaloolProfileData(): array
{
    return [
        'noael_mg_kg_bw_day' => '117',
        'noael_route' => 'oral',
        'noael_species' => 'rat',
        'dermal_absorption_percent' => '14.4',
        'adi_mg_kg_bw_day' => '0.5',
        'adi_reasoning' => 'JECFA group ADI applies to the substance as used.',
        'nesil_ug_cm2' => '15000',
        'references' => [
            ['number' => 1, 'citation' => 'OECD SIDS Linalool, 2002', 'url' => null],
            ['number' => 2, 'citation' => 'IFRA QRA2 restricted standards', 'url' => null],
            ['number' => 3, 'citation' => 'JECFA 84th meeting, 2017', 'url' => null],
        ],
        'value_references' => [
            'noael_mg_kg_bw_day' => '1',
            'dermal_absorption_percent' => '1',
            'adi_mg_kg_bw_day' => '3',
            'nesil_ug_cm2' => '2',
        ],
    ];
}

it('publishes successive versions and keeps only the latest current', function (): void {
    $substance = Substance::factory()->create(['name' => 'Linalool']);
    $admin = User::factory()->admin()->create();
    $publisher = app(ToxicologyProfilePublisher::class);

    $first = $publisher->publish($substance, linaloolProfileData(), $admin);
    $second = $publisher->publish($substance, [...linaloolProfileData(), 'nesil_ug_cm2' => '14000'], $admin);

    expect($first->refresh()->is_current)->toBeFalse()
        ->and($first->superseded_at)->not->toBeNull()
        ->and($second->version)->toBe(2)
        ->and($second->is_current)->toBeTrue()
        ->and($second->published_by_user_id)->toBe($admin->id)
        ->and($second->value_references['adi_mg_kg_bw_day'])->toBe([3])
        ->and($substance->currentToxicologyProfile()->first()?->is($second))->toBeTrue();
});

it('refuses a value without a source reference', function (): void {
    $data = linaloolProfileData();
    unset($data['value_references']['nesil_ug_cm2']);

    app(ToxicologyProfilePublisher::class)->publish(Substance::factory()->create(), $data, User::factory()->admin()->create());
})->throws(ValidationException::class);

it('refuses a value pointing at an unknown reference number', function (): void {
    $data = linaloolProfileData();
    $data['value_references']['nesil_ug_cm2'] = '9';

    app(ToxicologyProfilePublisher::class)->publish(Substance::factory()->create(), $data, User::factory()->admin()->create());
})->throws(ValidationException::class);

it('refuses an ADI or TTC without applicability reasoning', function (string $field, array $extra): void {
    $data = [...linaloolProfileData(), ...$extra];
    $data[$field] = null;

    app(ToxicologyProfilePublisher::class)->publish(Substance::factory()->create(), $data, User::factory()->admin()->create());
})->with([
    'adi' => ['adi_reasoning', []],
    'ttc' => ['ttc_reasoning', ['ttc_mg_kg_bw_day' => '0.0025', 'ttc_class' => 'Cramer III', 'value_references' => [...linaloolProfileData()['value_references'], 'ttc_mg_kg_bw_day' => '1']]],
])->throws(ValidationException::class);

it('never edits or deletes a published version', function (): void {
    $profile = app(ToxicologyProfilePublisher::class)
        ->publish(Substance::factory()->create(), linaloolProfileData(), User::factory()->admin()->create());

    expect(fn () => $profile->update(['nesil_ug_cm2' => '1']))->toThrow(LogicException::class)
        ->and(fn () => $profile->delete())->toThrow(LogicException::class);

    expect(ToxicologyProfile::query()->sole()->nesil_ug_cm2)->toBe('15000.000000');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Dossiers/ToxicologyProfilePublisherTest.php`
Expected: FAIL — `ToxicologyProfilePublisher` not found.

- [ ] **Step 3: Enum**

`app/Enums/NoaelRoute.php`:

```php
<?php

namespace App\Enums;

enum NoaelRoute: string
{
    case Oral = 'oral';
    case Dermal = 'dermal';
    case Inhalation = 'inhalation';
}
```

- [ ] **Step 4: Migration**

Run: `php artisan make:migration create_toxicology_profiles_table --no-interaction`, then:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toxicology_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('substance_id');
            $table->foreign('substance_id')->references('id')->on('substance_catalog')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('is_current')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->decimal('noael_mg_kg_bw_day', 20, 9)->nullable();
            $table->string('noael_route', 16)->nullable();
            $table->string('noael_species', 64)->nullable();
            $table->text('noael_study')->nullable();
            $table->decimal('oral_bioavailability_percent', 8, 4)->nullable();
            $table->decimal('ld50_mg_kg', 20, 9)->nullable();
            $table->string('ld50_route', 16)->nullable();
            $table->decimal('dermal_absorption_percent', 8, 4)->nullable();
            $table->decimal('cmax_percent', 12, 6)->nullable();
            $table->string('cmax_origin')->nullable();
            $table->decimal('adi_mg_kg_bw_day', 20, 9)->nullable();
            $table->text('adi_reasoning')->nullable();
            $table->string('ttc_class', 32)->nullable();
            $table->decimal('ttc_mg_kg_bw_day', 20, 9)->nullable();
            $table->text('ttc_reasoning')->nullable();
            $table->decimal('nesil_ug_cm2', 20, 6)->nullable();
            $table->text('skin_irritation_note')->nullable();
            $table->text('eye_irritation_note')->nullable();
            $table->text('sensitisation_note')->nullable();
            $table->text('phototoxicity_note')->nullable();
            $table->text('cmr_note')->nullable();
            $table->text('clp_classification')->nullable();
            $table->json('references');
            $table->json('value_references');
            $table->text('change_note')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['substance_id', 'version']);
            $table->index(['substance_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toxicology_profiles');
    }
};
```

- [ ] **Step 5: Model, factory and Substance relations**

Run: `php artisan make:model ToxicologyProfile --factory --no-interaction`

`app/Models/ToxicologyProfile.php`:

```php
<?php

namespace App\Models;

use App\Enums\NoaelRoute;
use App\Models\Concerns\HasAppendOnlyVersions;
use Database\Factories\ToxicologyProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of the toxicological data of an assessed material (a substance_catalog row).
 */
#[Fillable([
    'substance_id',
    'version',
    'is_current',
    'superseded_at',
    'noael_mg_kg_bw_day', 'noael_route', 'noael_species', 'noael_study',
    'oral_bioavailability_percent', 'ld50_mg_kg', 'ld50_route',
    'dermal_absorption_percent', 'cmax_percent', 'cmax_origin',
    'adi_mg_kg_bw_day', 'adi_reasoning', 'ttc_class', 'ttc_mg_kg_bw_day', 'ttc_reasoning',
    'nesil_ug_cm2',
    'skin_irritation_note', 'eye_irritation_note', 'sensitisation_note', 'phototoxicity_note', 'cmr_note', 'clp_classification',
    'references',
    'value_references',
    'change_note',
    'published_by_user_id',
])]
class ToxicologyProfile extends Model
{
    /** @use HasFactory<ToxicologyProfileFactory> */
    use HasFactory;

    use HasAppendOnlyVersions;

    /** Data columns copied between versions. */
    public const VALUE_COLUMNS = [
        'noael_mg_kg_bw_day', 'noael_route', 'noael_species', 'noael_study',
        'oral_bioavailability_percent', 'ld50_mg_kg', 'ld50_route',
        'dermal_absorption_percent', 'cmax_percent', 'cmax_origin',
        'adi_mg_kg_bw_day', 'adi_reasoning', 'ttc_class', 'ttc_mg_kg_bw_day', 'ttc_reasoning',
        'nesil_ug_cm2',
        'skin_irritation_note', 'eye_irritation_note', 'sensitisation_note', 'phototoxicity_note', 'cmr_note', 'clp_classification',
    ];

    /** Numeric values that must each cite at least one numbered reference. */
    public const REFERENCED_VALUES = [
        'noael_mg_kg_bw_day', 'oral_bioavailability_percent', 'ld50_mg_kg', 'dermal_absorption_percent',
        'cmax_percent', 'adi_mg_kg_bw_day', 'ttc_mg_kg_bw_day', 'nesil_ug_cm2',
    ];

    public static function versionScopeColumns(): array
    {
        return ['substance_id'];
    }

    public function substance(): BelongsTo
    {
        return $this->belongsTo(Substance::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'is_current' => 'bool',
            'superseded_at' => 'datetime',
            'noael_route' => NoaelRoute::class,
            'noael_mg_kg_bw_day' => 'decimal:9',
            'oral_bioavailability_percent' => 'decimal:4',
            'ld50_mg_kg' => 'decimal:9',
            'dermal_absorption_percent' => 'decimal:4',
            'cmax_percent' => 'decimal:6',
            'adi_mg_kg_bw_day' => 'decimal:9',
            'ttc_mg_kg_bw_day' => 'decimal:9',
            'nesil_ug_cm2' => 'decimal:6',
            'references' => 'array',
            'value_references' => 'array',
        ];
    }
}
```

`database/factories/ToxicologyProfileFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Substance;
use App\Models\ToxicologyProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ToxicologyProfile>
 */
class ToxicologyProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'substance_id' => Substance::factory(),
            'version' => 1,
            'is_current' => true,
            'superseded_at' => null,
            'noael_mg_kg_bw_day' => '100',
            'noael_route' => 'oral',
            'dermal_absorption_percent' => null,
            'references' => [['number' => 1, 'citation' => 'Factory reference', 'url' => null]],
            'value_references' => ['noael_mg_kg_bw_day' => [1]],
        ];
    }
}
```

In `app/Models/Substance.php`, add `use Illuminate\Database\Eloquent\Relations\HasOne;` and:

```php
    public function toxicologyProfiles(): HasMany
    {
        return $this->hasMany(ToxicologyProfile::class);
    }

    public function currentToxicologyProfile(): HasOne
    {
        return $this->hasOne(ToxicologyProfile::class)->where('is_current', true);
    }
```

- [ ] **Step 6: Publisher**

`app/Services/Dossiers/ToxicologyProfilePublisher.php`:

```php
<?php

namespace App\Services\Dossiers;

use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ToxicologyProfilePublisher
{
    public function __construct(private readonly AppendOnlyVersionPublisher $versions) {}

    /**
     * @param  array<string, mixed>  $data  Value columns, `references` (list of number/citation/url) and
     *                                      `value_references` (value column => "1, 2" or list of numbers).
     */
    public function publish(Substance $substance, array $data, User $publisher): ToxicologyProfile
    {
        $references = $this->references($data['references'] ?? []);
        $valueReferences = $this->valueReferences($data['value_references'] ?? []);
        $values = collect(Arr::only($data, ToxicologyProfile::VALUE_COLUMNS))
            ->map(fn (mixed $value): mixed => $value === '' ? null : $value);

        $this->assertSourced($values, $references, $valueReferences);
        $this->assertReasoned($values);

        /** @var ToxicologyProfile */
        return $this->versions->publish(ToxicologyProfile::class, [
            ...$values->all(),
            'substance_id' => $substance->id,
            'references' => $references->values()->all(),
            'value_references' => $valueReferences->all(),
            'change_note' => $data['change_note'] ?? null,
        ], $publisher);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, array{number: int, citation: string, url: string|null}>
     */
    private function references(array $rows): Collection
    {
        $references = collect($rows)->map(fn (array $row): array => [
            'number' => (int) ($row['number'] ?? 0),
            'citation' => trim((string) ($row['citation'] ?? '')),
            'url' => filled($row['url'] ?? null) ? (string) $row['url'] : null,
        ]);

        if ($references->contains(fn (array $row): bool => $row['number'] < 1 || $row['citation'] === '')
            || $references->pluck('number')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'references' => 'Each reference needs a unique number of 1 or more and a citation.',
            ]);
        }

        return $references->keyBy('number');
    }

    /**
     * @param  array<string, mixed>  $map
     * @return Collection<string, list<int>>
     */
    private function valueReferences(array $map): Collection
    {
        $unknown = array_diff(array_keys($map), ToxicologyProfile::REFERENCED_VALUES);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'value_references' => 'Unknown value fields: '.implode(', ', $unknown).'.',
            ]);
        }

        return collect($map)->map(fn (mixed $numbers): array => collect(is_array($numbers) ? $numbers : explode(',', (string) $numbers))
            ->map(fn (mixed $number): string => trim((string) $number))
            ->filter(fn (string $number): bool => $number !== '')
            ->map(fn (string $number): int => (int) $number)
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @param  Collection<string, mixed>  $values
     * @param  Collection<int, array{number: int, citation: string, url: string|null}>  $references
     * @param  Collection<string, list<int>>  $valueReferences
     */
    private function assertSourced(Collection $values, Collection $references, Collection $valueReferences): void
    {
        $errors = collect(ToxicologyProfile::REFERENCED_VALUES)
            ->filter(fn (string $field): bool => $values->get($field) !== null)
            ->mapWithKeys(function (string $field) use ($references, $valueReferences): array {
                $cited = $valueReferences->get($field, []);

                if ($cited === []) {
                    return ["value_references.{$field}" => "Cite at least one reference for {$field}."];
                }

                $missing = array_diff($cited, $references->keys()->all());

                return $missing === []
                    ? []
                    : ["value_references.{$field}" => 'Unknown reference numbers: '.implode(', ', $missing).'.'];
            });

        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->all());
        }
    }

    /**
     * @param  Collection<string, mixed>  $values
     */
    private function assertReasoned(Collection $values): void
    {
        $errors = [];

        if ($values->get('noael_mg_kg_bw_day') !== null && blank($values->get('noael_route'))) {
            $errors['noael_route'] = 'A NOAEL needs its route.';
        }

        if ($values->get('adi_mg_kg_bw_day') !== null && blank($values->get('adi_reasoning'))) {
            $errors['adi_reasoning'] = 'Explain why the ADI applies to this use.';
        }

        if ($values->get('ttc_mg_kg_bw_day') !== null
            && (blank($values->get('ttc_reasoning')) || blank($values->get('ttc_class')))) {
            $errors['ttc_reasoning'] = 'A TTC needs its class and the reason it applies.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
```

- [ ] **Step 7: Migrate and run the service tests**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/Dossiers/ToxicologyProfilePublisherTest.php`
Expected: PASS (6 tests).

- [ ] **Step 8: Write the failing Filament test**

`tests/Feature/Filament/ToxicologyProfileResourceTest.php`:

```php
<?php

use App\Filament\Resources\ToxicologyProfiles\Pages\CreateToxicologyProfile;
use App\Filament\Resources\ToxicologyProfiles\ToxicologyProfileResource;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use App\Services\Dossiers\ToxicologyProfilePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('publishes a profile version from the admin create page', function (): void {
    $admin = User::factory()->admin()->create();
    $substance = Substance::factory()->create(['name' => 'Glycerin']);
    $this->actingAs($admin);

    Livewire::test(CreateToxicologyProfile::class)
        ->fillForm([
            'substance_id' => $substance->id,
            'noael_mg_kg_bw_day' => '1180',
            'noael_route' => 'oral',
            'dermal_absorption_percent' => '7.34',
            'references' => [['number' => 1, 'citation' => 'OECD SIDS Glycerol', 'url' => null]],
            'value_references' => ['noael_mg_kg_bw_day' => '1', 'dermal_absorption_percent' => '1'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $profile = ToxicologyProfile::query()->sole();

    expect($profile->version)->toBe(1)
        ->and($profile->published_by_user_id)->toBe($admin->id)
        ->and($profile->value_references['dermal_absorption_percent'])->toBe([1]);
});

it('pre-fills a new version from an existing one', function (): void {
    $admin = User::factory()->admin()->create();
    $profile = app(ToxicologyProfilePublisher::class)->publish(Substance::factory()->create(), [
        'noael_mg_kg_bw_day' => '150',
        'noael_route' => 'oral',
        'references' => [['number' => 1, 'citation' => 'Source A', 'url' => null]],
        'value_references' => ['noael_mg_kg_bw_day' => '1'],
    ], $admin);
    $this->actingAs($admin);

    Livewire::withQueryParams(['from' => $profile->id])
        ->test(CreateToxicologyProfile::class)
        ->assertFormSet([
            'substance_id' => $profile->substance_id,
            'noael_mg_kg_bw_day' => '150.000000000',
            'value_references' => ['noael_mg_kg_bw_day' => '1'],
        ]);
});

it('keeps toxicology profiles out of reach of non-admin users', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(ToxicologyProfileResource::getUrl(panel: 'admin'))->assertForbidden();
});
```

- [ ] **Step 9: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Filament/ToxicologyProfileResourceTest.php`
Expected: FAIL — resource classes not found.

- [ ] **Step 10: Resource**

`app/Filament/Resources/ToxicologyProfiles/ToxicologyProfileResource.php`:

```php
<?php

namespace App\Filament\Resources\ToxicologyProfiles;

use App\Filament\Resources\ToxicologyProfiles\Pages\CreateToxicologyProfile;
use App\Filament\Resources\ToxicologyProfiles\Pages\ListToxicologyProfiles;
use App\Filament\Resources\ToxicologyProfiles\Pages\ViewToxicologyProfile;
use App\Filament\Resources\ToxicologyProfiles\Schemas\ToxicologyProfileForm;
use App\Filament\Resources\ToxicologyProfiles\Tables\ToxicologyProfilesTable;
use App\Models\ToxicologyProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ToxicologyProfileResource extends Resource
{
    protected static ?string $model = ToxicologyProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Beaker;

    protected static ?int $navigationSort = 61;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'toxicological profile';
    }

    public static function getPluralModelLabel(): string
    {
        return 'toxicological profiles';
    }

    public static function form(Schema $schema): Schema
    {
        return ToxicologyProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ToxicologyProfilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListToxicologyProfiles::route('/'),
            'create' => CreateToxicologyProfile::route('/create'),
            'view' => ViewToxicologyProfile::route('/{record}'),
        ];
    }
}
```

`app/Filament/Resources/ToxicologyProfiles/Schemas/ToxicologyProfileForm.php`:

```php
<?php

namespace App\Filament\Resources\ToxicologyProfiles\Schemas;

use App\Enums\NoaelRoute;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ToxicologyProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        $routes = collect(NoaelRoute::cases())->mapWithKeys(fn (NoaelRoute $route): array => [$route->value => ucfirst($route->value)])->all();

        return $schema
            ->components([
                Section::make('Assessed material')
                    ->description('Profiles are append-only: saving publishes a new version and supersedes the current one.')
                    ->icon(Heroicon::Beaker)
                    ->schema([
                        Select::make('substance_id')
                            ->label('Substance / assessed material')
                            ->relationship(name: 'substance', titleAttribute: 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Textarea::make('change_note')
                            ->label('What changed in this version')
                            ->rows(2),
                    ]),
                Section::make('Systemic toxicity')
                    ->schema([
                        TextInput::make('noael_mg_kg_bw_day')->label('NOAEL (mg/kg bw/day)')->numeric(),
                        Select::make('noael_route')->label('NOAEL route')->options($routes),
                        TextInput::make('noael_species')->label('Species')->maxLength(64),
                        Textarea::make('noael_study')->label('Study')->rows(2)->columnSpanFull(),
                        TextInput::make('oral_bioavailability_percent')->label('Oral bioavailability (%)')->helperText('Leave empty to use the assessment policy default.')->numeric(),
                        TextInput::make('ld50_mg_kg')->label('LD50 (mg/kg)')->numeric(),
                        Select::make('ld50_route')->label('LD50 route')->options($routes),
                    ])
                    ->columns(['md' => 2]),
                Section::make('Absorption and thresholds')
                    ->schema([
                        TextInput::make('dermal_absorption_percent')->label('Dermal absorption DAp (%)')->helperText('Leave empty to use the assessment policy default.')->numeric(),
                        TextInput::make('cmax_percent')->label('Cmax (%)')->numeric(),
                        TextInput::make('cmax_origin')->label('Cmax origin (SCCS, CIR, supplier…)')->maxLength(255),
                        TextInput::make('adi_mg_kg_bw_day')->label('ADI (mg/kg bw/day)')->numeric(),
                        Textarea::make('adi_reasoning')->label('Why the ADI applies to this use')->rows(2)->columnSpanFull(),
                        TextInput::make('ttc_class')->label('TTC class')->maxLength(32),
                        TextInput::make('ttc_mg_kg_bw_day')->label('TTC (mg/kg bw/day)')->numeric(),
                        Textarea::make('ttc_reasoning')->label('Why the TTC applies')->rows(2)->columnSpanFull(),
                        TextInput::make('nesil_ug_cm2')->label('WoE NESIL (µg/cm²)')->numeric(),
                    ])
                    ->columns(['md' => 2]),
                Section::make('Hazard notes')
                    ->schema([
                        Textarea::make('skin_irritation_note')->label('Skin irritation')->rows(2),
                        Textarea::make('eye_irritation_note')->label('Eye irritation')->rows(2),
                        Textarea::make('sensitisation_note')->label('Skin sensitisation')->rows(2),
                        Textarea::make('phototoxicity_note')->label('Phototoxicity')->rows(2),
                        Textarea::make('cmr_note')->label('CMR')->rows(2),
                        Textarea::make('clp_classification')->label('CLP classification')->rows(2),
                    ])
                    ->columns(['md' => 2]),
                Section::make('Sources')
                    ->description('Every numeric value must cite at least one numbered reference.')
                    ->icon(Heroicon::DocumentText)
                    ->schema([
                        Repeater::make('references')
                            ->schema([
                                TextInput::make('number')->numeric()->minValue(1)->required(),
                                TextInput::make('citation')->required()->maxLength(1000)->columnSpan(2),
                                TextInput::make('url')->url()->maxLength(2048),
                            ])
                            ->columns(4)
                            ->defaultItems(1)
                            ->required(),
                        KeyValue::make('value_references')
                            ->label('Value sources')
                            ->keyLabel('Value field (e.g. noael_mg_kg_bw_day)')
                            ->valueLabel('Reference numbers (comma separated)'),
                    ]),
            ]);
    }
}
```

`app/Filament/Resources/ToxicologyProfiles/Tables/ToxicologyProfilesTable.php`:

```php
<?php

namespace App\Filament\Resources\ToxicologyProfiles\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ToxicologyProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('substance'))
            ->columns([
                TextColumn::make('substance.name')->label('Material')->searchable()->sortable(),
                TextColumn::make('version')->sortable(),
                IconColumn::make('is_current')->label('Current')->boolean(),
                TextColumn::make('noael_mg_kg_bw_day')->label('NOAEL')->placeholder('—'),
                TextColumn::make('dermal_absorption_percent')->label('DAp %')->placeholder('—'),
                TextColumn::make('nesil_ug_cm2')->label('NESIL')->placeholder('—'),
                TextColumn::make('created_at')->label('Published')->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_current')->label('Current versions')->default(true),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No toxicological profiles yet');
    }
}
```

`app/Filament/Resources/ToxicologyProfiles/Pages/ListToxicologyProfiles.php`:

```php
<?php

namespace App\Filament\Resources\ToxicologyProfiles\Pages;

use App\Filament\Resources\ToxicologyProfiles\ToxicologyProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListToxicologyProfiles extends ListRecords
{
    protected static string $resource = ToxicologyProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
```

`app/Filament/Resources/ToxicologyProfiles/Pages/CreateToxicologyProfile.php`:

```php
<?php

namespace App\Filament\Resources\ToxicologyProfiles\Pages;

use App\Filament\Concerns\PublishesAppendOnlyVersions;
use App\Filament\Resources\ToxicologyProfiles\ToxicologyProfileResource;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Services\Dossiers\ToxicologyProfilePublisher;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateToxicologyProfile extends CreateRecord
{
    use PublishesAppendOnlyVersions;

    protected static string $resource = ToxicologyProfileResource::class;

    /**
     * @return array<string, mixed>
     */
    protected function versionFormData(Model $source): array
    {
        /** @var ToxicologyProfile $source */
        return [
            ...$source->only(['substance_id', ...ToxicologyProfile::VALUE_COLUMNS]),
            'noael_route' => $source->noael_route?->value,
            'references' => $source->references,
            'value_references' => collect($source->value_references)
                ->map(fn (array $numbers): string => implode(', ', $numbers))
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(ToxicologyProfilePublisher::class)->publish(
            Substance::query()->findOrFail($data['substance_id']),
            $data,
            auth()->user(),
        );
    }
}
```

`app/Filament/Resources/ToxicologyProfiles/Pages/ViewToxicologyProfile.php`:

```php
<?php

namespace App\Filament\Resources\ToxicologyProfiles\Pages;

use App\Filament\Concerns\OffersNewVersion;
use App\Filament\Resources\ToxicologyProfiles\ToxicologyProfileResource;
use Filament\Resources\Pages\ViewRecord;

class ViewToxicologyProfile extends ViewRecord
{
    use OffersNewVersion;

    protected static string $resource = ToxicologyProfileResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            ...$data,
            'value_references' => collect($data['value_references'] ?? [])
                ->map(fn (array $numbers): string => implode(', ', $numbers))
                ->all(),
        ];
    }
}
```

- [ ] **Step 11: Run all toxicology tests**

Run: `php artisan test --compact tests/Feature/Dossiers/ToxicologyProfilePublisherTest.php tests/Feature/Filament/ToxicologyProfileResourceTest.php`
Expected: PASS (9 tests). If `assertFormSet` reports the decimal as `'150'`, change the expected value to match the cast output you see (`'150.000000000'` is the `decimal:9` cast) — do not change the cast.

- [ ] **Step 12: Filacheck, format, commit**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
git add app/Enums/NoaelRoute.php app/Models/ToxicologyProfile.php app/Models/Substance.php app/Services/Dossiers/ToxicologyProfilePublisher.php app/Filament/Resources/ToxicologyProfiles database/migrations/*_create_toxicology_profiles_table.php database/factories/ToxicologyProfileFactory.php tests/Feature/Dossiers/ToxicologyProfilePublisherTest.php tests/Feature/Filament/ToxicologyProfileResourceTest.php
git commit -m "Add versioned, sourced toxicological profiles"
```

---

### Task 8: Versioned assessment parameters (policy, exposure presets, QRA2)

**Files:**
- Create: `database/migrations/<timestamp>_create_assessment_parameter_tables.php`
- Create: `app/Models/{AssessmentPolicy,ExposurePreset,Qra2Parameter}.php` + factories
- Create: `app/Filament/Resources/AssessmentPolicies/**`, `app/Filament/Resources/ExposurePresets/**`, `app/Filament/Resources/Qra2Parameters/**`
- Test: `tests/Feature/Dossiers/AssessmentParameterVersioningTest.php`, `tests/Feature/Filament/AssessmentParameterResourcesTest.php`

- [ ] **Step 1: Write the failing versioning tests**

`tests/Feature/Dossiers/AssessmentParameterVersioningTest.php`:

```php
<?php

use App\Models\AssessmentPolicy;
use App\Models\ExposurePreset;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\ProductType;
use App\Models\Qra2Parameter;
use App\Models\User;
use App\Services\Dossiers\AppendOnlyVersionPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('versions the global assessment policy as one series', function (): void {
    $admin = User::factory()->admin()->create();
    $publisher = app(AppendOnlyVersionPublisher::class);
    $policy = [
        'default_dermal_absorption_percent' => '100',
        'default_oral_bioavailability_percent' => '50',
        'mos_threshold' => '100',
        'body_weight_kg' => '60',
        'source_reference' => 'SCCS Notes of Guidance; 100% DAp conservative policy pending assessor confirmation',
    ];

    $publisher->publish(AssessmentPolicy::class, $policy, $admin);
    $second = $publisher->publish(AssessmentPolicy::class, [...$policy, 'default_dermal_absorption_percent' => '50'], $admin);

    expect(AssessmentPolicy::query()->current()->sole()->is($second))->toBeTrue()
        ->and($second->version)->toBe(2);
});

it('versions exposure presets per product type', function (): void {
    $admin = User::factory()->admin()->create();
    $soap = ProductType::factory()->create();
    $balm = ProductType::factory()->create();
    $preset = [
        'amount_per_use_g' => '1',
        'uses_per_day' => '10',
        'retention_factor' => '0.01',
        'is_rinse_off' => true,
        'application_sites' => ['body', 'face'],
        'direct_routes' => ['dermal'],
        'secondary_routes' => ['ocular'],
        'target_population' => 'adults',
        'source_reference' => 'SCCS Notes of Guidance, table of daily exposure',
    ];
    $publisher = app(AppendOnlyVersionPublisher::class);

    $publisher->publish(ExposurePreset::class, [...$preset, 'product_type_id' => $soap->id], $admin);
    $publisher->publish(ExposurePreset::class, [...$preset, 'product_type_id' => $soap->id, 'uses_per_day' => '6'], $admin);
    $balmPreset = $publisher->publish(ExposurePreset::class, [...$preset, 'product_type_id' => $balm->id, 'is_rinse_off' => false, 'retention_factor' => '1'], $admin);

    expect(ExposurePreset::query()->where('product_type_id', $soap->id)->current()->sole()->version)->toBe(2)
        ->and($balmPreset->version)->toBe(1)
        ->and($balmPreset->application_sites)->toBe(['body', 'face']);
});

it('versions QRA2 parameters per IFRA category and amendment and keeps the factor decision explicit', function (): void {
    $admin = User::factory()->admin()->create();
    $parameters = app(AppendOnlyVersionPublisher::class)->publish(Qra2Parameter::class, [
        'ifra_product_category_id' => IfraProductCategory::factory()->create()->id,
        'ifra_amendment_id' => IfraAmendment::factory()->create()->id,
        'sensitization_assessment_factor' => '300',
        'consumer_exposure_level_mg_cm2_day' => '0.2',
        'qra2_factor' => '0.5',
        'applies_qra2_factor' => false,
        'source_reference' => 'IFRA 49th amendment guidance',
    ], $admin);

    expect($parameters->version)->toBe(1)
        ->and($parameters->applies_qra2_factor)->toBeFalse()
        ->and($parameters->qra2_factor)->toBe('0.5000');
});

it('refuses to edit a published parameter version', function (): void {
    $policy = AssessmentPolicy::factory()->create();

    expect(fn () => $policy->update(['mos_threshold' => '50']))->toThrow(LogicException::class);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Dossiers/AssessmentParameterVersioningTest.php`
Expected: FAIL — `AssessmentPolicy` not found.

- [ ] **Step 3: Migration**

Run: `php artisan make:migration create_assessment_parameter_tables --no-interaction`, then:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->boolean('is_current')->default(true)->index();
            $table->timestamp('superseded_at')->nullable();
            $table->decimal('default_dermal_absorption_percent', 8, 4);
            $table->decimal('default_oral_bioavailability_percent', 8, 4);
            $table->decimal('mos_threshold', 12, 4);
            $table->decimal('body_weight_kg', 8, 3);
            $table->text('source_reference');
            $table->text('change_note')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('exposure_presets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_type_id');
            $table->foreign('product_type_id')->references('id')->on('product_types')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('is_current')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->decimal('amount_per_use_g', 12, 4);
            $table->decimal('uses_per_day', 8, 3);
            $table->decimal('retention_factor', 8, 5);
            $table->boolean('is_rinse_off');
            $table->json('application_sites');
            $table->json('direct_routes');
            $table->json('secondary_routes');
            $table->string('target_population');
            $table->text('source_reference');
            $table->text('change_note')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_type_id', 'version']);
            $table->index(['product_type_id', 'is_current']);
        });

        Schema::create('qra2_parameters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ifra_product_category_id');
            $table->foreign('ifra_product_category_id')->references('id')->on('ifra_product_categories')->restrictOnDelete();
            $table->unsignedBigInteger('ifra_amendment_id');
            $table->foreign('ifra_amendment_id')->references('id')->on('ifra_amendments')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('is_current')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->decimal('sensitization_assessment_factor', 10, 3);
            $table->decimal('consumer_exposure_level_mg_cm2_day', 12, 6);
            $table->decimal('qra2_factor', 8, 4)->nullable();
            $table->boolean('applies_qra2_factor')->default(false);
            $table->text('source_reference');
            $table->text('change_note')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ifra_product_category_id', 'ifra_amendment_id', 'version'], 'qra2_parameters_series_version_unique');
            $table->index(['ifra_product_category_id', 'ifra_amendment_id', 'is_current'], 'qra2_parameters_series_current_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qra2_parameters');
        Schema::dropIfExists('exposure_presets');
        Schema::dropIfExists('assessment_policies');
    }
};
```

- [ ] **Step 4: Models**

Run `php artisan make:model AssessmentPolicy --factory --no-interaction`, `php artisan make:model ExposurePreset --factory --no-interaction`, `php artisan make:model Qra2Parameter --factory --no-interaction`.

`app/Models/AssessmentPolicy.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAppendOnlyVersions;
use Database\Factories\AssessmentPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Global scientific-assumption defaults used when a profile or dossier gives no value.
 */
#[Fillable([
    'version', 'is_current', 'superseded_at',
    'default_dermal_absorption_percent', 'default_oral_bioavailability_percent', 'mos_threshold', 'body_weight_kg',
    'source_reference', 'change_note', 'published_by_user_id',
])]
class AssessmentPolicy extends Model
{
    /** @use HasFactory<AssessmentPolicyFactory> */
    use HasFactory;

    use HasAppendOnlyVersions;

    public static function versionScopeColumns(): array
    {
        return [];
    }

    protected function casts(): array
    {
        return [
            'is_current' => 'bool',
            'superseded_at' => 'datetime',
            'default_dermal_absorption_percent' => 'decimal:4',
            'default_oral_bioavailability_percent' => 'decimal:4',
            'mos_threshold' => 'decimal:4',
            'body_weight_kg' => 'decimal:3',
        ];
    }
}
```

`app/Models/ExposurePreset.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAppendOnlyVersions;
use Database\Factories\ExposurePresetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exposure assumptions for one finished-product type.
 */
#[Fillable([
    'product_type_id', 'version', 'is_current', 'superseded_at',
    'amount_per_use_g', 'uses_per_day', 'retention_factor', 'is_rinse_off',
    'application_sites', 'direct_routes', 'secondary_routes', 'target_population',
    'source_reference', 'change_note', 'published_by_user_id',
])]
class ExposurePreset extends Model
{
    /** @use HasFactory<ExposurePresetFactory> */
    use HasFactory;

    use HasAppendOnlyVersions;

    public static function versionScopeColumns(): array
    {
        return ['product_type_id'];
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    protected function casts(): array
    {
        return [
            'is_current' => 'bool',
            'superseded_at' => 'datetime',
            'amount_per_use_g' => 'decimal:4',
            'uses_per_day' => 'decimal:3',
            'retention_factor' => 'decimal:5',
            'is_rinse_off' => 'bool',
            'application_sites' => 'array',
            'direct_routes' => 'array',
            'secondary_routes' => 'array',
        ];
    }
}
```

`app/Models/Qra2Parameter.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAppendOnlyVersions;
use Database\Factories\Qra2ParameterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IFRA QRA2 method parameters for one IFRA category under one amendment.
 * Whether the QRA2 factor multiplies Cmax is an explicit, recorded decision (spec §11 open question 1).
 */
#[Fillable([
    'ifra_product_category_id', 'ifra_amendment_id', 'version', 'is_current', 'superseded_at',
    'sensitization_assessment_factor', 'consumer_exposure_level_mg_cm2_day', 'qra2_factor', 'applies_qra2_factor',
    'source_reference', 'change_note', 'published_by_user_id',
])]
class Qra2Parameter extends Model
{
    /** @use HasFactory<Qra2ParameterFactory> */
    use HasFactory;

    use HasAppendOnlyVersions;

    public static function versionScopeColumns(): array
    {
        return ['ifra_product_category_id', 'ifra_amendment_id'];
    }

    public function ifraProductCategory(): BelongsTo
    {
        return $this->belongsTo(IfraProductCategory::class);
    }

    public function ifraAmendment(): BelongsTo
    {
        return $this->belongsTo(IfraAmendment::class);
    }

    protected function casts(): array
    {
        return [
            'is_current' => 'bool',
            'superseded_at' => 'datetime',
            'sensitization_assessment_factor' => 'decimal:3',
            'consumer_exposure_level_mg_cm2_day' => 'decimal:6',
            'qra2_factor' => 'decimal:4',
            'applies_qra2_factor' => 'bool',
        ];
    }
}
```

- [ ] **Step 5: Factories**

`database/factories/AssessmentPolicyFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\AssessmentPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssessmentPolicy>
 */
class AssessmentPolicyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version' => 1,
            'is_current' => true,
            'superseded_at' => null,
            'default_dermal_absorption_percent' => '100',
            'default_oral_bioavailability_percent' => '50',
            'mos_threshold' => '100',
            'body_weight_kg' => '60',
            'source_reference' => 'Factory policy',
        ];
    }
}
```

`database/factories/ExposurePresetFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ExposurePreset;
use App\Models\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExposurePreset>
 */
class ExposurePresetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_type_id' => ProductType::factory(),
            'version' => 1,
            'is_current' => true,
            'superseded_at' => null,
            'amount_per_use_g' => '1',
            'uses_per_day' => '10',
            'retention_factor' => '0.01',
            'is_rinse_off' => true,
            'application_sites' => ['body', 'face'],
            'direct_routes' => ['dermal'],
            'secondary_routes' => ['ocular'],
            'target_population' => 'adults',
            'source_reference' => 'Factory preset',
        ];
    }
}
```

`database/factories/Qra2ParameterFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Qra2Parameter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Qra2Parameter>
 */
class Qra2ParameterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ifra_product_category_id' => IfraProductCategory::factory(),
            'ifra_amendment_id' => IfraAmendment::factory(),
            'version' => 1,
            'is_current' => true,
            'superseded_at' => null,
            'sensitization_assessment_factor' => '300',
            'consumer_exposure_level_mg_cm2_day' => '0.2',
            'qra2_factor' => null,
            'applies_qra2_factor' => false,
            'source_reference' => 'Factory QRA2 parameters',
        ];
    }
}
```

- [ ] **Step 6: Migrate and run the versioning tests**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/Dossiers/AssessmentParameterVersioningTest.php`
Expected: PASS (4 tests).

- [ ] **Step 7: Write the failing Filament test**

`tests/Feature/Filament/AssessmentParameterResourcesTest.php`:

```php
<?php

use App\Filament\Resources\AssessmentPolicies\Pages\CreateAssessmentPolicy;
use App\Filament\Resources\ExposurePresets\Pages\CreateExposurePreset;
use App\Filament\Resources\Qra2Parameters\Pages\CreateQra2Parameter;
use App\Models\AssessmentPolicy;
use App\Models\ExposurePreset;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\ProductType;
use App\Models\Qra2Parameter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('publishes an assessment policy version from the admin', function (): void {
    Livewire::test(CreateAssessmentPolicy::class)
        ->fillForm([
            'default_dermal_absorption_percent' => '100',
            'default_oral_bioavailability_percent' => '50',
            'mos_threshold' => '100',
            'body_weight_kg' => '60',
            'source_reference' => 'SCCS Notes of Guidance',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AssessmentPolicy::query()->current()->sole()->version)->toBe(1);
});

it('requires a source for an exposure preset', function (): void {
    Livewire::test(CreateExposurePreset::class)
        ->fillForm([
            'product_type_id' => ProductType::factory()->create()->id,
            'amount_per_use_g' => '1',
            'uses_per_day' => '10',
            'retention_factor' => '0.01',
            'is_rinse_off' => true,
            'application_sites' => ['body'],
            'direct_routes' => ['dermal'],
            'secondary_routes' => [],
            'target_population' => 'adults',
            'source_reference' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['source_reference' => 'required']);

    expect(ExposurePreset::query()->count())->toBe(0);
});

it('publishes QRA2 parameters for a category and amendment', function (): void {
    Livewire::test(CreateQra2Parameter::class)
        ->fillForm([
            'ifra_product_category_id' => IfraProductCategory::factory()->create()->id,
            'ifra_amendment_id' => IfraAmendment::factory()->create()->id,
            'sensitization_assessment_factor' => '300',
            'consumer_exposure_level_mg_cm2_day' => '0.2',
            'qra2_factor' => '0.5',
            'applies_qra2_factor' => false,
            'source_reference' => 'IFRA 49th amendment guidance',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Qra2Parameter::query()->sole()->version)->toBe(1);
});
```

- [ ] **Step 8: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Filament/AssessmentParameterResourcesTest.php`
Expected: FAIL — page classes not found.

- [ ] **Step 9: Assessment policy resource**

`app/Filament/Resources/AssessmentPolicies/AssessmentPolicyResource.php`:

```php
<?php

namespace App\Filament\Resources\AssessmentPolicies;

use App\Filament\Resources\AssessmentPolicies\Pages\CreateAssessmentPolicy;
use App\Filament\Resources\AssessmentPolicies\Pages\ListAssessmentPolicies;
use App\Filament\Resources\AssessmentPolicies\Pages\ViewAssessmentPolicy;
use App\Models\AssessmentPolicy;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssessmentPolicyResource extends Resource
{
    protected static ?string $model = AssessmentPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::AdjustmentsHorizontal;

    protected static ?int $navigationSort = 62;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'assessment policy';
    }

    public static function getPluralModelLabel(): string
    {
        return 'assessment policies';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Default scientific assumptions')
                ->description('Used when a toxicological profile or a dossier gives no value. Saving publishes a new version.')
                ->schema([
                    TextInput::make('default_dermal_absorption_percent')->label('Default dermal absorption (%)')->numeric()->required(),
                    TextInput::make('default_oral_bioavailability_percent')->label('Default oral bioavailability (%)')->numeric()->required(),
                    TextInput::make('mos_threshold')->label('MoS threshold')->numeric()->required(),
                    TextInput::make('body_weight_kg')->label('Body weight (kg)')->numeric()->required(),
                    Textarea::make('source_reference')->label('Source')->rows(3)->required()->columnSpanFull(),
                    Textarea::make('change_note')->label('What changed in this version')->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->sortable(),
                IconColumn::make('is_current')->label('Current')->boolean(),
                TextColumn::make('default_dermal_absorption_percent')->label('DAp %'),
                TextColumn::make('default_oral_bioavailability_percent')->label('Oral %'),
                TextColumn::make('mos_threshold')->label('MoS'),
                TextColumn::make('body_weight_kg')->label('bw kg'),
                TextColumn::make('created_at')->label('Published')->dateTime(),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('version', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssessmentPolicies::route('/'),
            'create' => CreateAssessmentPolicy::route('/create'),
            'view' => ViewAssessmentPolicy::route('/{record}'),
        ];
    }
}
```

`app/Filament/Resources/AssessmentPolicies/Pages/ListAssessmentPolicies.php`:

```php
<?php

namespace App\Filament\Resources\AssessmentPolicies\Pages;

use App\Filament\Resources\AssessmentPolicies\AssessmentPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssessmentPolicies extends ListRecords
{
    protected static string $resource = AssessmentPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

`app/Filament/Resources/AssessmentPolicies/Pages/CreateAssessmentPolicy.php`:

```php
<?php

namespace App\Filament\Resources\AssessmentPolicies\Pages;

use App\Filament\Concerns\PublishesAppendOnlyVersions;
use App\Filament\Resources\AssessmentPolicies\AssessmentPolicyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAssessmentPolicy extends CreateRecord
{
    use PublishesAppendOnlyVersions;

    protected static string $resource = AssessmentPolicyResource::class;
}
```

`app/Filament/Resources/AssessmentPolicies/Pages/ViewAssessmentPolicy.php`:

```php
<?php

namespace App\Filament\Resources\AssessmentPolicies\Pages;

use App\Filament\Concerns\OffersNewVersion;
use App\Filament\Resources\AssessmentPolicies\AssessmentPolicyResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAssessmentPolicy extends ViewRecord
{
    use OffersNewVersion;

    protected static string $resource = AssessmentPolicyResource::class;
}
```

- [ ] **Step 10: Exposure preset resource**

`app/Filament/Resources/ExposurePresets/ExposurePresetResource.php`:

```php
<?php

namespace App\Filament\Resources\ExposurePresets;

use App\Filament\Resources\ExposurePresets\Pages\CreateExposurePreset;
use App\Filament\Resources\ExposurePresets\Pages\ListExposurePresets;
use App\Filament\Resources\ExposurePresets\Pages\ViewExposurePreset;
use App\Models\ExposurePreset;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExposurePresetResource extends Resource
{
    protected static ?string $model = ExposurePreset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::HandRaised;

    protected static ?int $navigationSort = 63;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'exposure preset';
    }

    public static function getPluralModelLabel(): string
    {
        return 'exposure presets';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Exposure per product type')
                ->description('SCCS Notes of Guidance values with their source. Saving publishes a new version for the product type.')
                ->schema([
                    Select::make('product_type_id')
                        ->relationship(name: 'productType', titleAttribute: 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('target_population')->required()->maxLength(255),
                    TextInput::make('amount_per_use_g')->label('Amount per use (g)')->numeric()->required(),
                    TextInput::make('uses_per_day')->label('Uses per day')->numeric()->required(),
                    TextInput::make('retention_factor')->label('Retention factor')->numeric()->required(),
                    Toggle::make('is_rinse_off')->label('Rinse-off product'),
                    TagsInput::make('application_sites')->label('Application sites')->required(),
                    TagsInput::make('direct_routes')->label('Direct exposure routes')->required(),
                    TagsInput::make('secondary_routes')->label('Secondary exposure routes'),
                    Textarea::make('source_reference')->label('Source')->rows(3)->required()->columnSpanFull(),
                    Textarea::make('change_note')->label('What changed in this version')->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('productType'))
            ->columns([
                TextColumn::make('productType.name')->label('Product type')->searchable(),
                TextColumn::make('version'),
                IconColumn::make('is_current')->label('Current')->boolean(),
                TextColumn::make('amount_per_use_g')->label('g / use'),
                TextColumn::make('uses_per_day')->label('Uses / day'),
                TextColumn::make('retention_factor')->label('R'),
                IconColumn::make('is_rinse_off')->label('Rinse-off')->boolean(),
            ])
            ->filters([TernaryFilter::make('is_current')->label('Current versions')->default(true)])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExposurePresets::route('/'),
            'create' => CreateExposurePreset::route('/create'),
            'view' => ViewExposurePreset::route('/{record}'),
        ];
    }
}
```

`app/Filament/Resources/ExposurePresets/Pages/ListExposurePresets.php`:

```php
<?php

namespace App\Filament\Resources\ExposurePresets\Pages;

use App\Filament\Resources\ExposurePresets\ExposurePresetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExposurePresets extends ListRecords
{
    protected static string $resource = ExposurePresetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

`app/Filament/Resources/ExposurePresets/Pages/CreateExposurePreset.php`:

```php
<?php

namespace App\Filament\Resources\ExposurePresets\Pages;

use App\Filament\Concerns\PublishesAppendOnlyVersions;
use App\Filament\Resources\ExposurePresets\ExposurePresetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExposurePreset extends CreateRecord
{
    use PublishesAppendOnlyVersions;

    protected static string $resource = ExposurePresetResource::class;
}
```

`app/Filament/Resources/ExposurePresets/Pages/ViewExposurePreset.php`:

```php
<?php

namespace App\Filament\Resources\ExposurePresets\Pages;

use App\Filament\Concerns\OffersNewVersion;
use App\Filament\Resources\ExposurePresets\ExposurePresetResource;
use Filament\Resources\Pages\ViewRecord;

class ViewExposurePreset extends ViewRecord
{
    use OffersNewVersion;

    protected static string $resource = ExposurePresetResource::class;
}
```

- [ ] **Step 11: QRA2 parameter resource**

`app/Filament/Resources/Qra2Parameters/Qra2ParameterResource.php`:

```php
<?php

namespace App\Filament\Resources\Qra2Parameters;

use App\Filament\Resources\Qra2Parameters\Pages\CreateQra2Parameter;
use App\Filament\Resources\Qra2Parameters\Pages\ListQra2Parameters;
use App\Filament\Resources\Qra2Parameters\Pages\ViewQra2Parameter;
use App\Models\Qra2Parameter;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class Qra2ParameterResource extends Resource
{
    protected static ?string $model = Qra2Parameter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected static ?int $navigationSort = 64;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'QRA2 parameter set';
    }

    public static function getPluralModelLabel(): string
    {
        return 'QRA2 parameters';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('QRA2 per IFRA category')
                ->description('Saving publishes a new version for the category and amendment.')
                ->schema([
                    Select::make('ifra_product_category_id')
                        ->label('IFRA category')
                        ->relationship(name: 'ifraProductCategory', titleAttribute: 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('ifra_amendment_id')
                        ->label('IFRA amendment')
                        ->relationship(name: 'ifraAmendment', titleAttribute: 'code')
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('sensitization_assessment_factor')->label('SAF')->numeric()->required(),
                    TextInput::make('consumer_exposure_level_mg_cm2_day')->label('CEL (mg/cm²/day)')->numeric()->required(),
                    TextInput::make('qra2_factor')->label('QRA2 factor')->numeric(),
                    Toggle::make('applies_qra2_factor')
                        ->label('Multiply Cmax by the QRA2 factor')
                        ->helperText('Open question for the safety assessor (DIP spec §11.1). Record the decision and its source.'),
                    Textarea::make('source_reference')->label('Source')->rows(3)->required()->columnSpanFull(),
                    Textarea::make('change_note')->label('What changed in this version')->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['ifraProductCategory', 'ifraAmendment']))
            ->columns([
                TextColumn::make('ifraProductCategory.name')->label('Category'),
                TextColumn::make('ifraAmendment.code')->label('Amendment'),
                TextColumn::make('version'),
                IconColumn::make('is_current')->label('Current')->boolean(),
                TextColumn::make('sensitization_assessment_factor')->label('SAF'),
                TextColumn::make('consumer_exposure_level_mg_cm2_day')->label('CEL'),
                TextColumn::make('qra2_factor')->label('Factor')->placeholder('—'),
                IconColumn::make('applies_qra2_factor')->label('Factor applied')->boolean(),
            ])
            ->filters([TernaryFilter::make('is_current')->label('Current versions')->default(true)])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQra2Parameters::route('/'),
            'create' => CreateQra2Parameter::route('/create'),
            'view' => ViewQra2Parameter::route('/{record}'),
        ];
    }
}
```

`app/Filament/Resources/Qra2Parameters/Pages/ListQra2Parameters.php`:

```php
<?php

namespace App\Filament\Resources\Qra2Parameters\Pages;

use App\Filament\Resources\Qra2Parameters\Qra2ParameterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQra2Parameters extends ListRecords
{
    protected static string $resource = Qra2ParameterResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

`app/Filament/Resources/Qra2Parameters/Pages/CreateQra2Parameter.php`:

```php
<?php

namespace App\Filament\Resources\Qra2Parameters\Pages;

use App\Filament\Concerns\PublishesAppendOnlyVersions;
use App\Filament\Resources\Qra2Parameters\Qra2ParameterResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQra2Parameter extends CreateRecord
{
    use PublishesAppendOnlyVersions;

    protected static string $resource = Qra2ParameterResource::class;
}
```

`app/Filament/Resources/Qra2Parameters/Pages/ViewQra2Parameter.php`:

```php
<?php

namespace App\Filament\Resources\Qra2Parameters\Pages;

use App\Filament\Concerns\OffersNewVersion;
use App\Filament\Resources\Qra2Parameters\Qra2ParameterResource;
use Filament\Resources\Pages\ViewRecord;

class ViewQra2Parameter extends ViewRecord
{
    use OffersNewVersion;

    protected static string $resource = Qra2ParameterResource::class;
}
```

- [ ] **Step 12: Run the parameter tests**

Run: `php artisan test --compact tests/Feature/Dossiers/AssessmentParameterVersioningTest.php tests/Feature/Filament/AssessmentParameterResourcesTest.php`
Expected: PASS (7 tests).

- [ ] **Step 13: Filacheck, format, commit**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
git add app/Models/AssessmentPolicy.php app/Models/ExposurePreset.php app/Models/Qra2Parameter.php app/Filament/Resources/AssessmentPolicies app/Filament/Resources/ExposurePresets app/Filament/Resources/Qra2Parameters database/migrations/*_create_assessment_parameter_tables.php database/factories/AssessmentPolicyFactory.php database/factories/ExposurePresetFactory.php database/factories/Qra2ParameterFactory.php tests/Feature/Dossiers/AssessmentParameterVersioningTest.php tests/Feature/Filament/AssessmentParameterResourcesTest.php
git commit -m "Add versioned assessment policy, exposure presets and QRA2 parameters"
```

---

### Task 9: Material assessment coverage

**Files:**
- Create: `app/Enums/AssessmentReviewState.php`
- Create: `database/migrations/<timestamp>_create_material_assessment_coverages_table.php`
- Create: `app/Models/MaterialAssessmentCoverage.php`, `database/factories/MaterialAssessmentCoverageFactory.php`
- Create: `app/Services/Dossiers/MaterialAssessmentCoverageService.php`
- Test: `tests/Feature/Dossiers/MaterialAssessmentCoverageTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Dossiers/MaterialAssessmentCoverageTest.php`:

```php
<?php

use App\Enums\AssessmentReviewState;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Models\Ingredient;
use App\Models\IngredientSubstanceEntry;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Services\Dossiers\MaterialAssessmentCoverageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function profiledSubstance(string $name): Substance
{
    $substance = Substance::factory()->create(['name' => $name, 'entity_type' => 'whole_ingredient']);
    ToxicologyProfile::factory()->for($substance)->create();

    return $substance;
}

function privateIngredientFor(Workspace $workspace): Ingredient
{
    return Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
}

it('treats a material without coverage as not reviewed', function (): void {
    expect(app(MaterialAssessmentCoverageService::class)->stateFor(Ingredient::factory()->create()))
        ->toBe(AssessmentReviewState::NotReviewed);
});

it('accepts whole-material coverage for an ingredient with an empty constituent list', function (): void {
    $olive = Ingredient::factory()->create(['inci_name' => 'OLEA EUROPAEA FRUIT OIL']);
    $profiled = profiledSubstance('Olea europaea fruit oil');

    $coverage = app(MaterialAssessmentCoverageService::class)->review(User::factory()->admin()->create(), $olive, [
        'review_state' => 'sufficient',
        'covers_whole_material' => true,
        'covers_constituents' => false,
        'supporting_substance_ids' => [$profiled->id],
    ]);

    expect($olive->substanceEntries()->count())->toBe(0)
        ->and($coverage->review_state)->toBe(AssessmentReviewState::Sufficient)
        ->and($coverage->workspace_id)->toBeNull()
        ->and($coverage->supportingSubstances->pluck('id')->all())->toBe([$profiled->id]);
});

it('accepts whole-material plus constituent coverage', function (): void {
    $rosemary = Ingredient::factory()->create();
    IngredientSubstanceEntry::factory()->for($rosemary)->create();

    $coverage = app(MaterialAssessmentCoverageService::class)->review(User::factory()->admin()->create(), $rosemary, [
        'review_state' => 'sufficient',
        'covers_whole_material' => true,
        'covers_constituents' => true,
        'supporting_substance_ids' => [profiledSubstance('Rosmarinus officinalis leaf oil')->id],
    ]);

    expect($coverage->covers_whole_material)->toBeTrue()
        ->and($coverage->covers_constituents)->toBeTrue();
});

it('covers a final soap material such as sodium olivate', function (): void {
    $sodiumOlivate = Substance::factory()->create(['name' => 'Sodium olivate']);

    $coverage = app(MaterialAssessmentCoverageService::class)->review(User::factory()->admin()->create(), $sodiumOlivate, [
        'review_state' => 'sufficient',
        'covers_whole_material' => true,
        'covers_constituents' => false,
        'supporting_substance_ids' => [profiledSubstance('Sodium olivate (profile)')->id],
    ]);

    expect(app(MaterialAssessmentCoverageService::class)->stateFor($sodiumOlivate))->toBe(AssessmentReviewState::Sufficient)
        ->and($coverage->coverable->is($sodiumOlivate))->toBeTrue();
});

it('rejects inconsistent coverage', function (array $review, string $errorKey): void {
    $ingredient = Ingredient::factory()->create();
    $unprofiled = Substance::factory()->create();

    try {
        app(MaterialAssessmentCoverageService::class)->review(User::factory()->admin()->create(), $ingredient, [
            'review_state' => 'sufficient',
            'covers_whole_material' => false,
            'covers_constituents' => false,
            'supporting_substance_ids' => [],
            ...$review,
            'supporting_substance_ids' => $review['supporting_substance_ids'] ?? ($review['use_unprofiled'] ?? false ? [$unprofiled->id] : []),
        ]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($errorKey);
    }
})->with([
    'sufficient without any coverage' => [[], 'covers_whole_material'],
    'whole material without a profiled reference' => [['covers_whole_material' => true, 'use_unprofiled' => true], 'supporting_substance_ids'],
    'constituents when none are tracked' => [['covers_constituents' => true], 'covers_constituents'],
    'insufficient without a reason' => [['review_state' => 'insufficient'], 'insufficient_reason'],
]);

it('lets owners of a granted workspace review their private ingredient but not platform materials', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $private = privateIngredientFor($workspace);
    $service = app(MaterialAssessmentCoverageService::class);

    $coverage = $service->review($owner, $private, [
        'review_state' => 'insufficient',
        'insufficient_reason' => 'No NESIL for citral in this blend.',
    ]);

    expect($coverage->workspace_id)->toBe($workspace->id);

    expect(fn () => $service->review($owner, Ingredient::factory()->create(), ['review_state' => 'not_reviewed']))
        ->toThrow(AuthorizationException::class);
});

it('refuses private-ingredient review without the module grant', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    app(MaterialAssessmentCoverageService::class)->review($owner, privateIngredientFor($workspace), ['review_state' => 'not_reviewed']);
})->throws(AuthorizationException::class);
```

Private ingredients are owned with `OwnerType::Workspace`, `owner_id` = workspace id (see `UserIngredientAuthoringService`). Before running, check `database/factories/IngredientSubstanceEntryFactory.php` creates a valid entry with `->for($rosemary)`; if its relation name differs, use `IngredientSubstanceEntry::factory()->create(['ingredient_id' => $rosemary->id])`.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Dossiers/MaterialAssessmentCoverageTest.php`
Expected: FAIL — `AssessmentReviewState` not found.

- [ ] **Step 3: Enum**

`app/Enums/AssessmentReviewState.php`:

```php
<?php

namespace App\Enums;

enum AssessmentReviewState: string
{
    case NotReviewed = 'not_reviewed';
    case Sufficient = 'sufficient';
    case Insufficient = 'insufficient';
}
```

- [ ] **Step 4: Migration**

Run: `php artisan make:migration create_material_assessment_coverages_table --no-interaction`, then:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_assessment_coverages', function (Blueprint $table) {
            $table->id();
            $table->morphs('coverable');
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('review_state', 24);
            $table->text('insufficient_reason')->nullable();
            $table->boolean('covers_whole_material')->default(false);
            $table->boolean('covers_constituents')->default(false);
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['coverable_type', 'coverable_id']);
        });

        Schema::create('material_assessment_coverage_substance', function (Blueprint $table) {
            $table->foreignId('material_assessment_coverage_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('substance_id');
            $table->foreign('substance_id')->references('id')->on('substance_catalog')->restrictOnDelete();
            $table->primary(['material_assessment_coverage_id', 'substance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_assessment_coverage_substance');
        Schema::dropIfExists('material_assessment_coverages');
    }
};
```

If the auto-generated foreign-key name for `material_assessment_coverage_id` exceeds PostgreSQL's 63-character limit, pass an explicit name: `->constrained(indexName: 'macs_coverage_id_foreign')`.

- [ ] **Step 5: Model and factory**

Run: `php artisan make:model MaterialAssessmentCoverage --factory --no-interaction`

`app/Models/MaterialAssessmentCoverage.php`:

```php
<?php

namespace App\Models;

use App\Enums\AssessmentReviewState;
use Database\Factories\MaterialAssessmentCoverageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What the safety assessment of one material (an ingredient or a final material such as a soap salt) rests on.
 * Platform-owned when workspace_id is null; owned by the workspace of a private ingredient otherwise.
 */
#[Fillable([
    'coverable_type',
    'coverable_id',
    'workspace_id',
    'review_state',
    'insufficient_reason',
    'covers_whole_material',
    'covers_constituents',
    'note',
    'reviewed_by_user_id',
    'reviewed_at',
])]
class MaterialAssessmentCoverage extends Model
{
    /** @use HasFactory<MaterialAssessmentCoverageFactory> */
    use HasFactory;

    public function coverable(): MorphTo
    {
        return $this->morphTo();
    }

    public function supportingSubstances(): BelongsToMany
    {
        return $this->belongsToMany(Substance::class, 'material_assessment_coverage_substance');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'review_state' => AssessmentReviewState::class,
            'covers_whole_material' => 'bool',
            'covers_constituents' => 'bool',
            'reviewed_at' => 'datetime',
        ];
    }
}
```

`database/factories/MaterialAssessmentCoverageFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\AssessmentReviewState;
use App\Models\Ingredient;
use App\Models\MaterialAssessmentCoverage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialAssessmentCoverage>
 */
class MaterialAssessmentCoverageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'coverable_type' => Ingredient::class,
            'coverable_id' => Ingredient::factory(),
            'workspace_id' => null,
            'review_state' => AssessmentReviewState::NotReviewed,
            'insufficient_reason' => null,
            'covers_whole_material' => false,
            'covers_constituents' => false,
            'note' => null,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
        ];
    }
}
```

- [ ] **Step 6: Service**

`app/Services/Dossiers/MaterialAssessmentCoverageService.php`:

```php
<?php

namespace App\Services\Dossiers;

use App\Enums\AssessmentReviewState;
use App\Enums\WorkspaceModule;
use App\Models\Ingredient;
use App\Models\MaterialAssessmentCoverage;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialAssessmentCoverageService
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    public function coverageFor(Ingredient|Substance $material): ?MaterialAssessmentCoverage
    {
        return MaterialAssessmentCoverage::query()
            ->where('coverable_type', $material::class)
            ->where('coverable_id', $material->getKey())
            ->first();
    }

    public function stateFor(Ingredient|Substance $material): AssessmentReviewState
    {
        return $this->coverageFor($material)?->review_state ?? AssessmentReviewState::NotReviewed;
    }

    /**
     * @param  array{review_state: string, insufficient_reason?: string|null, covers_whole_material?: bool,
     *               covers_constituents?: bool, supporting_substance_ids?: list<int>, note?: string|null}  $review
     */
    public function review(User $actor, Ingredient|Substance $material, array $review): MaterialAssessmentCoverage
    {
        $this->assertCanReview($actor, $material);

        $state = AssessmentReviewState::from($review['review_state']);
        $coversWhole = (bool) ($review['covers_whole_material'] ?? false);
        $coversConstituents = (bool) ($review['covers_constituents'] ?? false);
        $substanceIds = collect($review['supporting_substance_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->unique()->values();

        $this->assertConsistent($material, $state, $review['insufficient_reason'] ?? null, $coversWhole, $coversConstituents, $substanceIds->all());

        return DB::transaction(function () use ($actor, $material, $review, $state, $coversWhole, $coversConstituents, $substanceIds): MaterialAssessmentCoverage {
            MaterialAssessmentCoverage::query()
                ->where('coverable_type', $material::class)
                ->where('coverable_id', $material->getKey())
                ->lockForUpdate()
                ->first();

            $coverage = MaterialAssessmentCoverage::query()->updateOrCreate(
                ['coverable_type' => $material::class, 'coverable_id' => $material->getKey()],
                [
                    'workspace_id' => $material instanceof Ingredient ? $material->workspace_id : null,
                    'review_state' => $state,
                    'insufficient_reason' => $state === AssessmentReviewState::Insufficient ? trim((string) $review['insufficient_reason']) : null,
                    'covers_whole_material' => $coversWhole,
                    'covers_constituents' => $coversConstituents,
                    'note' => $review['note'] ?? null,
                    'reviewed_by_user_id' => $actor->id,
                    'reviewed_at' => now(),
                ],
            );

            $coverage->supportingSubstances()->sync($substanceIds->all());

            return $coverage->load('supportingSubstances');
        }, attempts: 5);
    }

    private function assertCanReview(User $actor, Ingredient|Substance $material): void
    {
        $workspaceId = $material instanceof Ingredient ? $material->workspace_id : null;

        $allowed = $workspaceId === null
            ? $actor->is_admin
            : $this->authorization->canEditModule($actor, $workspaceId, WorkspaceModule::ProductDossiers);

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  list<int>  $substanceIds
     */
    private function assertConsistent(
        Ingredient|Substance $material,
        AssessmentReviewState $state,
        ?string $insufficientReason,
        bool $coversWhole,
        bool $coversConstituents,
        array $substanceIds,
    ): void {
        $errors = [];

        if ($state === AssessmentReviewState::Insufficient && blank($insufficientReason)) {
            $errors['insufficient_reason'] = 'Name the uncertainty that prevents a conclusion.';
        }

        if ($state === AssessmentReviewState::Sufficient && ! $coversWhole && ! $coversConstituents) {
            $errors['covers_whole_material'] = 'Sufficient coverage needs the whole material, its constituents, or both.';
        }

        if ($coversWhole) {
            $profiled = ToxicologyProfile::query()->current()->whereIn('substance_id', $substanceIds)->distinct()->count('substance_id');

            if ($substanceIds === [] || $profiled !== count($substanceIds)) {
                $errors['supporting_substance_ids'] = 'Whole-material coverage needs profile references, each with a current toxicological profile.';
            }
        }

        if ($coversConstituents && ! $this->tracksConstituents($material)) {
            $errors['covers_constituents'] = 'No constituents are tracked for this material.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function tracksConstituents(Ingredient|Substance $material): bool
    {
        return $material instanceof Ingredient
            && ($material->substanceEntries()->exists() || $material->allergenEntries()->exists() || $material->components()->exists());
    }
}
```

- [ ] **Step 7: Migrate and run**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/Dossiers/MaterialAssessmentCoverageTest.php`
Expected: PASS (10 tests).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/AssessmentReviewState.php app/Models/MaterialAssessmentCoverage.php app/Services/Dossiers/MaterialAssessmentCoverageService.php database/migrations/*_create_material_assessment_coverages_table.php database/factories/MaterialAssessmentCoverageFactory.php tests/Feature/Dossiers/MaterialAssessmentCoverageTest.php
git commit -m "Add explicit assessment coverage for ingredients and final materials"
```

---

### Task 10: Admin resource for platform coverage

**Files:**
- Create: `app/Filament/Resources/MaterialAssessmentCoverages/{MaterialAssessmentCoverageResource.php, Pages/ListMaterialAssessmentCoverages.php, Pages/CreateMaterialAssessmentCoverage.php, Pages/EditMaterialAssessmentCoverage.php}`
- Test: `tests/Feature/Filament/MaterialAssessmentCoverageResourceTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Filament/MaterialAssessmentCoverageResourceTest.php`:

```php
<?php

use App\Enums\AssessmentReviewState;
use App\Filament\Resources\MaterialAssessmentCoverages\Pages\CreateMaterialAssessmentCoverage;
use App\Filament\Resources\MaterialAssessmentCoverages\Pages\EditMaterialAssessmentCoverage;
use App\Models\Ingredient;
use App\Models\MaterialAssessmentCoverage;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('records platform coverage through the coverage service', function (): void {
    $admin = User::factory()->admin()->create();
    $olive = Ingredient::factory()->create();
    $profiled = Substance::factory()->create();
    ToxicologyProfile::factory()->for($profiled)->create();
    $this->actingAs($admin);

    Livewire::test(CreateMaterialAssessmentCoverage::class)
        ->fillForm([
            'coverable_type' => Ingredient::class,
            'coverable_id' => $olive->id,
            'review_state' => 'sufficient',
            'covers_whole_material' => true,
            'covers_constituents' => false,
            'supporting_substance_ids' => [$profiled->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $coverage = MaterialAssessmentCoverage::query()->sole();

    expect($coverage->review_state)->toBe(AssessmentReviewState::Sufficient)
        ->and($coverage->reviewed_by_user_id)->toBe($admin->id);
});

it('surfaces the service validation on the form', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $coverage = MaterialAssessmentCoverage::factory()->create();

    Livewire::test(EditMaterialAssessmentCoverage::class, ['record' => $coverage->getKey()])
        ->fillForm(['review_state' => 'insufficient', 'insufficient_reason' => ''])
        ->call('save')
        ->assertHasFormErrors(['insufficient_reason']);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Filament/MaterialAssessmentCoverageResourceTest.php`
Expected: FAIL — page classes not found.

- [ ] **Step 3: Resource**

`app/Filament/Resources/MaterialAssessmentCoverages/MaterialAssessmentCoverageResource.php`:

```php
<?php

namespace App\Filament\Resources\MaterialAssessmentCoverages;

use App\Enums\AssessmentReviewState;
use App\Filament\Resources\MaterialAssessmentCoverages\Pages\CreateMaterialAssessmentCoverage;
use App\Filament\Resources\MaterialAssessmentCoverages\Pages\EditMaterialAssessmentCoverage;
use App\Filament\Resources\MaterialAssessmentCoverages\Pages\ListMaterialAssessmentCoverages;
use App\Models\Ingredient;
use App\Models\MaterialAssessmentCoverage;
use App\Models\Substance;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MaterialAssessmentCoverageResource extends Resource
{
    protected static ?string $model = MaterialAssessmentCoverage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ShieldCheck;

    protected static ?int $navigationSort = 65;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'assessment coverage';
    }

    public static function getPluralModelLabel(): string
    {
        return 'assessment coverage';
    }

    /**
     * Platform coverage only: private-ingredient coverage belongs to its workspace.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('workspace_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Material')
                ->description('A platform ingredient, or a final material such as a soap salt or glycerin.')
                ->schema([
                    MorphToSelect::make('coverable')
                        ->label('Material')
                        ->types([
                            MorphToSelect\Type::make(Ingredient::class)
                                ->titleAttribute('display_name')
                                ->modifyOptionsQueryUsing(fn (Builder $query): Builder => $query->whereNull('owner_type')->whereNull('workspace_id')),
                            MorphToSelect\Type::make(Substance::class)->titleAttribute('name'),
                        ])
                        ->searchable()
                        ->required()
                        ->disabledOn('edit'),
                ]),
            Section::make('Review')
                ->schema([
                    Select::make('review_state')
                        ->options(collect(AssessmentReviewState::cases())->mapWithKeys(fn (AssessmentReviewState $state): array => [$state->value => str($state->value)->replace('_', ' ')->ucfirst()->toString()])->all())
                        ->required()
                        ->live(),
                    Textarea::make('insufficient_reason')
                        ->label('Named uncertainty')
                        ->rows(2)
                        ->visible(fn (Get $get): bool => $get('review_state') === AssessmentReviewState::Insufficient->value),
                    Toggle::make('covers_whole_material')->label('Covers the whole material'),
                    Toggle::make('covers_constituents')->label('Covers its tracked constituents'),
                    Select::make('supporting_substance_ids')
                        ->label('Profile references')
                        ->helperText('Assessed materials whose current toxicological profile supports this coverage.')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => Substance::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Textarea::make('note')->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['coverable', 'reviewedBy']))
            ->columns([
                TextColumn::make('coverable_type')->label('Kind')->formatStateUsing(fn (string $state): string => class_basename($state))->badge(),
                TextColumn::make('material')
                    ->label('Material')
                    ->state(fn (MaterialAssessmentCoverage $record): string => (string) ($record->coverable?->display_name ?? $record->coverable?->name ?? '—')),
                TextColumn::make('review_state')->badge(),
                IconColumn::make('covers_whole_material')->label('Whole')->boolean(),
                IconColumn::make('covers_constituents')->label('Constituents')->boolean(),
                TextColumn::make('reviewedBy.email')->label('Reviewed by')->placeholder('—'),
                TextColumn::make('reviewed_at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('reviewed_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaterialAssessmentCoverages::route('/'),
            'create' => CreateMaterialAssessmentCoverage::route('/create'),
            'edit' => EditMaterialAssessmentCoverage::route('/{record}/edit'),
        ];
    }
}
```

`app/Filament/Resources/MaterialAssessmentCoverages/Pages/ListMaterialAssessmentCoverages.php`:

```php
<?php

namespace App\Filament\Resources\MaterialAssessmentCoverages\Pages;

use App\Filament\Resources\MaterialAssessmentCoverages\MaterialAssessmentCoverageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaterialAssessmentCoverages extends ListRecords
{
    protected static string $resource = MaterialAssessmentCoverageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

`app/Filament/Resources/MaterialAssessmentCoverages/Pages/CreateMaterialAssessmentCoverage.php`:

```php
<?php

namespace App\Filament\Resources\MaterialAssessmentCoverages\Pages;

use App\Filament\Resources\MaterialAssessmentCoverages\MaterialAssessmentCoverageResource;
use App\Models\Ingredient;
use App\Models\Substance;
use App\Services\Dossiers\MaterialAssessmentCoverageService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateMaterialAssessmentCoverage extends CreateRecord
{
    protected static string $resource = MaterialAssessmentCoverageResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $material = match ($data['coverable_type'] ?? null) {
            Ingredient::class => Ingredient::query()->whereNull('owner_type')->whereNull('workspace_id')->findOrFail($data['coverable_id']),
            Substance::class => Substance::query()->findOrFail($data['coverable_id']),
            default => throw ValidationException::withMessages(['data.coverable_type' => 'Choose an ingredient or a substance.']),
        };

        try {
            return app(MaterialAssessmentCoverageService::class)->review(auth()->user(), $material, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["data.{$key}" => $messages])->all(),
            );
        }
    }
}
```

`app/Filament/Resources/MaterialAssessmentCoverages/Pages/EditMaterialAssessmentCoverage.php`:

```php
<?php

namespace App\Filament\Resources\MaterialAssessmentCoverages\Pages;

use App\Filament\Resources\MaterialAssessmentCoverages\MaterialAssessmentCoverageResource;
use App\Models\MaterialAssessmentCoverage;
use App\Services\Dossiers\MaterialAssessmentCoverageService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditMaterialAssessmentCoverage extends EditRecord
{
    protected static string $resource = MaterialAssessmentCoverageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var MaterialAssessmentCoverage $record */
        $record = $this->getRecord();

        return [
            ...$data,
            'review_state' => $record->review_state->value,
            'supporting_substance_ids' => $record->supportingSubstances()->pluck('substance_catalog.id')->all(),
        ];
    }

    /**
     * @param  MaterialAssessmentCoverage  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(MaterialAssessmentCoverageService::class)->review(auth()->user(), $record->coverable, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["data.{$key}" => $messages])->all(),
            );
        }
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/Filament/MaterialAssessmentCoverageResourceTest.php tests/Feature/Dossiers/MaterialAssessmentCoverageTest.php`
Expected: PASS. If `MorphToSelect` stores its state under different keys than `coverable_type` / `coverable_id` in this Filament version, read them from the keys the form dehydrates (dump `$data` once) and adjust `handleRecordCreation` and the test's `fillForm` together.

- [ ] **Step 5: Filacheck, format, commit**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
git add app/Filament/Resources/MaterialAssessmentCoverages tests/Feature/Filament/MaterialAssessmentCoverageResourceTest.php
git commit -m "Add admin resource for platform assessment coverage"
```

---

### Task 11: Reviewed EU annex legal entries

**Files:**
- Create: `app/Enums/LegalAnnex.php`, `app/Enums/LegalEntryProductScope.php`, `app/Enums/LegalEntryReviewStatus.php`
- Create: `database/migrations/<timestamp>_create_regulatory_legal_entries_table.php`
- Create: `app/Models/RegulatoryLegalEntry.php`, `database/factories/RegulatoryLegalEntryFactory.php`
- Create: `app/Services/Dossiers/RegulatoryLegalEntryReviewer.php`
- Test: `tests/Feature/Dossiers/RegulatoryLegalEntryTest.php`

Decision recorded here (spec §2.4 left it to this plan): legal entries get a **new table**. The existing `regulatory_regime_substance_rules` table has a unique `(regulatory_regime_id, substance_id)` index and feeds the workbench through `SubstanceComplianceService`; changing it now would alter workbench behaviour. Aligning the workbench with legal entries is a later phase.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Dossiers/RegulatoryLegalEntryTest.php`:

```php
<?php

use App\Enums\LegalEntryReviewStatus;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegime;
use App\Models\Substance;
use App\Models\User;
use App\Services\Dossiers\RegulatoryLegalEntryReviewer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('lets one substance carry several legal entries in the same regime', function (): void {
    $regime = RegulatoryRegime::factory()->create(['code' => 'eu']);
    $limonene = Substance::factory()->create(['name' => 'Limonene']);

    foreach (['III/88', 'III/167', 'III/168'] as $reference) {
        RegulatoryLegalEntry::factory()->for($regime)->create(['entry_reference' => $reference])->substances()->attach($limonene);
    }

    expect($limonene->legalEntries()->count())->toBe(3);
});

it('marks a complete draft as reviewed by an admin', function (): void {
    $admin = User::factory()->admin()->create();
    $entry = RegulatoryLegalEntry::factory()->create(['entry_reference' => 'III/84', 'annex' => 'III']);
    $entry->substances()->attach(Substance::factory()->create());

    app(RegulatoryLegalEntryReviewer::class)->markReviewed($admin, $entry);

    expect($entry->refresh()->review_status)->toBe(LegalEntryReviewStatus::Reviewed)
        ->and($entry->reviewed_by_user_id)->toBe($admin->id);
});

it('refuses to review an incomplete entry', function (array $attributes, bool $withSubstance, string $errorKey): void {
    $entry = RegulatoryLegalEntry::factory()->create($attributes);

    if ($withSubstance) {
        $entry->substances()->attach(Substance::factory()->create());
    }

    try {
        app(RegulatoryLegalEntryReviewer::class)->markReviewed(User::factory()->admin()->create(), $entry);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($errorKey);
    }
})->with([
    'no substance' => [[], false, 'substances'],
    'no source' => [['source_reference' => ' '], true, 'source_reference'],
    'reference outside its annex' => [['annex' => 'IV', 'entry_reference' => 'III/84'], true, 'entry_reference'],
    'conditions scope without conditions' => [['product_scope' => 'see_conditions', 'product_type_body_parts' => null], true, 'product_type_body_parts'],
]);

it('refuses review by a non-admin', function (): void {
    $entry = RegulatoryLegalEntry::factory()->create();
    $entry->substances()->attach(Substance::factory()->create());

    app(RegulatoryLegalEntryReviewer::class)->markReviewed(User::factory()->create(), $entry);
})->throws(AuthorizationException::class);

it('freezes a reviewed entry except for its end date', function (): void {
    $entry = RegulatoryLegalEntry::factory()->reviewed()->create();

    $entry->update(['effective_until' => '2027-12-31']);

    expect($entry->refresh()->effective_until->toDateString())->toBe('2027-12-31')
        ->and(fn () => $entry->update(['max_concentration_percent' => '1']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Dossiers/RegulatoryLegalEntryTest.php`
Expected: FAIL — `RegulatoryLegalEntry` not found.

- [ ] **Step 3: Enums**

`app/Enums/LegalAnnex.php`:

```php
<?php

namespace App\Enums;

enum LegalAnnex: string
{
    case AnnexII = 'II';
    case AnnexIII = 'III';
    case AnnexIV = 'IV';
    case AnnexV = 'V';
    case AnnexVI = 'VI';
}
```

`app/Enums/LegalEntryProductScope.php`:

```php
<?php

namespace App\Enums;

enum LegalEntryProductScope: string
{
    case AllProducts = 'all_products';
    case RinseOffOnly = 'rinse_off_only';
    case LeaveOnOnly = 'leave_on_only';
    case SeeConditions = 'see_conditions';
}
```

`app/Enums/LegalEntryReviewStatus.php`:

```php
<?php

namespace App\Enums;

enum LegalEntryReviewStatus: string
{
    case Draft = 'draft';
    case Reviewed = 'reviewed';
}
```

- [ ] **Step 4: Migration**

Run: `php artisan make:migration create_regulatory_legal_entries_table --no-interaction`, then:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulatory_legal_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('regulatory_regime_id');
            $table->foreign('regulatory_regime_id')->references('id')->on('regulatory_regimes')->restrictOnDelete();
            $table->string('annex', 4);
            $table->string('entry_reference', 32);
            $table->string('clause', 32)->nullable();
            $table->text('substance_description');
            $table->string('product_scope', 24);
            $table->text('product_type_body_parts')->nullable();
            $table->decimal('max_concentration_percent', 12, 6)->nullable();
            $table->text('other_limitations')->nullable();
            $table->text('label_warnings_fr')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->date('placing_on_market_until')->nullable();
            $table->date('making_available_until')->nullable();
            $table->text('source_reference');
            $table->string('review_status', 16)->default('draft')->index();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['regulatory_regime_id', 'entry_reference']);
        });

        Schema::create('regulatory_legal_entry_substance', function (Blueprint $table) {
            $table->foreignId('regulatory_legal_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('substance_id');
            $table->foreign('substance_id')->references('id')->on('substance_catalog')->restrictOnDelete();
            $table->primary(['regulatory_legal_entry_id', 'substance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulatory_legal_entry_substance');
        Schema::dropIfExists('regulatory_legal_entries');
    }
};
```

- [ ] **Step 5: Model, factory and Substance relation**

Run: `php artisan make:model RegulatoryLegalEntry --factory --no-interaction`

`app/Models/RegulatoryLegalEntry.php`:

```php
<?php

namespace App\Models;

use App\Enums\LegalAnnex;
use App\Enums\LegalEntryProductScope;
use App\Enums\LegalEntryReviewStatus;
use Database\Factories\RegulatoryLegalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

/**
 * One entry/clause of an annex of Regulation (EC) No 1223/2009, transcribed from the consolidated EUR-Lex text.
 * Reviewed entries are frozen; an amendment ends one entry (effective_until) and adds a new one.
 */
#[Fillable([
    'regulatory_regime_id', 'annex', 'entry_reference', 'clause', 'substance_description',
    'product_scope', 'product_type_body_parts', 'max_concentration_percent', 'other_limitations', 'label_warnings_fr',
    'effective_from', 'effective_until', 'placing_on_market_until', 'making_available_until',
    'source_reference', 'review_status', 'reviewed_by_user_id', 'reviewed_at',
])]
class RegulatoryLegalEntry extends Model
{
    /** @use HasFactory<RegulatoryLegalEntryFactory> */
    use HasFactory;

    private const EDITABLE_AFTER_REVIEW = ['effective_until', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (RegulatoryLegalEntry $entry): void {
            if ($entry->getOriginal('review_status') !== LegalEntryReviewStatus::Reviewed) {
                return;
            }

            if (array_diff(array_keys($entry->getDirty()), self::EDITABLE_AFTER_REVIEW) !== []) {
                throw new LogicException('Reviewed legal entries are frozen; only their end date can change.');
            }
        });

        static::deleting(function (RegulatoryLegalEntry $entry): void {
            if ($entry->isReviewed()) {
                throw new LogicException('Reviewed legal entries cannot be deleted.');
            }
        });
    }

    public function regulatoryRegime(): BelongsTo
    {
        return $this->belongsTo(RegulatoryRegime::class);
    }

    public function substances(): BelongsToMany
    {
        return $this->belongsToMany(Substance::class, 'regulatory_legal_entry_substance');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isReviewed(): bool
    {
        return $this->review_status === LegalEntryReviewStatus::Reviewed;
    }

    protected function casts(): array
    {
        return [
            'annex' => LegalAnnex::class,
            'product_scope' => LegalEntryProductScope::class,
            'review_status' => LegalEntryReviewStatus::class,
            'max_concentration_percent' => 'decimal:6',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'placing_on_market_until' => 'date',
            'making_available_until' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }
}
```

`database/factories/RegulatoryLegalEntryFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\LegalEntryReviewStatus;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegime;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegulatoryLegalEntry>
 */
class RegulatoryLegalEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'regulatory_regime_id' => RegulatoryRegime::factory(),
            'annex' => 'III',
            'entry_reference' => 'III/'.fake()->unique()->numberBetween(1, 400),
            'clause' => null,
            'substance_description' => fake()->words(2, true),
            'product_scope' => 'all_products',
            'product_type_body_parts' => null,
            'max_concentration_percent' => null,
            'other_limitations' => null,
            'label_warnings_fr' => null,
            'effective_from' => null,
            'effective_until' => null,
            'placing_on_market_until' => null,
            'making_available_until' => null,
            'source_reference' => 'Regulation (EC) No 1223/2009, consolidated text (EUR-Lex)',
            'review_status' => LegalEntryReviewStatus::Draft,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
        ];
    }

    public function reviewed(): static
    {
        return $this->state(fn (): array => [
            'review_status' => LegalEntryReviewStatus::Reviewed,
            'reviewed_by_user_id' => User::factory()->admin(),
            'reviewed_at' => now(),
        ]);
    }
}
```

In `app/Models/Substance.php`, add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` and:

```php
    public function legalEntries(): BelongsToMany
    {
        return $this->belongsToMany(RegulatoryLegalEntry::class, 'regulatory_legal_entry_substance');
    }
```

- [ ] **Step 6: Reviewer**

`app/Services/Dossiers/RegulatoryLegalEntryReviewer.php`:

```php
<?php

namespace App\Services\Dossiers;

use App\Enums\LegalEntryProductScope;
use App\Enums\LegalEntryReviewStatus;
use App\Models\RegulatoryLegalEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegulatoryLegalEntryReviewer
{
    public function markReviewed(User $admin, RegulatoryLegalEntry $entry): RegulatoryLegalEntry
    {
        if (! $admin->is_admin) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($admin, $entry): RegulatoryLegalEntry {
            $locked = RegulatoryLegalEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isReviewed()) {
                return $locked;
            }

            $this->assertComplete($locked);

            $locked->update([
                'review_status' => LegalEntryReviewStatus::Reviewed,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $locked;
        }, attempts: 5);
    }

    private function assertComplete(RegulatoryLegalEntry $entry): void
    {
        $errors = [];

        if (blank($entry->source_reference)) {
            $errors['source_reference'] = 'Cite the consolidated legal text the entry was transcribed from.';
        }

        if (! $entry->substances()->exists()) {
            $errors['substances'] = 'Link at least one substance.';
        }

        if (preg_match('/^'.preg_quote($entry->annex->value, '/').'\/\d+[a-z]?$/', $entry->entry_reference) !== 1) {
            $errors['entry_reference'] = "Use the form {$entry->annex->value}/<number>, matching the annex.";
        }

        if ($entry->product_scope === LegalEntryProductScope::SeeConditions && blank($entry->product_type_body_parts)) {
            $errors['product_type_body_parts'] = 'Transcribe the product type and body parts the conditions refer to.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
```

- [ ] **Step 7: Migrate and run**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/Dossiers/RegulatoryLegalEntryTest.php`
Expected: PASS (8 tests).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/LegalAnnex.php app/Enums/LegalEntryProductScope.php app/Enums/LegalEntryReviewStatus.php app/Models/RegulatoryLegalEntry.php app/Models/Substance.php app/Services/Dossiers/RegulatoryLegalEntryReviewer.php database/migrations/*_create_regulatory_legal_entries_table.php database/factories/RegulatoryLegalEntryFactory.php tests/Feature/Dossiers/RegulatoryLegalEntryTest.php
git commit -m "Add reviewed EU annex legal entries with multiple clauses per substance"
```

---

### Task 12: Admin resource for legal entries

**Files:**
- Create: `app/Filament/Resources/RegulatoryLegalEntries/{RegulatoryLegalEntryResource.php, Pages/ListRegulatoryLegalEntries.php, Pages/CreateRegulatoryLegalEntry.php, Pages/EditRegulatoryLegalEntry.php}`
- Test: `tests/Feature/Filament/RegulatoryLegalEntryResourceTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Filament/RegulatoryLegalEntryResourceTest.php`:

```php
<?php

use App\Enums\LegalEntryReviewStatus;
use App\Filament\Resources\RegulatoryLegalEntries\Pages\CreateRegulatoryLegalEntry;
use App\Filament\Resources\RegulatoryLegalEntries\Pages\EditRegulatoryLegalEntry;
use App\Filament\Resources\RegulatoryLegalEntries\Pages\ListRegulatoryLegalEntries;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegime;
use App\Models\Substance;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('creates a draft entry linked to substances and reviews it from the list', function (): void {
    $linalool = Substance::factory()->create(['name' => 'Linalool']);

    Livewire::test(CreateRegulatoryLegalEntry::class)
        ->fillForm([
            'regulatory_regime_id' => RegulatoryRegime::factory()->create()->id,
            'annex' => 'III',
            'entry_reference' => 'III/84',
            'substance_description' => 'Linalool',
            'product_scope' => 'all_products',
            'source_reference' => 'Regulation (EC) No 1223/2009, Annex III, consolidated text',
            'substances' => [$linalool->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $entry = RegulatoryLegalEntry::query()->sole();

    expect($entry->review_status)->toBe(LegalEntryReviewStatus::Draft)
        ->and($entry->substances->pluck('id')->all())->toBe([$linalool->id]);

    Livewire::test(ListRegulatoryLegalEntries::class)
        ->callAction(TestAction::make('markReviewed')->table($entry));

    expect($entry->refresh()->isReviewed())->toBeTrue();
});

it('does not save edits to a reviewed entry', function (): void {
    $entry = RegulatoryLegalEntry::factory()->reviewed()->create(['substance_description' => 'Original']);

    Livewire::test(EditRegulatoryLegalEntry::class, ['record' => $entry->getKey()])
        ->fillForm(['substance_description' => 'Changed'])
        ->call('save');

    expect($entry->refresh()->substance_description)->toBe('Original');
});

it('sets the end date of a reviewed entry', function (): void {
    $entry = RegulatoryLegalEntry::factory()->reviewed()->create();

    Livewire::test(ListRegulatoryLegalEntries::class)
        ->callAction(TestAction::make('setEffectiveUntil')->table($entry), data: ['effective_until' => '2027-06-30']);

    expect($entry->refresh()->effective_until->toDateString())->toBe('2027-06-30');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Filament/RegulatoryLegalEntryResourceTest.php`
Expected: FAIL — page classes not found.

- [ ] **Step 3: Resource**

`app/Filament/Resources/RegulatoryLegalEntries/RegulatoryLegalEntryResource.php`:

```php
<?php

namespace App\Filament\Resources\RegulatoryLegalEntries;

use App\Enums\LegalAnnex;
use App\Enums\LegalEntryProductScope;
use App\Filament\Resources\RegulatoryLegalEntries\Pages\CreateRegulatoryLegalEntry;
use App\Filament\Resources\RegulatoryLegalEntries\Pages\EditRegulatoryLegalEntry;
use App\Filament\Resources\RegulatoryLegalEntries\Pages\ListRegulatoryLegalEntries;
use App\Models\RegulatoryLegalEntry;
use App\Services\Dossiers\RegulatoryLegalEntryReviewer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class RegulatoryLegalEntryResource extends Resource
{
    protected static ?string $model = RegulatoryLegalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Scale;

    protected static ?int $navigationSort = 66;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'legal entry';
    }

    public static function getPluralModelLabel(): string
    {
        return 'EU annex legal entries';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Entry')
                ->description('Transcribe from the consolidated EUR-Lex text. CosIng is a discovery aid only. Reviewed entries are frozen.')
                ->icon(Heroicon::Scale)
                ->schema([
                    Select::make('regulatory_regime_id')
                        ->relationship(name: 'regulatoryRegime', titleAttribute: 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('annex')
                        ->options(collect(LegalAnnex::cases())->mapWithKeys(fn (LegalAnnex $annex): array => [$annex->value => "Annex {$annex->value}"])->all())
                        ->required(),
                    TextInput::make('entry_reference')->label('Entry (e.g. III/84)')->required()->maxLength(32),
                    TextInput::make('clause')->maxLength(32),
                    Textarea::make('substance_description')->label('Substance as named in the annex')->rows(2)->required()->columnSpanFull(),
                    Select::make('substances')
                        ->label('Linked substances')
                        ->relationship(name: 'substances', titleAttribute: 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
            Section::make('Conditions')
                ->schema([
                    Select::make('product_scope')
                        ->options([
                            LegalEntryProductScope::AllProducts->value => 'All products',
                            LegalEntryProductScope::RinseOffOnly->value => 'Rinse-off only',
                            LegalEntryProductScope::LeaveOnOnly->value => 'Leave-on only',
                            LegalEntryProductScope::SeeConditions->value => 'See product type / body parts',
                        ])
                        ->required(),
                    TextInput::make('max_concentration_percent')->label('Max in ready-for-use product (%)')->numeric(),
                    Textarea::make('product_type_body_parts')->label('Product type, body parts (column a)')->rows(2)->columnSpanFull(),
                    Textarea::make('other_limitations')->label('Other (column c)')->rows(2)->columnSpanFull(),
                    Textarea::make('label_warnings_fr')->label('Mandatory label warnings, French (column d)')->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
            Section::make('Dates and source')
                ->schema([
                    DatePicker::make('effective_from'),
                    DatePicker::make('effective_until'),
                    DatePicker::make('placing_on_market_until')->label('Placing on market until'),
                    DatePicker::make('making_available_until')->label('Making available until'),
                    Textarea::make('source_reference')->required()->rows(2)->columnSpanFull(),
                ])
                ->columns(['md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['regulatoryRegime', 'substances']))
            ->columns([
                TextColumn::make('entry_reference')->label('Entry')->searchable()->sortable(),
                TextColumn::make('substance_description')->label('Substance')->limit(40)->searchable(),
                TextColumn::make('substances.name')->label('Linked')->badge(),
                TextColumn::make('max_concentration_percent')->label('Max %')->placeholder('—'),
                TextColumn::make('review_status')->badge(),
                TextColumn::make('effective_until')->date()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('review_status')->options(['draft' => 'Draft', 'reviewed' => 'Reviewed']),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (RegulatoryLegalEntry $record): bool => ! $record->isReviewed()),
                Action::make('markReviewed')
                    ->label('Mark reviewed')
                    ->requiresConfirmation()
                    ->visible(fn (RegulatoryLegalEntry $record): bool => ! $record->isReviewed())
                    ->action(function (RegulatoryLegalEntry $record): void {
                        try {
                            app(RegulatoryLegalEntryReviewer::class)->markReviewed(auth()->user(), $record);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Entry is incomplete')
                                ->body(collect($exception->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('setEffectiveUntil')
                    ->label('Set end date')
                    ->visible(fn (RegulatoryLegalEntry $record): bool => $record->isReviewed())
                    ->schema([DatePicker::make('effective_until')->required()])
                    ->action(fn (RegulatoryLegalEntry $record, array $data) => $record->update(['effective_until' => $data['effective_until']])),
            ])
            ->defaultSort('entry_reference');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegulatoryLegalEntries::route('/'),
            'create' => CreateRegulatoryLegalEntry::route('/create'),
            'edit' => EditRegulatoryLegalEntry::route('/{record}/edit'),
        ];
    }
}
```

`app/Filament/Resources/RegulatoryLegalEntries/Pages/ListRegulatoryLegalEntries.php`:

```php
<?php

namespace App\Filament\Resources\RegulatoryLegalEntries\Pages;

use App\Filament\Resources\RegulatoryLegalEntries\RegulatoryLegalEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegulatoryLegalEntries extends ListRecords
{
    protected static string $resource = RegulatoryLegalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

`app/Filament/Resources/RegulatoryLegalEntries/Pages/CreateRegulatoryLegalEntry.php`:

```php
<?php

namespace App\Filament\Resources\RegulatoryLegalEntries\Pages;

use App\Filament\Resources\RegulatoryLegalEntries\RegulatoryLegalEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRegulatoryLegalEntry extends CreateRecord
{
    protected static string $resource = RegulatoryLegalEntryResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'review_status' => 'draft'];
    }
}
```

`app/Filament/Resources/RegulatoryLegalEntries/Pages/EditRegulatoryLegalEntry.php`:

```php
<?php

namespace App\Filament\Resources\RegulatoryLegalEntries\Pages;

use App\Filament\Resources\RegulatoryLegalEntries\RegulatoryLegalEntryResource;
use App\Models\RegulatoryLegalEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditRegulatoryLegalEntry extends EditRecord
{
    protected static string $resource = RegulatoryLegalEntryResource::class;

    protected function beforeSave(): void
    {
        /** @var RegulatoryLegalEntry $record */
        $record = $this->getRecord();

        if ($record->isReviewed()) {
            Notification::make()->title('Reviewed entries are frozen')->body('End this entry and add a new one for an amendment.')->warning()->send();
            $this->halt();
        }
    }

    protected function getFormActions(): array
    {
        /** @var RegulatoryLegalEntry $record */
        $record = $this->getRecord();

        return $record->isReviewed() ? [] : parent::getFormActions();
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/Filament/RegulatoryLegalEntryResourceTest.php tests/Feature/Dossiers/RegulatoryLegalEntryTest.php`
Expected: PASS.

- [ ] **Step 5: Filacheck, format, commit**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
git add app/Filament/Resources/RegulatoryLegalEntries tests/Feature/Filament/RegulatoryLegalEntryResourceTest.php
git commit -m "Add admin resource for reviewing EU annex legal entries"
```

---

### Task 13: Phase verification

- [ ] **Step 1: Run every phase test**

Run: `php artisan test --compact tests/Feature/Dossiers tests/Feature/Filament/WorkspaceDossierGrantResourceTest.php tests/Feature/Filament/ToxicologyProfileResourceTest.php tests/Feature/Filament/AssessmentParameterResourcesTest.php tests/Feature/Filament/MaterialAssessmentCoverageResourceTest.php tests/Feature/Filament/RegulatoryLegalEntryResourceTest.php`
Expected: all PASS.

- [ ] **Step 2: Run the neighbouring suites this phase touches**

Run: `php artisan test --compact tests/Feature/WorkspaceCapabilitiesTest.php tests/Feature/SettingsSecurityTest.php tests/Feature/SettingsLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/SubstanceComplianceTest.php`
Expected: all PASS.

- [ ] **Step 3: Check the migrations roll back**

Run: `php artisan migrate:rollback --step=6 --no-interaction && php artisan migrate --no-interaction`
Expected: both succeed.

- [ ] **Step 4: Static checks and graph**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --format agent
graphify update .
```

- [ ] **Step 5: Manual check in the browser**

Open the admin panel: the "Product dossiers" group lists DIP module grants, toxicological profiles, assessment policies, exposure presets, QRA2 parameters, assessment coverage and EU annex legal entries. Grant the module to your workspace, then open Settings in the app: the « Dossiers produit (DIP) » tab appears for the Owner only, and saving the Responsible Person works.

- [ ] **Step 6: Ask for the full suite**

Ask the owner to run `php artisan test --compact` (UTF-8 locale) and report the result before phase 2 planning.
