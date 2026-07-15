<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PostgreSqlBackupService
{
    /** @return array{object_key: string, size: int, sha256: string} */
    public function create(): array
    {
        $connectionName = (string) config('database_backup.connection', 'pgsql');
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Database backups require a PostgreSQL connection.');
        }

        $temporaryDirectory = storage_path('app/private/database-backups');
        File::ensureDirectoryExists($temporaryDirectory, 0700);
        $temporaryPath = tempnam($temporaryDirectory, 'pgsql-backup-');

        if (! is_string($temporaryPath)) {
            throw new RuntimeException('Unable to create a temporary backup file.');
        }

        try {
            if (! chmod($temporaryPath, 0600)) {
                throw new RuntimeException('Unable to secure the temporary backup file.');
            }

            $this->dump($connection, $temporaryPath);
            $size = filesize($temporaryPath);

            if (! is_int($size) || $size < 1) {
                throw new RuntimeException('PostgreSQL produced an empty database backup.');
            }

            $sha256 = hash_file('sha256', $temporaryPath);

            if (! is_string($sha256)) {
                throw new RuntimeException('Unable to hash the database backup.');
            }

            $objectKey = $this->objectKey();
            $disk = Storage::disk((string) config('database_backup.disk', 'r2_backups'));

            try {
                $stream = fopen($temporaryPath, 'rb');

                if (! is_resource($stream)) {
                    throw new RuntimeException('Unable to open the database backup for upload.');
                }

                try {
                    if (! $disk->writeStream($objectKey, $stream, ['ContentType' => 'application/octet-stream'])) {
                        throw new RuntimeException('The database backup upload failed.');
                    }
                } finally {
                    fclose($stream);
                }

                if (! $disk->exists($objectKey) || $disk->size($objectKey) !== $size) {
                    throw new RuntimeException('The uploaded database backup could not be verified.');
                }

                $remoteStream = $disk->readStream($objectKey);

                if (! is_resource($remoteStream)) {
                    throw new RuntimeException('The uploaded database backup could not be read for verification.');
                }

                try {
                    $remoteHash = hash_init('sha256');
                    hash_update_stream($remoteHash, $remoteStream);
                    $remoteSha256 = hash_final($remoteHash);
                } finally {
                    fclose($remoteStream);
                }

                if (! hash_equals($sha256, $remoteSha256)) {
                    throw new RuntimeException('The uploaded database backup checksum did not match.');
                }
            } catch (Throwable $exception) {
                $disk->delete($objectKey);

                throw $exception;
            }

            return [
                'object_key' => $objectKey,
                'size' => $size,
                'sha256' => $sha256,
            ];
        } finally {
            File::delete($temporaryPath);
        }
    }

    /** @param array<string, mixed> $connection */
    private function dump(array $connection, string $temporaryPath): void
    {
        $environment = [
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
        ];

        if (filled($connection['sslmode'] ?? null)) {
            $environment['PGSSLMODE'] = (string) $connection['sslmode'];
        }

        $result = Process::timeout((int) config('database_backup.timeout', 900))
            ->env($environment)
            ->run([
                (string) config('database_backup.pg_dump_binary', 'pg_dump'),
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '5432'),
                '--username='.(string) ($connection['username'] ?? ''),
                '--dbname='.(string) ($connection['database'] ?? ''),
                '--file='.$temporaryPath,
            ]);

        if ($result->failed()) {
            throw new RuntimeException("pg_dump failed with exit code {$result->exitCode()}.");
        }
    }

    private function objectKey(): string
    {
        $now = now('UTC');
        $prefix = trim((string) config('database_backup.prefix', 'postgresql'), '/');
        $filenamePrefix = Str::slug((string) config('database_backup.filename_prefix', 'soapkraft'));
        $filename = $filenamePrefix.'-'.$now->format('Ymd\THis\Z').'-'.Str::ulid().'.dump';

        return $prefix.'/'.$now->format('Y/m/d').'/'.$filename;
    }
}
