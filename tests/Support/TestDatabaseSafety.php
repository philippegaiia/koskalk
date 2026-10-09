<?php

namespace Tests\Support;

use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

final class TestDatabaseSafety
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public static function assertSafe(
        array $configuration,
        bool $allowDisposablePostgres = false,
        bool $allowFormulaSharingPostgres = false,
        ?string $expectedFormulaSharingDatabase = null,
    ): void {
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

        if ($allowFormulaSharingPostgres && $driver === 'pgsql' && is_string($database)
            && preg_match('/^koskalk_formula_sharing_test_\d{8}(?:_[a-z0-9]+)*$/D', $database) === 1
            && $expectedFormulaSharingDatabase === $database) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against [%s:%s]. Tests must use SQLite [:memory:] unless explicitly opted into a disposable PostgreSQL index database, or a koskalk_formula_sharing_test_YYYYMMDD database matching the expected identity. Clear the Laravel configuration cache before running Pest.',
            is_scalar($driver) ? (string) $driver : get_debug_type($driver),
            is_scalar($database) ? (string) $database : get_debug_type($database),
        ));
    }
}
