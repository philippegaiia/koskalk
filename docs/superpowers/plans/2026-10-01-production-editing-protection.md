# Production Editing Protection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Default to inline execution in this conversation; delegate only after the user selects that option.

**Goal:** Protect existing productions and their stock preparation against conflicting edits, while leaving reading and other productions available.

**Architecture:** Production-specific leases and mounted revisions protect persistent detail/preparation editors and temporary register commands. A server mutation guard validates ownership, fresh authority and revisions before existing domain operations, then acknowledges only committed changes. Reuse `WorkspaceWriteLock` and the tested Recipe Bench departure behavior, without introducing a generic lease framework or changing formula-sharing isolation.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, Alpine, PostgreSQL, SQLite feature tests, Pest 4 and the existing Node test tooling.

---

## Approved scope and execution constraints

Design: `docs/superpowers/specs/2026-10-01-production-editing-protection-design.md`, approved 2026-10-01. Planning baseline: `cd73d777`, after sharing integration `4e34e899`. Refresh the current checkout before execution; never reset it to this recorded baseline.

- Work on **main**, as requested. Preserve unrelated memory/export-plan files and other agents' changes. Stage only the files belonging to a completed task.
- No application implementation, local build, database mutation, push or deployment occurs while writing this plan. Subsequent implementation also retains the user's **no build** preference.
- One production reservation covers its tasks, journal and linked output commands. Reading, calendar browsing and different productions remain available.
- Explicit **Edit production** / **Edit allocations**, 90-second expiry, 15-second polling, matching-token departure release, explicit waiting-editor recovery, Owner/Admin audited takeover.
- Purchasing, general inventory mutation and settings are outside scope. Their physical stock effects still participate in existing confirmation checks.
- Complete the guard rollout and all callers before releasing this feature. Intermediate commits are checkpoints, not deployable partial features.
- Read `.ai/rules/index.md`, matching rules and keyword matches before each implementation area. Apply Laravel, testing, schema, frontend and media skills when entering those domains. Do not add dependencies or browser-test packages.
- Inspect the current live structure with Truss before creating the additive migration. Execute migration round trips only against test-owned databases. Do not reset the working database.

## File boundaries

| File(s) | Responsibility |
| --- | --- |
| `database/migrations/2026_10_01_120000_add_production_editing_protection.php` | Add revision, lease and takeover audit storage; reverse without damaging production integrity objects. Generate with Artisan, then normalize the generated filename to this planned path. |
| `app/Models/ProductionRun.php` | Integer revision cast; revision remains server-owned. Lease/audit tables follow Recipe Bench's query-builder convention and need no unused Eloquent models. |
| `app/Services/ProductionEditingService.php` | Status, group acquisition, heartbeat, matching release, takeover and root locking. |
| `app/Services/Production/ProductionEditingContext.php` | Native PHP command context with mounted revisions and tab/temporary ownership; never a browser-controlled capability flag. |
| `app/Services/Production/ProductionMutationResult.php` | Existing domain result plus exact changed production IDs. |
| `app/Services/Production/ProductionMutationScope.php` | Short-lived proof passed to internal task/actuals helpers already enclosed by one authorized command. |
| `app/Services/ProductionMutationGuard.php` | Required context, atomic validation, one revision increment per changed production, exact acknowledgement. |
| `app/Http/Requests/ReleaseProductionEditingRequest.php`, `app/Http/Controllers/ProductionEditingController.php`, `routes/web.php` | Authenticated bounded departure release. |
| `app/Livewire/Concerns/InteractsWithProductionEditing.php` | Locked mount state; status/acquisition/recovery and acknowledgements. |
| `app/Livewire/ProductionBench/Production/{ProductionDetail,StockPreparation,ProductionIndex,TaskIndex}.php` | Page-specific form snapshots and guarded callers. |
| `resources/js/production-editing.js`, `resources/js/production-editing-draft.js`, `resources/js/app.js` | One request queue per component, pending input and departure lifecycle. |
| `resources/views/components/production-bench/editing-status.blade.php` | Compact status, edit/finish/resume/reload and manager takeover controls. |
| `resources/views/livewire/production-bench/production/{production-detail,stock-preparation,production-index,task-index}.blade.php` | Attach coordinator, preserve reads, route writes through the queue. |
| `app/Actions/Production/**`, `app/Services/Production/ProductionCompletionService.php` | Only the existing-production commands listed in Tasks 4–6; preserve domain calculations. |
| `app/Actions/Inventory/{AttachProductionDocument,DetachProductionDocument}.php` | Protect the production branch while retaining receipt behavior. |
| `app/Console/Commands/BackfillProductionFormulaSnapshots.php` | Report busy maintenance skips separately. |
| `lang/en/production_bench.php`, `database/seeders/data/interface-translations.json` | New dotted keys and all six catalogue locales; existing wildcard registration already covers this group. |
| `tests/Support/ProductionEditingFixture.php` | Small test fixture and explicit lease/context setup. |
| `tests/Feature/ProductionEditing{Migration,Service,Release,Mutation,Detail,StockPreparation,Registers,Documents,Maintenance,Client,PostgresConcurrency}Test.php` | New behavior and failure modes, plus affected existing regression files. |
| `tests/Unit/production-editing.test.mjs`, `tests/Unit/production-editing-draft.test.mjs` | Deterministic client state and lifecycle tests, run through Node and a Pest process bridge. |

Use `php85 artisan make:class`, `make:controller`, `make:request`, `make:test --pest`, and `make:migration`, all with `--no-interaction`. Inspect command help before generating nested classes. JS modules and Blade views use normal file edits. Each test file declares `uses(RefreshDatabase::class)` unless it is an explicitly guarded PostgreSQL concurrency file.

## Contracts used throughout the tasks

### Status response

```php
[
    'status' => 'available', // acquired, blocked, stale, unavailable
    'can_edit' => true,
    'can_take_over' => true,
    'release_url' => route('production-bench.production.editing.release'),
    'productions' => [
        42 => [
            'public_id' => 'a-production-uuid',
            'revision' => 7,
            'status' => 'available',
            'holder_name' => null,
            'expires_at' => null,
        ],
    ],
]
```

`productions` contains only authorized mounted records. Missing records become `unavailable`; mixed-workspace input fails without leaking another company's record. Status is read-only. The server-current revisions in this response are **observations**, not permission to replace the mounted baseline. Acquisition receives the mounted revision map and writes no lease unless the entire group passes. Group takeover also checks all revisions before changing any lease or audit row.

### Native editing context

Implement this complete context in Task 3. It keeps acknowledgements separate from immutable submitted revisions and avoids fetching a later writer's revision after saving:

```php
namespace App\Services\Production;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProductionEditingContext
{
    /** @var array<int, int|null> */
    private array $acknowledgedRevisions = [];

    /** @param array<int, int> $expectedRevisions */
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $token,
        public readonly array $expectedRevisions,
        public readonly bool $temporary = false,
    ) {
        if (! Str::isUuid($token) || count($expectedRevisions) < 1 || count($expectedRevisions) > 100) {
            throw ValidationException::withMessages(['production_editing' => __('production_bench.editing.validation.selection')]);
        }
        foreach ($expectedRevisions as $id => $revision) {
            if (! is_int($id) || $id < 1 || ! is_int($revision) || $revision < 0) {
                throw ValidationException::withMessages(['production_editing' => __('production_bench.editing.validation.selection')]);
            }
        }
    }

    /** @param array<int, int|null> $revisions */
    public function acknowledge(array $revisions): void
    {
        $this->acknowledgedRevisions = $revisions;
    }

    /** @return array<int, int|null> */
    public function acknowledgedRevisions(): array
    {
        return $this->acknowledgedRevisions;
    }
}
```

Public Actions may append `?ProductionEditingContext $editing = null` to preserve existing optional argument order. The guard **rejects null**. This is a migration-friendly signature, not an unguarded fallback. Only native PHP callers construct contexts; Livewire uses Locked original workspace, IDs, token and expected revisions, never a submitted `temporary`, `trusted`, `workspace_id` or revision argument.

### Existing command outcome

```php
namespace App\Services\Production;

final readonly class ProductionMutationResult
{
    /** @param list<int> $changedProductionIds */
    public function __construct(public mixed $value, public array $changedProductionIds) {}
}
```

The guard returns `value`, preserving existing public Action return types. The native context receives the committed revision map. Each callback explicitly names changed IDs, including child-only task/journal/output changes. A deleted row is acknowledged with `null`; a no-op retains its prior revision.

## Task 1: Add storage and a small production editing fixture

**Files:** migration above; `app/Models/ProductionRun.php`; `tests/Support/ProductionEditingFixture.php`; `tests/Feature/ProductionEditingMigrationTest.php`. Existing regression: `ProductionRunNumberStorageTest.php`, `ProductionPlanningSchemaTest.php`, `ProductionTaskSchemaTest.php`.

- [x] **Step 1: Inspect structure and current factory conventions.**

Run `php85 artisan truss:export --format=llm --focus=production_runs --depth=1 --compact` and `php85 artisan truss:doctor`. Record relevant findings in the task notes; do not repair unrelated findings. Read the existing recipe-editing migration/round-trip test and production numbering integrity tests.

- [x] **Step 2: Add the fixture and failing round-trip test.**

The fixture is a `Tests\Support\ProductionEditingFixture` class with this complete factory method and constructor:

```php
public function __construct(public readonly \App\Models\User $owner, public readonly \App\Models\Workspace $workspace) {}

public static function create(): self
{
    $owner = \App\Models\User::factory()->create();
    $workspace = \App\Models\Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $plan = \App\Models\Plan::factory()->create(['allows_collaboration' => true]);
    $owner->entitlements()->create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subMinute()]);
    \App\Models\WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    return new self($owner, $workspace);
}
```

