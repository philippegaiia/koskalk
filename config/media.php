<?php

return [

    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),

    'visibility' => env('MEDIA_VISIBILITY', 'public'),

    'recipe_featured_images' => [
        'max_size_kb' => 1024,
        'max_width' => 1200,
        'max_height' => 900,
        'quality' => 85,
    ],

    'recipe_rich_content_images' => [
        'max_size_kb' => 1536,
        'max_width' => 1600,
        'max_height' => 1600,
        'quality' => 85,
    ],

    'ingredient_images' => [
        'max_size_kb' => 2048,
        'width' => 800,
        'height' => 800,
        'quality' => 85,
    ],

    'ingredient_icons' => [
        'max_size_kb' => 1024,
        'width' => 96,
        'height' => 96,
        'quality' => 85,
    ],
];
