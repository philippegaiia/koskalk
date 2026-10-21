<?php

return [
    /*
     * DIP evidence is a retained legal record (10 years): it must live off the server, like media assets.
     * Falls back to the media asset disk (private R2 in production) unless set explicitly.
     */
    'evidence_disk' => env('DOSSIER_EVIDENCE_DISK', env('MEDIA_ASSET_DISK', env('RECIPE_MEDIA_DISK', 'local'))),
    'evidence_directory' => 'dossier-evidence',

    // PDFs are stored as received. Real supplier documents measured 2026-10-21: 99% under 825 KB, largest 1.38 MB.
    'evidence_pdf_max_kb' => 2048,

    // Images are accepted up to this size, then compressed to WebP before storage (owner decision, 2026-10-21).
    'evidence_image_upload_max_kb' => 10240,
    'evidence_image_max_edge' => 2000,
    'evidence_image_quality' => 85,

    'evidence_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
];