Round-trip test: create a production, task, requirement and numbering issuance using the existing numbering fixture; capture persisted rows excluding `edit_revision`, and SQLite trigger/partial-index SQL or PostgreSQL equivalent catalogue definitions. Require the exact new migration, run `down()` then `up()`, assert identical rows and integrity objects, revision 0, and both new tables. Add a second test that deleting a production cleans its lease/audit without deleting the user's independent production. Use existing `RecipeEditingMigrationTest.php` snapshot logic with table list `production_runs`, `production_requirements`, `production_tasks`, `production_run_number_issuances`.

```php
it('preserves a production through an editing migration round trip', function (): void {
    $fixture = \Tests\Support\ProductionEditingFixture::create();
    $production = \App\Models\ProductionRun::factory()->for($fixture->workspace)->create(['notes' => 'Keep history']);
    $before = collect($production->getRawOriginal())->except('edit_revision')->all();
    $migration = require database_path('migrations/2026_10_01_120000_add_production_editing_protection.php');
    $migration->down();
    expect(\Illuminate\Support\Facades\Schema::hasColumn('production_runs', 'edit_revision'))->toBeFalse();
    $migration->up();
    $after = collect($production->fresh()->getRawOriginal())->except('edit_revision')->all();
    expect($after)->toBe($before)->and($production->fresh()->edit_revision)->toBe(0);
});
```

Run `php85 -d memory_limit=2G vendor/bin/pest tests/Feature/ProductionEditingMigrationTest.php`. Expected RED: missing migration/storage/cast, not an unrelated fixture error.

- [x] **Step 3: Generate and implement the additive migration.**

Add `unsignedBigInteger('edit_revision')->default(0)` to `production_runs`. Add `edit_revision => integer` to its existing `casts()` method without making the property browser-fillable. Create storage with the following exact schemas:

```php
Schema::create('production_edit_leases', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('production_run_id')->unique()->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
    $table->string('token_hash', 64);
    $table->string('holder_name');
    $table->timestamp('expires_at');
    $table->timestamps();
});
Schema::create('production_edit_takeovers', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('production_run_id')->index()->constrained()->cascadeOnDelete();
    $table->foreignId('actor_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignId('previous_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->string('actor_name');
    $table->string('previous_holder_name')->nullable();
    $table->text('reason');
    $table->timestamp('created_at');
});
```

`down()` drops takeover then lease tables, then only `production_runs.edit_revision`. Use native compatible SQLite `ALTER TABLE production_runs DROP COLUMN edit_revision` if Schema's rebuild loses its existing triggers/indexes; PostgreSQL uses the normal Blueprint drop. The round-trip integrity assertions decide this, not assumption. Do not modify old deployed migrations.

- [ ] **Step 4: Verify and checkpoint.** Run the new migration test and three schema/numbering regressions, then `php85 vendor/bin/pint --dirty --format agent`. Expected all PASS and no changed historical quantities or integrity objects. Commit only this task's files with `feat: add production editing storage`.

## Task 2: Implement production lease ownership and atomic groups

**Files:** `app/Services/ProductionEditingService.php`; `tests/Feature/ProductionEditingServiceTest.php`. Read `RecipeEditingService`, `WorkspaceWriteLock`, `WorkspaceAuthorization`, `ProductionBenchAccess` and merged services rules before coding.

- [x] **Step 1: Add behavioral tests before the service.**

```php
it('reads without reserving and blocks a genuine second owner tab', function (): void {
    $fixture = \Tests\Support\ProductionEditingFixture::create();
    $run = \App\Models\ProductionRun::factory()->for($fixture->workspace)->create();
    $service = app(\App\Services\ProductionEditingService::class);
    $token = (string) \Illuminate\Support\Str::uuid();
    expect($service->status($fixture->owner, $fixture->workspace->id, [$run->id])['status'])->toBe('available');
    $this->assertDatabaseCount('production_edit_leases', 0);
    expect($service->acquire($fixture->owner, $fixture->workspace->id, [$run->id => 0], $token)['status'])->toBe('acquired');
    expect($service->acquire($fixture->owner, $fixture->workspace->id, [$run->id => 0], (string) \Illuminate\Support\Str::uuid())['status'])->toBe('blocked');
    expect($run->fresh()->edit_revision)->toBe(0);
});

it('acquires none of a group when one production is held', function (): void {
    $fixture = \Tests\Support\ProductionEditingFixture::create();
    [$first, $second] = \App\Models\ProductionRun::factory()->for($fixture->workspace)->count(2)->create()->all();
    $service = app(\App\Services\ProductionEditingService::class);
    $service->acquire($fixture->owner, $fixture->workspace->id, [$second->id => 0], (string) \Illuminate\Support\Str::uuid());
    $result = $service->acquire($fixture->owner, $fixture->workspace->id, [$first->id => 0, $second->id => 0], (string) \Illuminate\Support\Str::uuid());
    expect($result['status'])->toBe('blocked');
    $this->assertDatabaseMissing('production_edit_leases', ['production_run_id' => $first->id]);
    $this->assertDatabaseCount('production_edit_leases', 1);
});
```

Add named datasets for these distinct boundaries: Owner/Admin/Editor acquire, Viewer denied; only Owner/Admin takeover with trimmed nonempty reason ≤1000; live role downgrade/removal, active-workspace change and owner collaboration expiry; cancelled/inactive Bench; stale/missing/mixed-workspace group and 0/100/101 selections; valid UUID versus malformed token. Freeze time for renewal at 80 seconds, expiry at 90, heartbeat refusing expired ownership, and reacquisition only against matching revisions. Release with a wrong token/user changes nothing; repeated matching release succeeds; delayed old-token release preserves a takeover. Release after downgrade to readable Viewer or Bench cancellation remains permitted, revoked reading does not.

Run the service test; expected RED for missing service.

- [x] **Step 2: Implement the service API and lock boundary.**

Use these exact public methods and return the status contract above:

```php
public const int LeaseSeconds = 90;
public const int MaximumGroupSize = 100;

public function status(User $actor, int $workspaceId, array $productionIds, ?string $token = null): array;
public function acquire(User $actor, int $workspaceId, array $expectedRevisions, string $token): array;
public function heartbeat(User $actor, int $workspaceId, array $expectedRevisions, string $token): array;
public function takeover(User $actor, int $workspaceId, array $expectedRevisions, string $token, string $reason): array;
public function release(User $actor, int $workspaceId, array $productionIds, string $token): void;
public function withLocked(User $actor, int $workspaceId, array $productionIds, Closure $callback, bool $writable = true): mixed;
public function assertLease(ProductionRun $production, User $actor, string $token): void;
public function assertRevision(ProductionRun $production, int $expectedRevision): void;
public function isActivelyReserved(ProductionRun $production): bool;
```

Inject `WorkspaceWriteLock` and `ProductionBenchAccess`. `withLocked()` is the common retrying root transaction; this is its implementation body:

```php
$ids = collect($productionIds)->map(fn (int $id): int => $id)->unique()->sort()->values()->all();
validator(['ids' => $ids], ['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'min:1']])->validate();

return DB::transaction(function () use ($actor, $workspaceId, $ids, $callback, $writable): mixed {
    $freshActor = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($actor->id);
    $workspace = $this->workspaceLock->acquire($workspaceId);
    WorkspaceMember::withoutGlobalScopes()->where('user_id', $freshActor->id)
        ->where('workspace_id', $workspaceId)->lockForUpdate()->get();
    if ($writable) {
        $this->access->assertWritable($freshActor, $workspace);
    } else {
        $this->access->assertReadable($freshActor, $workspace);
    }
    $productions = ProductionRun::query()->where('workspace_id', $workspaceId)
        ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    if ($productions->count() !== count($ids)) {
        throw ValidationException::withMessages(['production_editing' => __('production_bench.editing.validation.unavailable')]);
    }

    return $callback($freshActor, $workspace, $productions);
}, attempts: 5);
```

Validate input types before the typed collection mapping. `status()` does not call this write-lock helper: refresh actor/workspace, assert readable, query bounded records/leases and return observations without renewing anything. Acquisition and takeover perform a **complete validation pass before any writes**; return blocked/stale/unavailable group state without partial ownership. Heartbeat uses a complete assert pass and then renews all; it never revives an expired lease. Takeover additionally calls `assertCanConfigure()` and records previous-holder audit for each replaced lease.

Use Recipe Bench's active/owns/hash behavior with `production_run_id` and `hash_equals()`. Write lease expiry with `now()->addSeconds(self::LeaseSeconds)` and preserve `created_at` on renewal. A release passes `writable: false` to `withLocked()` and deletes only `production_run_id + user_id + token_hash`. For deleted IDs on departure, release resolves only surviving records and makes an empty surviving set a successful no-op; it must not fail all surviving releases because another selected production was deleted. No business revision changes for lease operations. Include the production's frozen name and planning/permanent reference in authorized group state so the status component can name affected records without showing raw database IDs.

- [ ] **Step 3: Verify and checkpoint.** Run `ProductionEditingServiceTest.php`, `ProductionWorkspaceAuthorizationTest.php` and `ProductionBenchEntitlementTest.php`; expect PASS. Pint and commit `feat: add production editing reservations`.

## Task 3: Require a mutation context and produce exact revision acknowledgements

**Files:** context/result/scope classes from the contracts; `app/Services/ProductionMutationGuard.php`; `tests/Feature/ProductionEditingMutationTest.php`.

- [x] **Step 1: Prove rollback, stale rejection and no-op behavior.**

Add this complete `lease()` method to the fixture. Do not auto-refresh a context before a tested stale write.

```php
public function lease(\App\Models\ProductionRun $production, ?\App\Models\User $actor = null): \App\Services\Production\ProductionEditingContext
{
    $actor ??= $this->owner;
    $token = (string) \Illuminate\Support\Str::uuid();
    $revisions = [$production->id => (int) $production->fresh()->edit_revision];
    $state = app(\App\Services\ProductionEditingService::class)->acquire($actor, $this->workspace->id, $revisions, $token);
    if ($state['status'] !== 'acquired') {
        throw new \LogicException('Test fixture could not acquire its explicit production lease.');
    }

    return new \App\Services\Production\ProductionEditingContext($this->workspace->id, $token, $revisions);
}
```

