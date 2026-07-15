<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-07-15 02:30:00 UTC');

    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => '127.0.0.1',
        'database.connections.pgsql.port' => '5432',
        'database.connections.pgsql.database' => 'soapkraft',
        'database.connections.pgsql.username' => 'soapkraft_app',
        'database.connections.pgsql.password' => 'database-secret',
        'database.connections.pgsql.sslmode' => 'prefer',
        'database_backup.disk' => 'r2_backups',
        'database_backup.prefix' => 'postgresql',
        'database_backup.filename_prefix' => 'soapkraft',
        'database_backup.pg_dump_binary' => 'pg_dump',
        'database_backup.timeout' => 900,
    ]);

    Storage::fake('r2_backups');
    Process::preventStrayProcesses();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('creates a PostgreSQL dump, uploads it, verifies it, and removes the temporary file', function () {
    $temporaryPath = null;

    Process::fake(function (PendingProcess $process) use (&$temporaryPath) {
        $fileArgument = collect($process->command)
            ->first(fn (string $argument): bool => str_starts_with($argument, '--file='));
        $temporaryPath = substr((string) $fileArgument, strlen('--file='));

        expect(fileperms($temporaryPath) & 0777)->toBe(0600);

        file_put_contents($temporaryPath, 'valid-postgresql-dump');

        return Process::result();
    });

    $this->artisan('backup:database')->assertSuccessful();

    $objectKey = collect(Storage::disk('r2_backups')->allFiles('postgresql/2026/07/15'))->sole();

    Storage::disk('r2_backups')->assertExists($objectKey);
    expect($objectKey)->toMatch('/^postgresql\/2026\/07\/15\/soapkraft-20260715T023000Z-[0-9A-HJKMNP-TV-Z]{26}\.dump$/')
        ->and(Storage::disk('r2_backups')->size($objectKey))->toBe(21)
        ->and($temporaryPath)->not->toBeNull()
        ->and(file_exists((string) $temporaryPath))->toBeFalse();

    Process::assertRan(function (PendingProcess $process): bool {
        return is_array($process->command)
            && $process->command === [
                'pg_dump',
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--host=127.0.0.1',
                '--port=5432',
                '--username=soapkraft_app',
                '--dbname=soapkraft',
                $process->command[8],
            ]
            && str_starts_with($process->command[8], '--file=')
            && ! str_contains(implode(' ', $process->command), 'database-secret')
            && $process->environment['PGPASSWORD'] === 'database-secret'
            && $process->environment['PGSSLMODE'] === 'prefer'
            && $process->timeout === 900;
    });
});

it('fails without uploading and removes the temporary file when pg dump fails', function () {
    $temporaryPath = null;

    Process::fake(function (PendingProcess $process) use (&$temporaryPath) {
        $fileArgument = collect($process->command)
            ->first(fn (string $argument): bool => str_starts_with($argument, '--file='));
        $temporaryPath = substr((string) $fileArgument, strlen('--file='));
        file_put_contents($temporaryPath, 'partial-dump');

        return Process::result(errorOutput: 'connection failed', exitCode: 1);
    });

    $this->artisan('backup:database')
        ->expectsOutputToContain('Database backup failed')
        ->assertFailed();

    Storage::disk('r2_backups')->assertDirectoryEmpty('/');
    expect($temporaryPath)->not->toBeNull()
        ->and(file_exists((string) $temporaryPath))->toBeFalse();
});

it('rejects an empty database dump', function () {
    Process::fake(function (PendingProcess $process) {
        $fileArgument = collect($process->command)
            ->first(fn (string $argument): bool => str_starts_with($argument, '--file='));
        $temporaryPath = substr((string) $fileArgument, strlen('--file='));
        touch($temporaryPath);

        return Process::result();
    });

    $this->artisan('backup:database')
        ->expectsOutputToContain('Database backup failed')
        ->assertFailed();

    Storage::disk('r2_backups')->assertDirectoryEmpty('/');
});

it('removes an uploaded object when remote verification fails', function () {
    $temporaryPath = null;

    Process::fake(function (PendingProcess $process) use (&$temporaryPath) {
        $fileArgument = collect($process->command)
            ->first(fn (string $argument): bool => str_starts_with($argument, '--file='));
        $temporaryPath = substr((string) $fileArgument, strlen('--file='));
        file_put_contents($temporaryPath, 'valid-postgresql-dump');

        return Process::result();
    });

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('writeStream')->once()->andReturnTrue();
    $disk->shouldReceive('exists')->once()->andReturnTrue();
    $disk->shouldReceive('size')->once()->andReturn(1);
    $disk->shouldReceive('delete')->once()->andReturnTrue();

    Storage::shouldReceive('disk')
        ->once()
        ->with('r2_backups')
        ->andReturn($disk);

    $this->artisan('backup:database')
        ->expectsOutputToContain('Database backup failed')
        ->assertFailed();

    expect($temporaryPath)->not->toBeNull()
        ->and(file_exists((string) $temporaryPath))->toBeFalse();
});

it('attempts remote cleanup when an upload reports failure', function () {
    $temporaryPath = null;

    Process::fake(function (PendingProcess $process) use (&$temporaryPath) {
        $fileArgument = collect($process->command)
            ->first(fn (string $argument): bool => str_starts_with($argument, '--file='));
        $temporaryPath = substr((string) $fileArgument, strlen('--file='));
        file_put_contents($temporaryPath, 'valid-postgresql-dump');

        return Process::result();
    });

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('writeStream')->once()->andReturnFalse();
    $disk->shouldReceive('delete')->once()->andReturnTrue();

    Storage::shouldReceive('disk')
        ->once()
        ->with('r2_backups')
        ->andReturn($disk);

    $this->artisan('backup:database')
        ->expectsOutputToContain('Database backup failed')
        ->assertFailed();

    expect($temporaryPath)->not->toBeNull()
        ->and(file_exists((string) $temporaryPath))->toBeFalse();
});

it('keeps every R2 bucket on isolated credentials', function () {
    expect(config('filesystems.disks.r2_public.key'))->toBe(env('R2_PUBLIC_ACCESS_KEY_ID'))
        ->and(config('filesystems.disks.r2_public.secret'))->toBe(env('R2_PUBLIC_SECRET_ACCESS_KEY'))
        ->and(config('filesystems.disks.r2_private.key'))->toBe(env('R2_PRIVATE_ACCESS_KEY_ID'))
        ->and(config('filesystems.disks.r2_private.secret'))->toBe(env('R2_PRIVATE_SECRET_ACCESS_KEY'))
        ->and(config('filesystems.disks.r2_backups.key'))->toBe(env('R2_BACKUP_ACCESS_KEY_ID'))
        ->and(config('filesystems.disks.r2_backups.secret'))->toBe(env('R2_BACKUP_SECRET_ACCESS_KEY'))
        ->and(config('filesystems.disks.r2_public.supports_visibility'))->toBeFalse()
        ->and(config('filesystems.disks.r2_private.supports_visibility'))->toBeFalse()
        ->and(config('filesystems.disks.r2_backups.supports_visibility'))->toBeFalse()
        ->and(config('filesystems.disks.r2_private'))->not->toHaveKey('url')
        ->and(config('filesystems.disks.r2_backups'))->not->toHaveKey('url');
});

it('schedules the database backup once daily in production without overlap', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command, 'backup:database'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 2 * * *')
        ->and($event->timezone)->toBe('UTC')
        ->and($event->environments)->toBe(['production'])
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(120)
        ->and($event->onOneServer)->toBeTrue();
});
