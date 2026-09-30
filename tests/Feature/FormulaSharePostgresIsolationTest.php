<?php

use App\Models\User;
use App\Services\FormulaShareTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires explicit formula-sharing PostgreSQL opt-in and the expected disposable database identity.');
    }
});

it('rejects a nested read committed sharing transaction before executing its operation and preserves parent isolation', function (): void {
    DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $before = DB::selectOne('SHOW transaction_isolation')->transaction_isolation;
    expect($before)->toBe('read committed');
    $called = false;
    $actor = User::factory()->make(['id' => -1]);

    expect(fn () => app(FormulaShareTransaction::class)->run($actor, [], function () use (&$called): void {
        $called = true;
    }))->toThrow(RuntimeException::class, 'Formula sharing inside a transaction requires repeatable-read or serializable isolation.');
    expect($called)->toBeFalse()
        ->and(DB::selectOne('SHOW transaction_isolation')->transaction_isolation)->toBe($before)
        ->and(DB::transactionLevel())->toBe(1);
});

it('accepts a supported nested PostgreSQL isolation level without changing the parent setting', function (string $isolation): void {
    DB::statement('SET TRANSACTION ISOLATION LEVEL '.$isolation);
    $actor = User::factory()->create();
    $result = app(FormulaShareTransaction::class)->run($actor, [], fn (): string => DB::selectOne('SHOW transaction_isolation')->transaction_isolation);

    expect($result)->toBe(strtolower($isolation))
        ->and(DB::selectOne('SHOW transaction_isolation')->transaction_isolation)->toBe($result)
        ->and(DB::transactionLevel())->toBe(1);
})->with(['REPEATABLE READ', 'SERIALIZABLE']);
