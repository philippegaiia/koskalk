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
