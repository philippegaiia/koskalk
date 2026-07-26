<?php

return [
    'sources' => [
        'account' => ['*'],
        'dashboard' => ['*'],
        'formula_documents' => ['*'],
        'ingredients' => ['*'],
        'media' => ['*'],
        'media_library' => ['*'],
        'navigation' => ['*'],
        'packaging' => ['*'],
        'products' => ['*'],
        'settings' => ['*'],
        'table' => ['*'],
        'workbench' => ['*'],
        'number_formats' => ['*'],
        'public' => ['*'],
        'auth' => [
            'password_requirements',
            'password_optional_reset',
            'login.*',
            'verification.*',
        ],
    ],
];
