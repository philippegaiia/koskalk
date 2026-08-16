<?php

namespace Tests\Support;

use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

final class TestDatabaseSafety
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public static function assertSafe(array $configuration, bool $allowDisposablePostgres = false): void
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
        $effectiveConfiguration = is_array($connectionConfiguration)
            ? (new ConfigurationUrlParser)->parseConfiguration($connectionConfiguration)
            : [];
        $driver = is_array($effectiveConfiguration)
            ? ($effectiveConfiguration['driver'] ?? $connection)
            : $connection;
        $database = is_array($effectiveConfiguration)
            ? ($effectiveConfiguration['database'] ?? null)
            : null;

        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        if (
            $allowDisposablePostgres
            && $driver === 'pgsql'
            && is_string($database)
            && str_starts_with($database, 'koskalk_fk_index_roundtrip_')
        ) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against [%s:%s]. Tests must use SQLite [:memory:] unless explicit PostgreSQL index verification targets a koskalk_fk_index_roundtrip_* database. Clear the Laravel configuration cache before running Pest.',
            is_scalar($driver) ? (string) $driver : get_debug_type($driver),
            is_scalar($database) ? (string) $database : get_debug_type($database),
        ));
    }
}
