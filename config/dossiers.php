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

    /*
     * Ingredient tracing (spec §4.1). Formula inputs carry 4-decimal gram values, so differences within this many
     * grams are representation only. A tolerance never absorbs an unexplained difference.
     */
    'mass_balance_tolerance_g' => '0.001',
    // The verifier refuses any tolerance above this bound: a larger one could hide a displaced ingredient.
    'mass_balance_tolerance_max_g' => '0.01',
    'percent_tolerance' => '0.000001',

    // INCI names under which these final soap materials are found in the substance catalogue.
    'final_material_inci' => [
        'glycerin' => 'GLYCERIN',
        'residual_water' => 'AQUA',
    ],
];
