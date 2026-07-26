<?php

return [

    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),

    'visibility' => env('MEDIA_VISIBILITY', 'public'),

    'recipe_disk' => env('RECIPE_MEDIA_DISK', 'local'),

    'user_disk' => env('USER_MEDIA_DISK', env('RECIPE_MEDIA_DISK', 'local')),

    'asset_disk' => env('MEDIA_ASSET_DISK', env('RECIPE_MEDIA_DISK', 'local')),

    'asset_pending_disk' => env('MEDIA_ASSET_PENDING_DISK', 'local'),

    'asset_uploads' => [
        'max_size_kb' => 10240,
        'max_pixels' => 25_000_000,
        'master_max_edge' => 800,
        'quality' => 85,
        'accepted_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'],
        'accepted_image_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'],
        'accepted_document_extensions' => ['pdf'],
        'pdf' => [
            'max_pages' => 50,
            'pdfinfo_binary' => env('PDFINFO_BINARY', 'pdfinfo'),
            'pdftoppm_binary' => env('PDFTOPPM_BINARY', 'pdftoppm'),
            'process_timeout' => 30,
        ],
    ],

    'recipe_visibility' => 'private',

    'recipe_featured_images' => [
        'max_size_kb' => 3072,
        'max_width' => 800,
        'max_height' => 800,
        'quality' => 82,
    ],

    'recipe_rich_content_images' => [
        'max_size_kb' => 1536,
        'max_width' => 680,
        'max_height' => 680,
        'quality' => 80,
    ],

    'ingredient_images' => [
        'max_size_kb' => 2048,
        'width' => 400,
        'height' => 400,
        'quality' => 85,
    ],

    'ingredient_icons' => [
        'max_size_kb' => 1024,
        'width' => 96,
        'height' => 96,
        'quality' => 85,
    ],
];
