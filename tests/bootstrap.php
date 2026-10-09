<?php

use Tests\Support\TestDatabaseSafety;

require_once dirname(__DIR__).'/vendor/autoload.php';

$cachedConfigurationPath = dirname(__DIR__).'/bootstrap/cache/config.php';

if (is_file($cachedConfigurationPath)) {
    $cachedConfiguration = require $cachedConfigurationPath;

    if (! is_array($cachedConfiguration)) {
        throw new RuntimeException('Refusing to run tests because the Laravel configuration cache is invalid.');
    }

    TestDatabaseSafety::assertSafe(
        $cachedConfiguration,
        allowDisposablePostgres: filter_var(
            $_SERVER['VERIFY_POSTGRESQL_INDEXES']
                ?? $_ENV['VERIFY_POSTGRESQL_INDEXES']
                ?? getenv('VERIFY_POSTGRESQL_INDEXES'),
            FILTER_VALIDATE_BOOL,
        ),
        allowFormulaSharingPostgres: filter_var(
            $_SERVER['VERIFY_FORMULA_SHARING_POSTGRES'] ?? $_ENV['VERIFY_FORMULA_SHARING_POSTGRES'] ?? getenv('VERIFY_FORMULA_SHARING_POSTGRES'),
            FILTER_VALIDATE_BOOL,
        ),
        expectedFormulaSharingDatabase: ($expectedFormulaSharingDatabase = $_SERVER['FORMULA_SHARING_POSTGRES_DATABASE'] ?? $_ENV['FORMULA_SHARING_POSTGRES_DATABASE'] ?? getenv('FORMULA_SHARING_POSTGRES_DATABASE')) !== false
            ? $expectedFormulaSharingDatabase : null,
    );
}