```php
it('acknowledges its committed revision and rejects the same old draft', function (): void {
    $fixture = \Tests\Support\ProductionEditingFixture::create();
    $run = \App\Models\ProductionRun::factory()->for($fixture->workspace)->create(['notes' => 'Original']);
    $context = $fixture->lease($run);
    $guard = app(\App\Services\ProductionMutationGuard::class);
    $guard->run($fixture->owner, [$run->id], $context, function ($actor, $workspace, $productions, $scope) use ($run) {
        $productions[$run->id]->update(['notes' => 'Saved']);
        return new \App\Services\Production\ProductionMutationResult(null, [$run->id]);
    });
    expect($context->acknowledgedRevisions())->toBe([$run->id => 1]);
    expect(fn () => $guard->run($fixture->owner, [$run->id], $context, fn () => new \App\Services\Production\ProductionMutationResult(null, [])))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($run->fresh()->notes)->toBe('Saved')->and($run->fresh()->edit_revision)->toBe(1);
});
```

Additional independent tests: null context rejected with no callback; wrong context ID/workspace; child-only command increments parent; unchanged callback retains revision; two changed IDs increment once each; one stale or blocked group rolls back all; exception after a child insert rolls back both insert and revision; lease expiry during callback rolls back; successful delete yields `null` acknowledgement; temporary command leaves no lease on success/failure and refuses another page's active lease.

Run the mutation test; expected RED for missing guard/context.

- [x] **Step 2: Implement the native context/result, scope and guard.**

Use this complete scope class. It never appears in public Livewire properties, HTTP input or serialized replies. Existing internal helpers require this object instead of optional booleans or ambient globals. It proves an enclosing root command and does not increment revisions itself.

```php
namespace App\Services\Production;

use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ProductionMutationScope
{
    private bool $active = true;

    /** @param list<int> $productionIds */
    private function __construct(
        private readonly int $actorId,
        private readonly int $workspaceId,
        private readonly array $productionIds,
    ) {}

    /** @param Collection<int, ProductionRun> $productions */
    public static function within(User $actor, Workspace $workspace, Collection $productions, Closure $callback): mixed
    {
        if (DB::transactionLevel() < 1 || $productions->isEmpty()
            || $productions->contains(fn (ProductionRun $run): bool => (int) $run->workspace_id !== $workspace->id)) {
            throw new LogicException('Production scope requires an enclosing transaction and one workspace.');
        }
        $scope = new self($actor->id, $workspace->id, $productions->pluck('id')->all());
        try {
            return $callback($scope);
        } finally {
            $scope->active = false;
        }
    }

    public static function withinCreated(User $actor, Workspace $workspace, ProductionRun $production, Closure $callback): mixed
    {
        if (! $production->wasRecentlyCreated) {
            throw new LogicException('Creation scope cannot initialize an existing production.');
        }

        return self::within($actor, $workspace, collect([$production]), $callback);
    }

    public function assertFor(User $actor, ProductionRun $production, Workspace $workspace): void
    {
        if (! $this->active || DB::transactionLevel() < 1 || $actor->id !== $this->actorId
            || $workspace->id !== $this->workspaceId || (int) $production->workspace_id !== $workspace->id
            || ! in_array($production->id, $this->productionIds, true)) {
            throw new LogicException('Production helper has no active command scope for this record.');
        }
    }
}
```

These factories are internal native PHP capability construction. No public component method accepts a scope or creates one from request flags. The guard/creation command must already hold root locks and fresh authorization before constructing it. Add a test retaining a scope past the callback and proving that the internal helper refuses it.

The guard exposes `run(User $actor, array $productionIds, ?ProductionEditingContext $editing, Closure $callback): mixed`. Reject missing context before executing callbacks. Normalize/sort IDs and require exact equality with the context's revision keys, then enter service `withLocked()`. Keep each Action's fresh initial authority check before reporting lease/revision details; existing refused-role tests must continue to receive authorization refusal rather than a capability-information leak.

Inside the root transaction, run these phases in order:

```php
foreach ($productions as $production) {
    $this->editing->assertRevision($production, $editing->expectedRevisions[$production->id]);
    if (! $editing->temporary) {
        $this->editing->assertLease($production, $freshActor, $editing->token);
    } elseif ($this->editing->isActivelyReserved($production)) {
        throw ValidationException::withMessages(['production_editing' => __('production_bench.editing.validation.busy')]);
    }
}
if ($editing->temporary) {
    $this->editing->acquire($freshActor, $workspace->id, $editing->expectedRevisions, $editing->token);
}
$result = ProductionMutationScope::within($freshActor, $workspace, $productions,
    fn (ProductionMutationScope $scope): ProductionMutationResult => $callback($freshActor, $workspace, $productions, $scope));
if (collect($result->changedProductionIds)->diff($productions->keys())->isNotEmpty()) {
    throw new \LogicException('A production command changed an unguarded production.');
}
$acknowledged = [];
foreach ($productions as $production) {
    $current = ProductionRun::query()->whereKey($production->id)->first();
    if ($current === null) {
        $acknowledged[$production->id] = null;
        continue;
    }
    $this->editing->assertLease($current, $freshActor, $editing->token);
    if ((int) $current->edit_revision !== $editing->expectedRevisions[$current->id]) {
        throw new \LogicException('A nested production helper advanced the root command revision.');
    }
    if (in_array($production->id, $result->changedProductionIds, true)) {
        $current->increment('edit_revision');
    }
    $acknowledged[$production->id] = (int) $current->edit_revision;
}
if ($editing->temporary) {
    $this->editing->release($freshActor, $workspace->id, $productions->keys()->all(), $editing->token);
}
return [$result->value, $acknowledged];
```

The final `[$value, $acknowledged]` is an internal transaction return. Only **after** `withLocked()` succeeds, call `$editing->acknowledge($acknowledged)` and return `$value`. Do not record acknowledgements inside a callback that can retry or roll back. If the callback value is a surviving `ProductionRun`, set its `edit_revision` from `$acknowledged[$value->id]` while still inside the transaction; its loaded domain snapshot already belongs to this command. Do not refresh that result outside the transaction and accidentally include a later writer's values. A nested helper cannot advance the root command's revision independently; the explicit check above detects that error.

Temporary acquisition inside this transaction may reuse the service's root lock helper, but must check its result and throw if not acquired; nested Laravel retries do not replace the outer retry boundary. Keep all domain work inside the outer guard callback and no irreversible side effects there.

- [ ] **Step 3: Verify and checkpoint.** Run new guard/service tests; expect PASS for each failure mode and no leaked temporary leases. Pint and commit `feat: guard production mutations with mounted revisions`.

## Task 4: Guard production lifecycle, actuals, planning, stock and numbering commands

**Files:** exact list below; `tests/Feature/ProductionEditingMutationTest.php`; affected existing production tests.

| File under `app/Actions/Production/` | Root command / change signal |
| --- | --- |
| `SaveProductionActuals.php` | Consumption and calculated actuals; compare canonical persisted rows before replacing. Identical rows are a no-op. |
| `ScheduleProduction.php` | Production schedule plus generated tasks; one parent increment. |
| `RescheduleProduction.php` | Production date plus task dates/ready date; unchanged normalized date is no-op. |
| `AssignProductionLocation.php` | Resolved location comparison before update. |
| `StartProduction.php`, `CancelProduction.php`, `AbortProduction.php` | Existing valid lifecycle change. |
| `CompleteProduction.php` | Pass context into the guarded `ProductionCompletionService::complete()`; do not guard twice. |
| `ReleaseProductionStock.php` | Actual reservation release; retain no-op behavior and stock checks. |
| `PrepareProductionStock.php` | Complete exact selected group; changed IDs derive from new preparation effects, not merely selection. |
| `AssignProductionBatchNumbers.php` | Only newly numbered eligible IDs change; already numbered rows do not bump. |
| `DeleteProductionRun.php` | Same manager/draft restrictions; deleted acknowledgement and FK cleanup. |
| `UpdateProductionPlan.php` | Plan and linked requirement/task changes; compare authored canonical inputs. |
| `GenerateProductionTasks.php` | Direct existing-record handle requires guard; scoped helper only runs within a proved root command or creation scope. |
| `PlanProduction.php`, `CreateProductionDraft.php` | Keep creation idempotency; pass explicit creation scope to internal task generation. |

Also modify `app/Services/Production/ProductionCompletionService.php` and `ProductionRunNumberService.php` where workspace locking is centralized.

- [x] **Step 1: Add one stale/blocked entry test per command family.**

For each Action, extend its existing realistic fixture with an explicit editing context. Use that fixture's valid command input, advance the production revision through a competing temporary guard command, then submit the original context. Assert `production_editing` or `production_revision` errors and unchanged production/child/stock/accounting rows. Include direct `ProductionCompletionService::complete()` and `GenerateProductionTasks::handle()` so a service call cannot bypass protection. Missing-context calls must fail too. Existing authorization tests keep their asserted role refusal; check fresh authority before revealing lease/revision state.

```php
it('blocks cancellation from a second tab without changing cancellation evidence', function (): void {
    $fixture = \Tests\Support\ProductionEditingFixture::create();
    $run = \App\Models\ProductionRun::factory()->for($fixture->workspace)->create();
    $fixture->lease($run);
    $other = new \App\Services\Production\ProductionEditingContext(
        $fixture->workspace->id, (string) \Illuminate\Support\Str::uuid(), [$run->id => 0], temporary: true);
    expect(fn () => app(\App\Actions\Production\CancelProduction::class)->handle($fixture->owner, $run, 'Postponed', editing: $other))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($run->fresh()->cancellation_reason)->toBeNull()->and($run->fresh()->edit_revision)->toBe(0);
});
```

Run the new entry tests and their containing regression file; expected RED because old Actions ignore/reject the editing argument or still write.

- [x] **Step 2: Move the existing domain transaction body beneath the guard.**

