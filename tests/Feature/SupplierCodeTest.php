<?php

use App\Actions\Purchasing\SaveSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('backfills legacy supplier codes and remains reversible', function (): void {
    $workspace = Workspace::factory()->create();
    $migration = supplierCodeMigration();

    $migration->down();

    $supplierId = DB::table('suppliers')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Legacy supplier',
        'default_currency' => 'EUR',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('suppliers')->find($supplierId)->code)->toBe("SUP-{$supplierId}")
        ->and(Schema::getColumnListing('suppliers'))->toContain('code');

    $migration->down();

    expect(Schema::getColumnListing('suppliers'))->not->toContain('code');

    $migration->up();

    expect(DB::table('suppliers')->find($supplierId)->code)->toBe("SUP-{$supplierId}");
});

it('requires a supplier code without writing a supplier', function (): void {
    [$owner, $workspace] = activeSupplierCodeWorkspace();

    $exception = captureSupplierCodeValidationException(fn (): Supplier => app(SaveSupplier::class)->handle(
        $owner,
        $workspace,
        supplierCodeAttributes(),
    ));

    expect($exception?->errors())->toBe(['code' => ['The code field is required.']]);

    $this->assertDatabaseCount(Supplier::class, 0);
});

it('normalizes a supplier code before saving it', function (): void {
    [$owner, $workspace] = activeSupplierCodeWorkspace();

    $supplier = app(SaveSupplier::class)->handle($owner, $workspace, supplierCodeAttributes([
        'code' => ' oleva_01 ',
    ]));

    expect($supplier->code)->toBe('OLEVA_01');
});

it('rejects supplier codes with invalid characters or more than sixteen characters', function (string $code, string $rule): void {
    [$owner, $workspace] = activeSupplierCodeWorkspace();

    $exception = captureSupplierCodeValidationException(fn (): Supplier => app(SaveSupplier::class)->handle(
        $owner,
        $workspace,
        supplierCodeAttributes(['code' => $code]),
    ));

    expect($exception?->errors())->toHaveKey('code')
        ->and($exception?->errors()['code'][0])->toContain($rule);

    $this->assertDatabaseCount(Supplier::class, 0);
})->with([
    'invalid characters' => ['olive oil', 'format'],
    'too long' => ['SUPPLIER-CODE-000', '16'],
]);

it('rejects a case-insensitive duplicate supplier code in the same workspace without writing', function (): void {
    [$owner, $workspace] = activeSupplierCodeWorkspace();
    app(SaveSupplier::class)->handle($owner, $workspace, supplierCodeAttributes(['code' => 'OLEVA_01']));

    $exception = captureSupplierCodeValidationException(fn (): Supplier => app(SaveSupplier::class)->handle(
        $owner,
        $workspace,
        supplierCodeAttributes(['code' => 'oleva_01']),
    ));

    expect($exception?->errors())->toBe(['code' => ['The code has already been taken.']]);

    $this->assertDatabaseCount(Supplier::class, 1);
});

it('allows the same supplier code in another workspace', function (): void {
    [$owner, $workspace] = activeSupplierCodeWorkspace();
    $otherOwner = User::factory()->create();
    $otherWorkspace = Workspace::factory()->for($otherOwner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($otherOwner, $otherWorkspace);
    app(SaveSupplier::class)->handle($owner, $workspace, supplierCodeAttributes(['code' => 'OLEVA_01']));

    $supplier = app(SaveSupplier::class)->handle(
        $otherOwner,
        $otherWorkspace,
        supplierCodeAttributes(['code' => 'oleva_01']),
    );

    expect($supplier->code)->toBe('OLEVA_01')
        ->and($supplier->workspace_id)->toBe($otherWorkspace->id);
});

it('ignores the current supplier code during updates but rejects another supplier code', function (): void {
    [$owner, $workspace] = activeSupplierCodeWorkspace();
    $action = app(SaveSupplier::class);
    $supplier = $action->handle($owner, $workspace, supplierCodeAttributes(['code' => 'OLEVA_01']));
    $otherSupplier = $action->handle($owner, $workspace, supplierCodeAttributes([
        'name' => 'Other supplier',
        'code' => 'OTHER_01',
    ]));

    $updated = $action->handle($owner, $workspace, supplierCodeAttributes([
        'name' => 'Updated supplier',
        'code' => 'oleva_01',
    ]), $supplier);
    $exception = captureSupplierCodeValidationException(fn (): Supplier => $action->handle(
        $owner,
        $workspace,
        supplierCodeAttributes(['code' => 'other_01']),
        $supplier,
    ));

    expect($updated->code)->toBe('OLEVA_01')
        ->and($exception?->errors())->toBe(['code' => ['The code has already been taken.']])
        ->and($otherSupplier->fresh()?->code)->toBe('OTHER_01');
});

it('preserves the public id when editing a supplier code', function (): void {
    [$owner, $workspace] = activeSupplierCodeWorkspace();
    $action = app(SaveSupplier::class);
    $supplier = $action->handle($owner, $workspace, supplierCodeAttributes(['code' => 'OLEVA_01']));
    $publicId = $supplier->public_id;

    $updated = $action->handle($owner, $workspace, supplierCodeAttributes(['code' => 'OLEVA_02']), $supplier);

    expect($updated->code)->toBe('OLEVA_02')
        ->and($updated->public_id)->toBe($publicId);
});

it('keeps supplier actions isolated to their workspace', function (): void {
    [$owner, $workspace] = activeSupplierCodeWorkspace();
    $foreignWorkspace = Workspace::factory()->create();
    $foreignSupplier = Supplier::factory()->for($foreignWorkspace)->create();

    $exception = captureSupplierCodeValidationException(fn (): Supplier => app(SaveSupplier::class)->handle(
        $owner,
        $workspace,
        supplierCodeAttributes(['code' => 'LOCAL_01']),
        $foreignSupplier,
    ));

    expect($exception?->errors())->toBe([
        'supplier' => ['The supplier does not belong to this workspace.'],
    ])
        ->and($foreignSupplier->fresh()?->code)->toBe($foreignSupplier->code);
});

it('enforces case-insensitive workspace uniqueness at the database level', function (): void {
    $workspace = Workspace::factory()->create();
    Supplier::factory()->for($workspace)->create(['code' => 'OLEVA_01']);

    expect(fn () => DB::table('suppliers')->insert([
        'public_id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'code' => 'oleva_01',
        'name' => 'Duplicate supplier',
        'default_currency' => 'EUR',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/** @return array<string, mixed> */
function supplierCodeAttributes(array $overrides = []): array
{
    return [...[
        'name' => 'Northern Oils',
        'default_currency' => 'EUR',
        'is_active' => true,
    ], ...$overrides];
}

/** @return array{0: User, 1: Workspace} */
function activeSupplierCodeWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    return [$owner, $workspace];
}

function captureSupplierCodeValidationException(Closure $callback): ?ValidationException
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception;
    }

    return null;
}

function supplierCodeMigration(): Migration
{
    return require database_path('migrations/2026_08_01_100000_add_code_to_suppliers_table.php');
}
