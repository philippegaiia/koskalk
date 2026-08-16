<?php

use Tests\Support\TestDatabaseSafety;

it('allows tests to use an in-memory SQLite database', function (): void {
    expect(fn () => TestDatabaseSafety::assertSafe([
        'database' => [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => ['database' => ':memory:'],
            ],
        ],
    ]))->not->toThrow(Throwable::class);
});

it('allows an explicitly flagged disposable PostgreSQL index database', function (): void {
    expect(fn () => TestDatabaseSafety::assertSafe([
        'database' => [
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => ['database' => 'koskalk_fk_index_roundtrip_example'],
            ],
        ],
    ], allowDisposablePostgres: true))->not->toThrow(Throwable::class);
});

it('refuses any persistent or non-SQLite test database', function (): void {
    foreach ([
        ['pgsql', 'koskalk'],
        ['sqlite', '/tmp/koskalk-test.sqlite'],
    ] as [$connection, $database]) {
        expect(fn () => TestDatabaseSafety::assertSafe([
            'database' => [
                'default' => $connection,
                'connections' => [
                    $connection => ['database' => $database],
                ],
            ],
        ]))->toThrow(RuntimeException::class, 'Refusing to run tests');
    }
});

it('refuses a flagged PostgreSQL database outside the disposable namespace', function (): void {
    expect(fn () => TestDatabaseSafety::assertSafe([
        'database' => [
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => ['database' => 'koskalk'],
            ],
        ],
    ], allowDisposablePostgres: true))->toThrow(RuntimeException::class, 'Refusing to run tests');
});

it('refuses a safe-looking PostgreSQL database overridden by a production URL', function (): void {
    expect(fn () => TestDatabaseSafety::assertSafe([
        'database' => [
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'database' => 'koskalk_fk_index_roundtrip_example',
                    'url' => 'postgres://user:secret@database.example/koskalk',
                ],
            ],
        ],
    ], allowDisposablePostgres: true))->toThrow(RuntimeException::class, 'Refusing to run tests');
});

it('refuses an in-memory SQLite database overridden by a persistent URL', function (): void {
    expect(fn () => TestDatabaseSafety::assertSafe([
        'database' => [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'url' => 'sqlite:///tmp/koskalk.sqlite',
                ],
            ],
        ],
    ]))->toThrow(RuntimeException::class, 'Refusing to run tests');
});
