<?php

return [
    'ingredients' => [
        'path' => env('CATALOG_IMPORT_INGREDIENTS_PATH', '/Users/philippe/Downloads/export-3-ingredients.csv'),
        'source_name' => env('CATALOG_IMPORT_INGREDIENTS_SOURCE', 'initial_platform_ingredient_catalog'),
    ],

    'allergens' => [
        'path' => env(
            'CATALOG_IMPORT_ALLERGENS_PATH',
            '/Users/philippe/Downloads/Allergènes et Huiles Essentielles _ Étiquetage Cosmétique - Allergènes et Huiles Essentielles _ Étiquetage Cosmétique.csv'
        ),
        'source_name' => env('CATALOG_IMPORT_ALLERGENS_SOURCE', 'EU allergen list'),
    ],
];
