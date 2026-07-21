<?php

return [
    'sources' => [
        'account' => ['*'],
        'dashboard' => ['*'],
        'formula_documents' => ['*'],
        'ingredients' => ['*'],
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
