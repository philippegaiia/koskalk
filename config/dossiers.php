<?php

return [
    /*
     * DIP evidence is a retained legal record (10 years): it must live off the server, like media assets.
     * Falls back to the media asset disk (private R2 in production) unless set explicitly.
     */
    'evidence_disk' => env('DOSSIER_EVIDENCE_DISK', env('MEDIA_ASSET_DISK', env('RECIPE_MEDIA_DISK', 'local'))),
    'evidence_directory' => 'dossier-evidence',
    'evidence_max_kb' => 10240,
    'evidence_mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
];
