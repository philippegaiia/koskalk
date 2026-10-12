<?php

return [
    'connection' => env('EXPORT_QUEUE_CONNECTION', 'database'),
    'queue' => env('EXPORT_QUEUE', 'exports'),
    'disk' => env('EXPORT_DISK', 'local'),
    'retention_hours' => 24,
    'maximum_pending_per_workspace' => 3,
    'maximum_retained_per_workspace' => 10,
    'maximum_bytes' => 25 * 1024 * 1024,
];
