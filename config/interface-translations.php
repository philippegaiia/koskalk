<?php

return [
    'sources' => [
        'navigation' => ['*'],
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
