<?php

return [

    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),

    'visibility' => env('MEDIA_VISIBILITY', 'public'),

    'recipe_disk' => env('RECIPE_MEDIA_DISK', 'local'),

    'user_disk' => env('USER_MEDIA_DISK', env('RECIPE_MEDIA_DISK', 'local')),

    'recipe_visibility' => 'private',

    'recipe_featured_images' => [
        'max_size_kb' => 1024,
        'max_width' => 800,
        'max_height' => 600,
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
