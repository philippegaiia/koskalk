<?php

return [
    'evidence_disk' => env('DOSSIER_EVIDENCE_DISK', 'local'),
    'evidence_directory' => 'dossier-evidence',
    'evidence_max_kb' => 10240,
    'evidence_mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
];
