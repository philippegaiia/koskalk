<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FormulaSharePostgresDatabase;
use Tests\Support\TestDatabaseSafety;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();
        $allowFormulaSharingPostgres = filter_var(env('VERIFY_FORMULA_SHARING_POSTGRES', false), FILTER_VALIDATE_BOOL);
        $expectedFormulaSharingDatabase = env('FORMULA_SHARING_POSTGRES_DATABASE');

        TestDatabaseSafety::assertSafe(
            $application['config']->all(),
            allowDisposablePostgres: filter_var(env('VERIFY_POSTGRESQL_INDEXES', false), FILTER_VALIDATE_BOOL),
            allowFormulaSharingPostgres: $allowFormulaSharingPostgres,
            expectedFormulaSharingDatabase: is_string($expectedFormulaSharingDatabase) ? $expectedFormulaSharingDatabase : null,
        );
        if ($allowFormulaSharingPostgres && $application['db']->connection()->getDriverName() === 'pgsql'
            && $application['db']->selectOne('SELECT current_database() AS name')->name !== $expectedFormulaSharingDatabase) {
            throw new \RuntimeException('Refusing to run tests: the PostgreSQL server database does not match the expected disposable identity.');
        }
        if ($allowFormulaSharingPostgres && $application['db']->connection()->getDriverName() === 'pgsql') {
            FormulaSharePostgresDatabase::reset(once: true);
        }

        return $application;
    }
}