Append context to `handle()`; inject guard; replace the outer `DB::transaction` with guard `run()`. Use the supplied fresh actor/workspace/locked production rather than a stale passed model or reversed lock acquisition. Preserve existing validation and bcmath calculations. For a callback returning existing value, wrap that value in `ProductionMutationResult` and report only actually changed IDs.

This is the complete `AssignProductionLocation::handle()` replacement, establishing the change/no-op convention:

```php
public function handle(User $actor, ProductionRun $production, ?int $locationId, ?ProductionEditingContext $editing = null): ProductionRun
{
    return $this->guard->run($actor, [$production->id], $editing,
        function (User $freshActor, Workspace $workspace, Collection $productions, ProductionMutationScope $scope) use ($production, $locationId): ProductionMutationResult {
            $current = $productions[$production->id];
            if (! $workspace->uses_production_locations || ! in_array($current->status, [ProductionRunStatus::Draft, ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved], true)) {
                throw ValidationException::withMessages(['production_location_id' => __('locations.validation.production_assignment')]);
            }
            $resolved = $this->locations->resolve($workspace, $locationId);
            $changed = $current->production_location_id !== $resolved;
            if ($changed) {
                $current->update(['production_location_id' => $resolved]);
            }

            return new ProductionMutationResult($current->fresh(), $changed ? [$current->id] : []);
        });
}
```

Add imports for the context/result/scope, `Collection` and guard; keep existing constructor-injected location/access dependencies. Other domain bodies remain in their current files, using the matrix's explicit change signal. Do not use `updated_at` alone to detect child changes.

For internal task generation and default calculated actuals, add required `ProductionMutationScope $scope`; first call `$scope->assertFor($actor, $lockedProduction, $lockedWorkspace)`. Existing `ScheduleProduction` passes its guard-created scope. Creation uses a distinct `withinCreated()` scope factory: require an open transaction and a freshly created production from that transaction; existing idempotent results return without rerunning initialization. If an existing creation retry needs missing initialization repaired, route that repair through guarded maintenance, never an unrestricted old-record helper. Reject retained/inactive scopes after the callback finishes.

Replace guarded workspace lock calls with `WorkspaceWriteLock`; remove task/production-before-workspace root locks. Number settings, stock lots and children are locked after the already locked production set. No transaction isolation change. Preserve idempotency: a preparation replay returns its recorded result without new stock effects, and reports no new changed IDs; it must not bypass ownership/revision validation merely because it is a replay.

- [x] **Step 3: Update existing test callers explicitly.** Add context only for guarded existing-production operations. Keep new-record and unrelated inventory/purchasing setup unchanged. After a test's successful write, construct its next context from that command's acknowledged revisions; never silently acquire/rebase inside the Action. Do not delete or weaken existing assertions.

- [ ] **Step 4: Verify and checkpoint.** Run `ProductionEditingMutationTest.php`, `ProductionExecutionTest.php`, `ProductionPlanningTest.php`, `ProductionCalculatedMaterialsTest.php`, `ProductionStockPreparationTest.php`, `ProductionRunBatchNumberingTest.php`, `ProductionRunNumberStorageTest.php`, `ProductionDeleteAuthorizationTest.php`, `ProductionWorkspaceAuthorizationTest.php`. Expect PASS with identical domain/stock results and new revision checks. Pint and commit `feat: protect production lifecycle and stock commands`.

## Task 5: Protect task and linked output writers at their own boundaries

**Files:** `app/Actions/Production/{AssignProductionTask,CompleteProductionTask,ReopenProductionTask,RescheduleProductionTask,ResetProductionTaskDate,ReleaseOutputLot,IssueFinishedGoods}.php`; `ProductionEditingMutationTest.php`; existing task/output execution regressions.

- [x] **Step 1: Add direct-call tests.**

Use task factory with the same workspace and production explicitly. Check a task-only write increments the parent; unchanged assignee/date retains revision; missing/wrong context does not alter the child. A completed production still allows the already supported completion/reopen behavior; assignment retains its existing terminal restriction. For output, create a completed production and linked output lot using the existing execution fixture; verify stale/blocked calls neither change handling status nor add stock movements.

```php
it('advances the production revision for a task completion', function (): void {
    $fixture = \Tests\Support\ProductionEditingFixture::create();
    $run = \App\Models\ProductionRun::factory()->for($fixture->workspace)->create();
    $task = \App\Models\ProductionTask::factory()->for($fixture->workspace)->for($run, 'productionRun')->create();
    $context = $fixture->lease($run);
    app(\App\Actions\Production\CompleteProductionTask::class)->handle($fixture->owner, $task, editing: $context);
    expect($task->fresh()->completed_at)->not->toBeNull()->and($context->acknowledgedRevisions())->toBe([$run->id => 1]);
});
```

Run this test; expected RED for missing guard/revision acknowledgement.

- [x] **Step 2: Resolve the durable production parent and guard before locking children.**

Use this task lookup inside each guarded callback after deriving the hint parent without a lock and requiring its ID in the editing context:

```php
$lockedTask = ProductionTask::query()->where('workspace_id', $workspace->id)
    ->where('production_run_id', $production->id)->lockForUpdate()->findOrFail($task->id);
```

Output uses `StockLot::withoutGlobalScopes()` to resolve its durable `production_run_id`, enters guard for that parent, then locks the lot constrained by **both** workspace and parent. Reject a null/changed link; never trust a passed relation. Retain completed-production/task-readiness, early confirmation, quarantined/released state and availability checks. Issuance records stock/accounting effects exactly as today; its parent revision changes even though the production row's other fields do not.

Callback change reports are explicit:

```php
return new ProductionMutationResult($lockedTask->fresh(), $changed ? [$production->id] : []);
```

Use this for task Actions after computing normalized before/after values. Output transition/issuance uses `[$production->id]` only after successfully recording a real effect. No force takeover is hidden in an Action.

- [ ] **Step 3: Verify and checkpoint.** Run new mutation tests, `ProductionTaskOrganizationTest.php`, `ProductionTaskSchedulingTest.php`, `ProductionExecutionTest.php`, `ProductionOutputReconciliationTest.php`, `ProductionTaskResourceLimitsTest.php`. Expect preserved lifecycle and resource limits. Pint and commit `feat: protect production tasks and output operations`.

## Task 6: Guard journal documents and lease-aware maintenance

**Files:** journal Action, two Inventory document Actions, backfill Action/command; `ProductionEditingDocumentsTest.php`, `ProductionEditingMaintenanceTest.php`. Existing `ProductionDocumentAttachmentTest.php`, `ProductionFormulaSnapshotBackfillTest.php`, receipt tests.

- [x] **Step 1: Add distinct production/receipt and maintenance tests.**

Production attach/detach/journal writes require context and bump parent once; duplicate attachment changes nothing; Editor cannot detach despite owning lease; wrong-parent document IDs do not detach another production's evidence. Receipt attachment/detachment requires its existing permissions and no production context. An upload that finishes after lease loss rolls back unreferenced media and attaches nothing. Backfill skips an active lease, reports busy separately, increments exactly once for a missing snapshot write, and leaves completed-snapshot no-ops unchanged.

```php
it('skips an actively edited snapshot backfill without invalidating the page', function (): void {
    $fixture = \Tests\Support\ProductionEditingFixture::create();
    $run = \App\Models\ProductionRun::factory()->for($fixture->workspace)->create();
    $fixture->lease($run);
    $result = app(\App\Actions\Production\BackfillProductionFormulaSnapshot::class)->handle($run);
    expect($result)->toBeFalse()->and($run->fresh()->formula_snapshot_completed_at)->toBeNull()
        ->and($run->fresh()->edit_revision)->toBe(0);
});
```

Preserve the Action's boolean return; add a separate command-side busy classification before calling it, and recheck inside the Action. Do not reinterpret `false` as a completed snapshot. Run the new tests; expect RED for the active-lease skip/context requirements.

- [x] **Step 2: Implement only the production document branch.**

`AttachProductionDocument::handle()` appends optional native context. If `$documentable instanceof ProductionRun`, call the guard and resolve the current parent, asset and duplicate attachment inside it. Validate asset ready/type/workspace from fresh rows. Existing first-or-create result uses `$document->wasRecentlyCreated` to report changed IDs. The receipt branch retains its current flow and does not enter a production guard.

Detach resolves `documentable_type` via the existing morph map, obtains a production parent hint, and rechecks document+parent under root locks. Keep `assertCanConfigure()` inside the guarded production callback before deleting. The receipt path stays as it is. Never dispatch a second generic document guard around an already guarded attachment.

In detail upload: assert current editing ownership/revision before expensive processing, run existing upload outside the guarded transaction, then pass context to attachment. Keep `rollbackUnreferencedUpload()` on failed attachment. No media operations run inside a retryable DB callback.

Backfill first uses `WorkspaceWriteLock`, then locks production. If `isActivelyReserved()` return false without snapshot changes. Only after a successful actual snapshot/line write call `$lockedProduction->increment('edit_revision')`. A completed snapshot returns true without increment. The CLI reports `busy`, `unavailable source`, `completed` separately; never takes over a human lease.

- [ ] **Step 3: Verify and checkpoint.** Run new tests plus `ProductionDocumentAttachmentTest.php`, `ProductionFormulaSnapshotBackfillTest.php`, `ProductionBenchGoodsReceiptPagesTest.php`, `ProductionWorkspaceAuthorizationTest.php`. Expect PASS and receipt/media behavior preserved. Pint and commit `feat: protect production evidence and snapshot maintenance`.

## Task 7: Add token-checked departure release endpoint

**Files:** request/controller/routes above; `ProductionEditingReleaseTest.php`.

- [x] **Step 1: Add HTTP behavior tests.**

Acquire two productions under one token and POST their public UUIDs. Verify release of only those leases, no revisions/stock changes, idempotent retry and unchanged unrelated lease. Cover guest authentication, malformed token/UUID, duplicate IDs, >100 IDs, foreign workspace, Viewer downgrade, cancelled Bench, revoked access, unknown/deleted IDs and late old-token release after takeover. Use atomic response assertions, not whole-payload comparisons.

