<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FormulaSharePostgresDatabase
{
    private static bool $reset = false;

    public static function reset(bool $once = false): void
    {
        if ($once && self::$reset) {
            return;
        }
        $allowed = filter_var(env('VERIFY_FORMULA_SHARING_POSTGRES', false), FILTER_VALIDATE_BOOL);
        $expected = env('FORMULA_SHARING_POSTGRES_DATABASE');
        TestDatabaseSafety::assertSafe(config()->all(), allowFormulaSharingPostgres: $allowed, expectedFormulaSharingDatabase: is_string($expected) ? $expected : null);
        if (! $allowed || DB::getDriverName() !== 'pgsql' || DB::transactionLevel() !== 0 || DB::selectOne('SELECT current_database() AS name')->name !== $expected) {
            throw new RuntimeException('Refusing to reset a PostgreSQL schema without its explicit disposable identity and an outer connection.');
        }
        DB::transaction(function (): void {
            DB::statement('DROP SCHEMA public CASCADE');
            DB::statement('CREATE SCHEMA public');
        });
        self::$reset = true;
    }
}
