<?php

namespace Tests\Support;

use RuntimeException;

final class TestDatabaseSafety
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public static function assertSafe(array $configuration): void
    {
        $databaseConfiguration = $configuration['database'] ?? [];
        $connection = is_array($databaseConfiguration)
            ? ($databaseConfiguration['default'] ?? null)
            : null;
        $connections = is_array($databaseConfiguration)
            ? ($databaseConfiguration['connections'] ?? [])
            : [];
        $connectionConfiguration = is_string($connection) && is_array($connections)
            ? ($connections[$connection] ?? [])
            : [];
        $database = is_array($connectionConfiguration)
            ? ($connectionConfiguration['database'] ?? null)
            : null;

        if ($connection === 'sqlite' && $database === ':memory:') {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against [%s:%s]. Tests must use SQLite [:memory:]. Clear the Laravel configuration cache before running Pest.',
            is_scalar($connection) ? (string) $connection : get_debug_type($connection),
            is_scalar($database) ? (string) $database : get_debug_type($database),
        ));
    }
}