```php
$this->actingAs($fixture->owner)->postJson(route('production-bench.production.editing.release'), [
    'token' => $context->token,
    'production_ids' => [$run->public_id],
])->assertOk()->assertJsonPath('ok', true);
$this->assertDatabaseMissing('production_edit_leases', ['production_run_id' => $run->id]);
```

Run the release test; expected RED for missing route.

- [x] **Step 2: Generate request/controller and add the route before the catch-all production detail route.**

Request `authorize()` returns authenticated-user presence. `rules()` is:

```php
return [
    'token' => ['required', 'string', 'uuid'],
    'production_ids' => ['required', 'array', 'min:1', 'max:100'],
    'production_ids.*' => ['required', 'string', 'uuid', 'distinct'],
];
```

Controller derives the original authorized selected workspace using a **fresh actor**, resolves all surviving public UUIDs there, and calls service release with internal IDs and `writable: false` through its public release method. Unknown/deleted UUIDs are a no-op; never query another workspace to disclose an owner or name. An attempt to release an existing foreign record cannot affect it. Return `response()->json(['ok' => true])`.

```php
Route::post('/production/editing/release', [ProductionEditingController::class, 'release'])
    ->middleware('throttle:60,1')
    ->name('production.editing.release');
```

Keep it inside the existing authenticated/verified Production Bench web group and CSRF middleware. Do not require a writable Bench middleware on this housekeeping endpoint; service still requires fresh read access.

- [ ] **Step 3: Verify and checkpoint.** Run new endpoint/service tests and `RecipeEditingReleaseTest.php`. Pint and commit `feat: release production editing leases on departure`.

## Task 8: Build the client queue, drafts and departure lifecycle

**Files:** `resources/js/production-editing.js`, `production-editing-draft.js`, app registration; two `.test.mjs` files and Pest process bridge. Read the current Recipe Bench editing/departure tests; reuse behavior, not its recipe-specific revision fields.

- [x] **Step 1: Add Node tests using fake time, deferred promises and EventTarget.**

Test these observable states: init never acquires; begin blocks second tab; save/poll/heartbeat share one serial queue; failed save retains draft; successful acknowledgement clears only its submitted group; newer input during Save stays dirty; no-op save is clean; watcher does not auto-acquire; former holder safe reacquires only matching baseline; stale/blocked/deleted stops writes and preserves input; focus/visibility stop renewal, not release.

This test requires the explicit draft API defined in Step 2:

```js
import assert from 'node:assert/strict';
import { createProductionDraft } from '../../resources/js/production-editing-draft.js';

const draft = createProductionDraft({ actuals: { quantity: '1' }, journal: { body: '' } });
draft.set('actuals', { quantity: '2' });
const submitted = draft.capture('actuals');
draft.set('actuals', { quantity: '3' });
draft.acknowledge(submitted, { quantity: '2' });
assert.deepEqual(draft.get('actuals'), { quantity: '3' });
assert.equal(draft.isDirty('actuals'), true);
assert.equal(draft.isDirty('journal'), false);
```

Departure matrix: cancelled `livewire:navigate`/`beforeunload`/blur sends nothing; navigating/pagehide/destroy sends one matching keepalive release; late acquire after departure sends another release; queued writes never start afterward; old-token release cannot revoke a new tab. Persisted `pageshow` waits for outstanding release/acquire promises, checks fresh state, then only former holder resumes if unchanged. Destroy removes listeners/intervals; mount-destroy-remount must not multiply requests.

Run `node --test tests/Unit/production-editing.test.mjs tests/Unit/production-editing-draft.test.mjs`. Expected RED for missing modules.

- [x] **Step 2: Implement draft state as a small independent module.**

```js
export function createProductionDraft(initial) {
    const copy = value => JSON.parse(JSON.stringify(value));
    const groups = new Map(Object.entries(initial).map(([name, value]) => [name, {
        value: copy(value), saved: copy(value), generation: 0,
    }]));
    const group = name => {
        if (!groups.has(name)) throw new Error(`Unknown production form group: ${name}`);
        return groups.get(name);
    };
    return {
        get(name) { return copy(group(name).value); },
        set(name, value) { const state = group(name); state.value = copy(value); state.generation++; },
        capture(name) { const state = group(name); return { name, generation: state.generation, value: copy(state.value) }; },
        acknowledge(submitted, canonical) {
            const state = group(submitted.name);
            state.saved = copy(canonical);
            if (state.generation === submitted.generation) state.value = copy(canonical);
        },
        isDirty(name) { const state = group(name); return JSON.stringify(state.value) !== JSON.stringify(state.saved); },
        hasChanges() { return [...groups.keys()].some(name => this.isDirty(name)); },
        replaceClean(snapshot) {
            if (this.hasChanges()) return false;
            for (const [name, value] of Object.entries(snapshot)) {
                const state = group(name); state.value = copy(value); state.saved = copy(value); state.generation++;
            }
            return true;
        },
    };
}
```

This plain serialization applies only to small bounded form groups, not files/DOM/stock previews. File upload dirty state is tracked separately and never serialized. Canonical decimal/date values must use the same normalized shape in current/saved groups; sort dynamic map keys before initialization/set so key ordering cannot create phantom dirtiness.

- [x] **Step 3: Implement the coordinator with one closure-owned runtime.**

Export `createProductionEditing(payload, environment = {})`; environment accepts document/window/fetch/setInterval/clearInterval for deterministic tests. Returned Alpine object exposes `init()`, `destroy()`, `begin()`, `finish()`, `poll()`, `runCommand(method, args, group)`, `takeover(reason)`, `reload()`, `restore()` and reactive `state`, `busy`, `stale`, `unavailable`, `owns`, `canWrite`, `message`, `draft`.

The queue implementation is exact and all revision-bearing calls use it:

```js
let queue = Promise.resolve();
let departing = false;
let generation = 0;
let releasePromise = null;
const enqueue = operation => {
    const expectedGeneration = generation;
    const next = queue.then(() => {
        if (departing || expectedGeneration !== generation) return null;
        return operation();
    });
    queue = next.catch(() => {});
    return next;
};
```

Mounted payload contains server-generated Locked token, public IDs, mounted revisions, read-only initial state, draft groups and release URL. `init()` installs listeners and a 15-second interval but never calls begin. Visible+focused ownership calls heartbeat; visible waiting editor calls status. An ordinary clean viewer receives a changed-production indication without an unsaved-input claim; explicit Reload refreshes its coherent snapshot as specified in Task 9. Hidden/unfocused pages do neither. No `.window` lifecycle handler closes over a nested scope's private runtime.

Independent departure release uses this body and request, with the browser's matching page token:

```js
const sendRelease = () => fetch(payload.releaseUrl, {
    method: 'POST', credentials: 'same-origin', keepalive: true,
    headers: {
        Accept: 'application/json', 'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
    },
    body: JSON.stringify({ token: payload.token, production_ids: payload.publicIds }),
});
```

`depart()` sets departing/generation before releasing, clears ownership, stops interval, deduplicates release, and stores whether it was a former holder. Calls already in flight check generation before applying state; an acquired reply after departure forces a fresh release after the first promise rather than being swallowed by deduplication. Native pagehide releases even when `persisted`; pageshow restoration retains draft, waits for releases then resets the queue generation and checks status before possible former-holder reacquisition. Network/CSRF failure leaves expiry as fallback and never restores ownership by assumption.

`runCommand()` captures the named group's submitted draft when the command is requested, before waiting in the queue; sends it to the appropriate server Action method, requires a successful explicit acknowledgement, advances only acknowledged revisions, and acknowledges only that group's canonical reply. A stale/lease failure does not clear the group. Other groups stay dirty. Destructive domain controls retain their existing confirmations before enqueueing. Finish/reload confirms discard if any group/upload is dirty; cancelling changes nothing. Takeover is explicit and passes a required reason, not an automatic retry.

Register in `resources/js/app.js`:

```js
import { createProductionEditing } from './production-editing';
window.productionEditing = createProductionEditing;
```

- [x] **Step 4: Add this existing-style Pest Node bridge.** No browser dependency and no source-string-only substitute for behavior.

```php
it('preserves production drafts and releases reservations on confirmed departure', function (): void {
    $process = new \Symfony\Component\Process\Process([
        'node', '--test', 'tests/Unit/production-editing.test.mjs', 'tests/Unit/production-editing-draft.test.mjs',
    ], base_path());
    $process->run();
    expect($process->isSuccessful(), $process->getOutput().$process->getErrorOutput())->toBeTrue();
});
```

- [ ] **Step 5: Verify and checkpoint.** Run Node tests, the bridge and `RecipeEditingClientTest.php`. Expect PASS across every departure and pending-input case. Commit `feat: coordinate production editing and draft recovery`.

## Task 9: Add explicit detail editing and coherent form snapshots

**Files:** concern, `ProductionDetail.php`, detail Blade, status component; `ProductionEditingDetailTest.php`; existing detail/presenter tests.

- [x] **Step 1: Add Livewire tests before enabling controls.**

```php
it('opens a production without reserving and requires explicit editing', function (): void {
    $fixture = \Tests\Support\ProductionEditingFixture::create();
    $run = \App\Models\ProductionRun::factory()->for($fixture->workspace)->create();
    $page = \Livewire\Livewire::actingAs($fixture->owner)->test(\App\Livewire\ProductionBench\Production\ProductionDetail::class, ['productionId' => $run->public_id]);
    $page->assertSee(__('production_bench.editing.begin'))->assertSet('editingOwnsLease', false);
    $this->assertDatabaseCount('production_edit_leases', 0);
    $page->call('beginEditing')->assertSet('editingOwnsLease', true)->assertHasNoErrors();
    $this->assertDatabaseHas('production_edit_leases', ['production_run_id' => $run->id, 'user_id' => $fixture->owner->id]);
});
```

Add tests for same-session successive saves, dirty state after failed save, stale poll preserving actuals/journal/date state, explicit clean snapshot reload updating forms and revision together, deletion unavailable, Locked identity/token/revision tampering, fresh workspace authority, same-workspace wrong-production task/document ID and post-completion valid operations. Call public write methods without begin and verify server refusal even if controls are bypassed.

