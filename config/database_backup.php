<?php

return [

    'connection' => env('DATABASE_BACKUP_CONNECTION', 'pgsql'),

    'disk' => env('DATABASE_BACKUP_DISK', 'r2_backups'),

    'prefix' => env('DATABASE_BACKUP_PREFIX', 'postgresql'),

    'filename_prefix' => env('DATABASE_BACKUP_FILENAME_PREFIX', 'soapkraft'),

    'pg_dump_binary' => env('PG_DUMP_BINARY', 'pg_dump'),

    'timeout' => (int) env('DATABASE_BACKUP_TIMEOUT', 900),

];
