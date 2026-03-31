<?php

return [
    'ingredients' => [
        'path' => env('CATALOG_IMPORT_INGREDIENTS_PATH', '/Users/philippe/Downloads/export-3-ingredients.csv'),
        'source_name' => env('CATALOG_IMPORT_INGREDIENTS_SOURCE', 'initial_platform_ingredient_catalog'),
    ],

    'carrier_oil_chemistry' => [
        'path' => env(
            'CATALOG_IMPORT_CARRIER_OIL_CHEMISTRY_PATH',
            database_path('seeders/data/carrier_oil_chemistry.json')
        ),
    ],

    'allergens' => [
        'path' => env(
            'CATALOG_IMPORT_ALLERGENS_PATH',
            '/Users/philippe/Downloads/Allergènes et Huiles Essentielles _ Étiquetage Cosmétique - Allergènes et Huiles Essentielles _ Étiquetage Cosmétique.csv'
        ),
        'source_name' => env('CATALOG_IMPORT_ALLERGENS_SOURCE', 'EU allergen list'),
    ],
];