- [x] **Step 2: Implement the concern's Locked state and methods.**

```php
#[Locked] public array $editingProductionIds = [];
#[Locked] public array $expectedProductionRevisions = [];
#[Locked] public string $productionEditingToken = '';
#[Locked] public bool $editingOwnsLease = false;
#[Locked] public array $productionEditingState = [];
```

Use `initializeProductionEditing(Collection $productions): void` only after authorized mount or deliberate coherent reload: assign IDs/revisions, a unique server UUID, and service status. Never acquire in render/mount. Method contracts: `beginEditing(): array`, `editingStatus(): array`, `heartbeatEditing(): array`, `takeoverEditing(string $reason): array`, `finishEditing(): array`, `reloadProduction(): array`, private `productionEditingContext(): ProductionEditingContext`, private `acknowledgeProductionMutation(ProductionEditingContext $context): array`.

Acquisition uses only Locked mounted revision map/token; status/heartbeat return observations and never rebase expected revisions. Acknowledgement merges only the just-committed context map and updates own state. On deletion set unavailable and stop attempts. Convert validation errors with `['ok' => false, 'errors' => $exception->errors()]`; revoked authority remains a proper access failure. Renderless status/acquisition methods prevent a poll from morphing unsaved fields. A successful draft-carrying command returns this exact structure:

```php
return [
    'ok' => true,
    'revisions' => $context->acknowledgedRevisions(),
    'canonical' => $canonicalSubmittedGroup,
    'editing' => $this->productionEditingState,
];
```

`$canonicalSubmittedGroup` is the command's normalized submitted form group, constructed by that command's existing normalization and returned persisted result; it is not an arbitrary fresh snapshot observed after another writer. Initialize/update `$this->productionEditingState` from the acknowledged Locked baseline. State returned by a subsequent poll may show newer observations but cannot substitute them for this receipt.

Detail `productionId` becomes `#[Locked]`. Narrow `task()` with `->where('production_run_id', (int) $this->productionId)` and document lookup similarly. Every Task 4–6 caller passes the **same** locally constructed context used to collect the command receipt. Keep original workspace hydration guard.

For draft-carrying methods such as Save actuals, accept an explicit submitted group array, normalize/validate through the current Action rules, and do not overwrite Livewire draft properties from a poll or save response. Return canonical rows renderlessly; client applies them only if its group generation is unchanged. Keep a separate presentation refresh for committed tables that does not reinitialize dirty form groups. A failed refresh must preserve the acknowledged save result, saved baseline and editing access, with separate translated display feedback. Lifecycle request failures resolve to handled results with visible feedback instead of rejecting event-handler promises.

The detail snapshot loader initializes these groups together with its revision: actual rows/calculated rows; planning date/location; completion quantity/mode/ingredient/manufacture/ready dates; task dates; cancellation reason; abort reason; finished-goods issue fields; journal body; document note. File input remains local dirty state. A clean reload fetches production and its children in one coherent readonly transaction, guarded by the workspace/parent lock order without the write MVCC fence. It updates both forms and baseline only on explicit clean reload or client-confirmed discard. `reloadProductionEditing()` prepares a Locked receipt without changing mounted revisions or drafts. The client rejects it if input or an upload arrived during preparation; otherwise it applies the exact groups and revisions before calling `acceptProductionReload(receiptId)`. Acceptance rechecks access and availability, adopts and renders that prepared snapshot, and consumes the receipt. A newer intervening database revision remains stale. Input arriving during acceptance stays dirty; unconfirmed acceptance disables writes through later polls until an explicit retry succeeds. Deliberate upload discard is client-owned; acceptance must not clear a newer upload.

- [x] **Step 3: Attach compact controls and route commands through the coordinator.**

Root `x-data="productionEditing(@js($editingPayload))"` receives original payload and shared closure. Keep unrelated modal visibility in the same Alpine object's presentation properties or an explicitly nested scope without shadowing coordinator state. The server `$mutationLocked` includes lack of reservation; client disabled state additionally uses `!canWrite || busy`.

```blade
<x-production-bench.editing-status />
<button type="button" @click="begin()" x-show="!owns && !stale && !unavailable" :disabled="busy" class="sk-btn sk-btn-secondary">
    {{ __('production_bench.editing.begin') }}
</button>
<button type="button" @click="finish()" x-show="owns" :disabled="busy" class="sk-btn sk-btn-ghost">
    {{ __('production_bench.editing.finish') }}
</button>
```

Status component escapes holder names, uses one `role="status"`/polite region, shows unsaved copy only when `draft.hasChanges()` or a pending upload exists, and renders explicit Resume/Reload/Takeover conditions. Takeover modal uses current shared modal/button components and reason validation, not `prompt()` or browser alerts. Plain read sections remain visible when controls are disabled.

Replace mutation `wire:click`, `wire:submit`, immediate task `wire:change` with queued `@click`, `@submit.prevent`, `@change` handlers; only user input synchronization that does not write a domain record remains. Avoid `.live.debounce` input requests competing with the queue: bind bounded form draft groups locally and submit snapshots explicitly. Filament date inputs retain their normal widgets; synchronize date updates into the appropriate local group and make their server updates pass through the same queue when normalization requires a request. Keep existing domain confirmation modals and date-only behavior.

- [ ] **Step 4: Verify and checkpoint.** Run new detail/client tests, `ProductionBenchProductionsTest.php`, `ProductionDetailPresenterTest.php`, `ProductionLocationProductionUiTest.php`, `ProductionRunBatchAssignmentPagesTest.php`, `ProductionWorkspaceAuthorizationTest.php`. Update existing UI tests to explicitly begin editing for mutations, retaining read/lifecycle assertions. Pint; commit `feat: add explicit production detail editing`.

## Task 10: Add atomic stock-preparation editing

**Files:** `StockPreparation.php`, its Blade; `ProductionEditingStockPreparationTest.php`; existing two preparation test files.

- [x] **Step 1: Add group UI/server tests.** Mount is preview and creates no lease; begin acquires exact selected group; malformed/foreign/101 IDs reject before preview generation; one held or stale selected production acquires none. A late stock change fails confirmation while retaining manual draft. A successful group confirmation validates all matching leases/revisions before creating any stock reservation/number and retains existing redirect. A new single- or multi-production page never automatically claims the old detail token.

```php
$page = \Livewire\Livewire::actingAs($fixture->owner)->withQueryParams(['ids' => $first->id.','.$second->id])
    ->test(\App\Livewire\ProductionBench\Production\StockPreparation::class);
$page->assertSet('editingOwnsLease', false);
$this->assertDatabaseCount('production_edit_leases', 0);
$page->call('beginEditing')->assertSet('editingOwnsLease', true);
$this->assertDatabaseCount('production_edit_leases', 2);
```

Run new preparation tests; expected RED for absent editing state and preview controls.

- [x] **Step 2: Bind the original group and manual allocation draft.** Make `productionIds` and `idempotencyKey` Locked. Normalize and validate original selection at mount with max100, authorize every selected record before computing proposals, then initialize concern state once. Never render a silently truncated group. Delete/unavailable states render readable pending allocations and an explicit selection/reload action instead of replacing them or repeatedly crashing renders.

Bind `manualMode` and `manualQuantities` to one local `allocations` draft group. `toggleManual` affects draft only. Existing proposal rendering remains a stock preview. **Edit allocations** calls concern begin; confirmation passes context and current submitted manual allocation snapshot to `PrepareProductionStock`. Retain existing lot eligibility, locked availability, material units and idempotency validation. On success acknowledge changed revisions before redirect so late replies can release the right group; on failure retain manual values and valid ownership.

The Action call is:

```php
$context = $this->productionEditingContext();
$prepared = $prepareProductionStock->handle(
    actor: $this->user(), productionIds: $this->productionIds,
    idempotencyKey: $this->idempotencyKey, manualAllocations: $this->manualAllocations(), editing: $context,
);
$this->acknowledgeProductionMutation($context);
```

Validate submitted requirements belong to the **selected productions**, not merely the workspace. A group acquisition/renewal failure marks the entire editor unable to confirm; never skip one production silently or continue with a subset. User changes selection by an explicit new mount after discard confirmation.

- [ ] **Step 3: Verify and checkpoint.** Run new group tests, `ProductionBenchStockPreparationTest.php`, `ProductionStockPreparationTest.php`, Node/client lifecycle tests. Expect PASS with zero partial stock or numbering effects on failure. Pint; commit `feat: reserve stock preparation productions atomically`.

## Task 11: Protect short production/task register commands

**Files:** `ProductionIndex.php`, `TaskIndex.php`, corresponding views; `ProductionEditingRegistersTest.php`; existing index/task/numbering page tests.

- [x] **Step 1: Add displayed-revision and temporary-lease tests.** Verify a list action rejects an active same-user other-tab lease and a stale rendered revision without touching rows; reading/filtering/selecting creates no lease. A successful standalone assignment creates no enduring lease and updates row+revision together. A scheduling modal retains its opening revision despite later list refresh. Bulk numbering acquires/writes all-or-nothing while retaining already-numbered no-ops and counter integrity.

- [x] **Step 2: Keep server-owned displayed and modal baselines.**

Add Locked bounded displayed revision maps alongside each rendered page. Initialize on mount/explicit list refresh/page change, not blindly in every render after a failed command. A modal has its own Locked production ID and opening revision copied when it opens; list refresh never updates it. Action task IDs must exist in the rendered/authorized selection and their durable production parent supplies the revision key. Do not accept arbitrary browser-provided expected revisions.

Construct temporary context server-side:

```php
$context = new ProductionEditingContext(
    workspaceId: $this->workspace()->id,
    token: (string) Str::uuid(),
    expectedRevisions: $selectedMountedRevisions,
    temporary: true,
);
```

Pass it to each list mutation, preserving current manager/role and lifecycle restrictions. On successful Action acknowledgement, refresh affected rows and their metadata together; on failure keep the mounted baseline and schedule date. Register commands need no persistent page-edit mode and cannot implicitly take over. Queue client actions/refreshes to prevent overlapping register writes. Calendar remains read-only and unchanged.

- [ ] **Step 3: Verify and checkpoint.** Run new register tests, `ProductionTaskIndexTest.php`, `ProductionRunBatchAssignmentPagesTest.php`, `ProductionBenchProductionsTest.php`, `ProductionDeleteAuthorizationTest.php`, `ProductionBenchProductionCalendarTest.php`. Pint; commit `feat: guard production register shortcuts`.

## Task 12: Localize concise status and recovery messages

**Files:** `lang/en/production_bench.php`, six-locale catalogue JSON; `ProductionBenchLocalizationTest.php`; new view/client tests. Existing catalogue `production_bench => ['*']` needs no duplicate registration.

- [x] **Step 1: Add translation and copy behavior assertions.** Verify catalogue contains every new English key and de/es/fr/it/nl/pt_BR values, placeholders match, and holder-name copy is escaped. Clean saved viewer has no unsaved-draft sentence; repeated blocked polls update one existing status and produce no toast. Use existing catalogue test utilities rather than a new exporter script.

- [x] **Step 2: Add this exact English subtree and catalogue rows.**

```php
'editing' => [
    'begin' => 'Edit production',
    'begin_allocations' => 'Edit allocations',
    'finish' => 'Finish editing',
    'resume' => 'Resume editing',
    'reload' => 'Reload production',
    'takeover' => 'Take over editing',
    'takeover_reason' => 'Reason for taking over',
    'blocked' => ':name is editing this production. You can still view it.',
    'available' => 'This production is available to edit.',
    'stale' => 'This production changed since you opened it. Reload before editing.',
    'unavailable' => 'This production is no longer available.',
    'unsaved' => 'Your unsaved changes remain on this page.',
    'discard_confirmation' => 'Discard your unsaved changes?',
    'group_blocked' => 'The selected productions could not all be reserved. Review the listed productions before continuing.',
    'status_failed' => 'Editing status could not be checked. Your changes remain on this page.',
    'validation' => [
        'selection' => 'Choose between 1 and 100 productions from this workspace.',
        'unavailable' => 'One or more selected productions are unavailable.',
        'busy' => 'This production is being edited in another session.',
        'lease' => 'Editing access has ended. Your changes have not been saved.',
        'revision' => 'This production has changed. Reload before saving.',
        'token' => 'A valid editing session is required.',
        'reason' => 'Enter a reason of up to 1,000 characters.',
    ],
],
```

Provide natural translations for all six existing catalogue locales, matching its current row schema and `:name` exactly. Importing translations into the working database is separate from file authoring; if later authorized, use the existing preserve-existing command after checking its help. Do not overwrite administrator wording.

- [ ] **Step 3: Verify and checkpoint.** Run localization/catalogue and detail/client tests. Expected no raw keys and no false unsaved message. Commit `feat: localize production editing recovery`.

## Task 13: Prove PostgreSQL races and complete integration verification

**Files:** `ProductionEditingPostgresConcurrencyTest.php`; extend `tests/Support/FormulaSharePostgresRace.php` only if its current bounded race helper needs a reusable production scenario. Reuse its guarded disposable-database setup rather than introducing an unsafe reset path.

- [x] **Step 1: Add real concurrent-session tests with barriers, not timing guesses.**

Use the existing `Tests\Support\FormulaSharePostgresDatabase` identity gate and `FormulaSharePostgresRace` process/join helper. This test file does not use SQLite `RefreshDatabase` or silently claim skipped tests as proof. It runs only with `VERIFY_FORMULA_SHARING_POSTGRES=true` and `FORMULA_SHARING_POSTGRES_DATABASE=koskalk_formula_sharing_test_YYYYMMDD`, matching `current_database()` and `TestDatabaseSafety`. Use an explicitly disposable database already approved for these tests. Never point the helper at Herd's working database.

Cases and persisted assertions:

| Race | Required winner/loser result |
| --- | --- |
| Two first acquisitions | Exactly one token holds the unique production lease. |
| Persistent editor vs temporary task/register command | No concurrent child write bypasses active ownership. |
| Old save vs committed newer command | Stale draft rejected; winning actuals/task/stock data preserved. |
| Opposite-order group selections | Normalized ascending production locks; no partial leases or stock effects. |
| Old departure release vs takeover | New token retained. |
| Lease loss/expiry during command | No partial domain changes or revision increment. |
| READ COMMITTED production write vs REPEATABLE READ sharing snapshot | Workspace MVCC fence forces stale transaction retry/safe failure; no lost quota/mapping/authority state. |
| Membership/selected-workspace/entitlement change vs acquisition/write | Fresh authority checked on winning serialization/retry; revoked actor cannot write. |
| Sharing/entitlement writer vs production group | Canonical actor/workspace/membership/production order; no reverse acquisition hidden in child services. |

Add a separate assertion that workspace business `updated_at` is identical before/after the fencing no-op, and failed/retried commands acknowledge no uncommitted revision. Reset/join child sessions using the existing helper's cleanup even on assertion failure.

- [x] **Step 2: Run concurrency tests and report actual execution.**

Run with the existing authorized disposable PostgreSQL environment and `php85 -d memory_limit=2G vendor/bin/pest tests/Feature/ProductionEditingPostgresConcurrencyTest.php tests/Feature/FormulaSharePostgresIsolationTest.php`. Expected PASS, **zero skips** for the requested PostgreSQL cases. If no disposable database is available, report this verification as pending and obtain the missing test-environment authorization rather than resetting another database. Preserve existing sharing/Recipe Bench isolation behavior.

- [x] **Step 3: Run the narrow integrated suite once after the complete rollout.**

```bash
php85 -d memory_limit=2G vendor/bin/pest tests/Feature/ProductionEditingMigrationTest.php tests/Feature/ProductionEditingServiceTest.php tests/Feature/ProductionEditingReleaseTest.php tests/Feature/ProductionEditingMutationTest.php tests/Feature/ProductionEditingDetailTest.php tests/Feature/ProductionEditingStockPreparationTest.php tests/Feature/ProductionEditingRegistersTest.php tests/Feature/ProductionEditingDocumentsTest.php tests/Feature/ProductionEditingMaintenanceTest.php tests/Feature/ProductionEditingClientTest.php
php85 -d memory_limit=2G vendor/bin/pest tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionBenchStockPreparationTest.php tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionTaskIndexTest.php tests/Feature/ProductionWorkspaceAuthorizationTest.php tests/Feature/ProductionBenchEntitlementTest.php tests/Feature/ProductionDocumentAttachmentTest.php tests/Feature/ProductionRunNumberStorageTest.php tests/Feature/ProductionRunBatchNumberingTest.php tests/Feature/ProductionBenchLocalizationTest.php
php85 -d memory_limit=2G vendor/bin/pest tests/Feature/RecipeEditingServiceTest.php tests/Feature/RecipeEditingReleaseTest.php tests/Feature/RecipeEditingClientTest.php tests/Feature/FormulaShareRecipeBenchIntegrationTest.php
php85 vendor/bin/pint --dirty --format agent
graphify update .
git diff --check
```

Expected PASS; Graphify refreshed; no unrelated files staged. Run `vendor/bin/filacheck --fix` only if implementation actually touches `app/Filament` (none planned). Truss diff is run after an authorized local additive migration; this planning task does not migrate the working database.

- [ ] **Step 4: Perform the two-profile walkthrough on Herd without a build.**

Resolve URLs with Boost `get-absolute-url`. Use existing logged-in browser access if available. Owner opens production A without Edit: Editor can edit A. Owner chooses Edit A: Editor sees holder and cannot take over; Owner/Admin can deliberately take over with reason. Different production B remains editable. Save twice, enter more input during a delayed Save, and observe correct saved/dirty status. Confirm departure makes A available on the next poll, cancelled navigation keeps ownership, backgrounding stops renewal without immediate release, returning unchanged former holder quietly recovers, and waiting observer chooses Resume explicitly.

Exercise tasks, journal, output release and stock allocation group; introduce a genuine stale production and competing lot availability; confirm drafts persist and no group partially changes. Repeat workspace switch/revoked role and completed-production allowed/disallowed operations. A closed tab's late release cannot remove a newer holder. Purchasing receipt documents still work normally. If frontend assets do not reflect changes, ask the user to use their existing dev process/build workflow; do not run a local build against their instruction.

- [ ] **Step 5: Ask Philippe to run the full suite.** Use `php85 -d memory_limit=2G artisan test --compact`. Resolve any related failure before completion, preserving other agents' work. Summarize modified behavior, actual test results, PostgreSQL execution and any remaining verification limit. Commit the integrated change only after the completed task checks; push only with user authorization. Deployment remains the user's manual Forge workflow.

## Coverage and final self-review

| Approved requirement | Tasks |
| --- | --- |
| Independent production reservations; read-only opening | 2, 9, 10 |
| Fresh roles, original workspace, Bench eligibility, takeover audit | 2, 7, 9, 13 |
| Matching user/token release and second tabs | 2, 7, 8, 13 |
| Mounted revision, exact save acknowledgement, newer input retained | 3, 8, 9 |
| Quiet former-holder recovery; explicit waiting editor | 2, 8, 9 |
| Atomic bounded groups and stock revalidation | 2, 3, 4, 10, 13 |
| Every production writer, linked task/output/doc boundaries | 4, 5, 6, 11 |
| Scope-proved creation/internal helpers; maintenance skips | 3, 4, 6 |
| Completed production domain restrictions preserved | 4, 5, 9, 13 |
| Confirmed departure, late responses, browser restoration | 7, 8, 13 |
| Coherent clean refresh; dirty/deleted recovery | 8, 9, 10 |
| Concise escaped status and six locales | 9, 12 |
| Sharing fence, canonical lock order, no blind isolation changes | 2, 3, 4, 13 |
| Migration integrity, PostgreSQL races, Recipe Bench regression | 1, 13 |

Before execution, retain the spec's production-wide scope and the existing page UX decisions. Before completion, inspect every `handle()`/`complete()` call site for the listed mutation families, including tests and internal services: no missing context may become a silent fallback, no public helper may accept a browser trust flag, and no child-only mutation may omit the parent revision. Check that group ID bounds apply before expensive proposal generation and that acknowledgement maps are recorded only after a transaction succeeds.

## Execution record — 2026-10-01

**Final follow-up verification — 2026-10-02:** Philippe authorized the remaining small cleanup and coverage. Assign/Complete task workspace errors now use the existing translation key. Removed unused aliases in four task actions and Reopen's redundant post-null-check type guard. Renamed the public-ID visibility test to describe its rendered-text assertion. The production register now says Refresh productions, with the new key in English and all six catalogue locales. Added explicit coverage proving production B remains available and acquirable while production A is leased. The existing prepare/adopt/accept reload contract remains unchanged.

Fresh affected verification passed **141 tests / 1,238 assertions**. A new PostgreSQL migration round-trip test passed **1 test / 15 assertions** against the identity-checked disposable `koskalk_formula_sharing_test_20261001_production` database: production history, indexes, constraints and triggers survive down/up, and the editing tables are recreated with their original definitions. The working database was not reset. Fresh full suite under `LANG=en_US.UTF-8` passed **4,659 tests, 66 skipped, 72,891 assertions** in 211.17 seconds, exit 0. This supersedes the outstanding full-suite and PostgreSQL migration checks recorded below; the PostgreSQL-only test skips in the default SQLite suite and was verified separately. Pint, whitespace checks and Graphify passed. No build, translation import, commit, push or deployment occurred; browser verification remains with Philippe.

**Register/task follow-up — 2026-10-02:** After evaluating both follow-up reviews, Philippe authorized visible translated register-request failure feedback and restored task-domain errors. Rejected register promises return safely and display a warning to reload/check the result before retrying; both register templates supply the localized message. All five task actions validate the scoped durable parent before the guard and check the task again under the production lock. Deleted/foreign tasks and a task disappearing inside the guarded transaction have regression coverage. Added default-suite authority-change checks and coverage of the already-correct definitive unavailable-reload branch. The existing coherent reload receipt design is retained. Fresh affected verification passed **180 tests / 1,458 assertions**, plus **45 direct Node tests**. Full-suite and PostgreSQL migration round-trip verification remain pending; use `LANG=en_US.UTF-8 php85 artisan test --compact` for the complete suite. No build, translation import, commit, push or deployment occurred.

**Final review update — 2026-10-02:** Philippe accepted manual browser verification after the computer-use failures. Independent standards review found no actionable defect; spec/client review reproduced and corrected task-date acknowledgements clearing rejected sibling input, a queued-date race clearing newer input, and the legacy Intermediate selector remaining hidden. Task commands capture input before waiting in the queue and acknowledge the individual target while refreshing only clean sibling dates. Legacy output selection now follows the local draft and requires its ingredient. All new regressions failed before their corrections. Independent re-review found no remaining concrete issue; the final affected run passed **149 tests / 1,135 assertions**, with **27 Node tests** passing directly. Pint, whitespace checks and Graphify passed. These narrow corrections do not change ownership, revisions or transaction locking; the earlier PostgreSQL evidence remains applicable. The previously recorded full-suite result predates these corrections; request a full-suite rerun before release. Task 9's explicit coherent Reload contract is now consistent across this plan and its design. Browser cases remain handed to Philippe, not claimed verified. No build, translation import, commit, push or deployment occurred. Other agents' unrelated files remain untouched and unstaged.

Tasks 1–12 are implemented inline on main, including existing-production callers, native helper scopes, detail/stock editors, register shortcuts and all six catalogue locales. Verification/checkpoint boxes above retain their pending commit step; no checkpoint or integrated commit was made during implementation. Other agents' formula-sharing work and unrelated memory/export-plan files remain intact.

Verification completed:

- Integrated production, Recipe Bench and sharing regression run: 592 passed, 9 environment-dependent skips, 3,082 assertions. This final run includes the bounded-command additions and scheduling modal queue contract; raw groups of 101 entries reject before deduplication or stock/numbering work.
- PostgreSQL: 11 production concurrency cases passed (59 assertions), plus 3 existing sharing-isolation cases (12 assertions), zero skips. Each file ran in a separate process against `koskalk_formula_sharing_test_20261001_production`; the working database and the other agent's disposable database were never reset. The sharing fence race covers two productions under READ COMMITTED against a REPEATABLE READ sharing writer, and preserves the workspace business timestamp.
- Node: 15 draft/coordinator/register tests passed, including newer input, lifecycle defaults, pending uploads, CSRF departure, network failure, restoration and serialized scheduling-modal/register submissions. Pest executes the same Node tests through its process bridge.
- Pint fixed formatting; `git diff --check` clean; `graphify update .` completed.
- Applied only `2026_10_01_120000_add_production_editing_protection.php` to Herd's local database. Truss confirms `production_runs.edit_revision`, `production_edit_leases` and `production_edit_takeovers`; its cumulative diff also includes the other agent's pre-existing sharing tables and ingredient lineage column.

Implementation refinements from final review: location assignment has a separate draft group from the planned date; Start returns its own transaction's canonical actuals defaults and updates only clean groups; same-date rescheduling preserves reserved stock as a no-op; identical actuals preserve timestamps and attribution; lease expiry also rolls back deletion; internal date/rescaling helpers require a live command scope.

Follow-up verification found two outdated contextual-help tests: the shared-formula work adds three registered topics (96 total), and stock preparation must open a scheduled production rather than an empty selection. Both fixtures were updated without removing their publication assertions. The browser walkthrough also found unescaped Filament extra Alpine attributes truncating date-picker watchers; the values are now explicitly escaped, with a parsed-DOM regression test. The 53 focused help/date/task checks passed, and the 10 detail tests passed again after Pint.

Browser verification in the existing Philippe Comet profile confirmed read-only opening, disabled date/task controls, explicit Edit enabling controls, and Save retaining editing ownership. The second browser profile could not be inspected because the computer-use server returned a ScreenCaptureKit audio/video capture failure, so the two-profile walkthrough remains pending. The fresh full suite passed after the corrections: 4,602 passed, 65 environment-dependent skips, 72,163 assertions (215.23 seconds). Complete the remaining walkthrough before the integrated commit/push. No local build, translation import, commit, push or deployment was performed.

Philippe subsequently confirmed the editing flow works smoothly in his walkthrough. The approved visibility adjustment retains the status row's position, gives Finish editing the same outlined button as Resume editing, and adds a compact Viewing/Editing/current-holder badge. Active ownership uses the existing soft success colors; longer notices remain below. All six catalogue locales include the three new labels. The 23 affected detail, stock, client and localization tests passed (524 assertions); Pint and whitespace checks passed. No build or editing behavior change was introduced.

The date/attachment follow-up labels the existing scheduled or reserved production action Save production date, while initial draft scheduling retains Plan production. A compact Unsaved changes badge and explicit Discard changes confirmation distinguish pending input from releasing editing access. File attachment readiness follows the Livewire upload property and upload lifecycle events; uploading, ready and failed feedback are translated in English and all six catalogue locales. Uploads block premature attachment and finishing, deliberate discard clears the temporary upload, and allocation pages never inspect a missing upload property. The queued backend attachment test uses a real image upload and verifies success plus retention of rejected evidence after a revision change. The 28 affected detail/document/client/localization/stock tests passed (640 assertions), including the Node process bridge. Comet confirmed the date label, read-only controls, editing enablement and opening/cancelling the file chooser; no file was uploaded through the browser and no production values were changed. Pint, whitespace checks and graphify completed. No build, translation import, commit or push was performed.

Further manual feedback exposed two gaps: renderless attachment rejection had no message beside Attach, and a Save could reach an expired lease before the next visible-page poll renewed it. Attachment results now persist locally through polls, show Attaching and acknowledged success, and expose the shared 180 KB PDF limit (retained at Philippe's request). The production-location select has a standard 40px height and no flex stretching. Queued mutations check heartbeat first, renew only a previously held free unchanged selection, and stop before writing when stale, blocked or departed; the server guard remains strict. The 1.6 MB PDF test verifies explicit rejection with file/note retained and no media/document creation. A time-controlled date-save test covers the 91-second expiry boundary; Node covers competing renewal and late departure. All 76 affected checks passed (823 assertions), including the Node bridge; Pint, whitespace checks and graphify passed. Browser observation confirmed a pending upload and an editing-access error, but the user was actively using Comet so no further browser saves were performed. No build, translation import, commit or push was performed.

The authorized final-verification phase refreshed the complete suite: **4,609 passed, 65 skipped, 72,506 assertions** (231.10 seconds, exit 0). All 24 Node draft/coordinator cases passed directly; fresh Pint and whitespace checks passed. No further code correction was identified in the reviewed ownership, acknowledgement, command and attachment paths. Comet control returned ScreenCaptureKit error `-3811` on both attempts, so the actual-browser cases remain explicitly pending in `2026-10-01-production-editing-final-verification.md`; computer-use restoration was requested. No browser action changed the local production. No build, translation import, commit, push or deployment occurred, and unrelated memory/export work remains unstaged.


### Waiting and group-message review corrections — 2026-10-02

Philippe authorized the two messaging findings. Successful acquisition clears the previous waiting state, so finishing returns to ordinary viewing without a stale Resume editing prompt or availability notice; a still-blocked observer retains the deliberate-resume prompt. Blocked multi-production selections now use the existing six-locale group warning from the mounted payload. Single-production notices still identify the holder, and the affected-production list remains available.

The waiting/finish regression, group-copy regression and rendered-payload contract failed before the corrections. Fresh verification: **37 affected tests passed, 746 assertions**, including the Node process bridge; **43 Node tests passed directly**. Pint, whitespace checks and Graphify passed. No build, translation import, commit, push or deployment occurred. Full-suite and browser verification remain with Philippe; rerun `php artisan test --compact` before release.
