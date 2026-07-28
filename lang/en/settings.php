<?php

return [
    'page' => [
        'title' => 'Settings',
        'intro' => 'Manage your display preferences and workspace settings.',
    ],
    'tabs' => [
        'preferences' => 'Preferences',
        'workspace' => 'Workspace',
    ],
    'preferences' => [
        'heading' => 'Display preferences',
        'description' => 'Choose how Soapkraft displays language and numbers for your account.',
    ],
    'workspace' => [
        'heading' => 'Workspace settings',
        'description' => 'Manage the shared defaults used for products, ingredients, packaging, and costing in this workspace.',
        'owner_help' => 'Only the workspace owner can change these settings.',
        'name' => 'Workspace name',
        'default_currency' => 'Default currency',
        'currency_search' => 'Search currencies',
        'currency_help' => 'Used by default for costing and pricing in this workspace.',
        'mass_display' => 'Measurement system',
        'mass_display_help' => 'Sets the starting unit for new formulas and the ingredient price basis. You can still convert any formula between g, kg, oz, and lb.',
        'mass_systems' => [
            'metric' => 'Metric',
            'metric_example' => 'g and kg · prices per kg',
            'us_customary' => 'US customary',
            'us_customary_example' => 'oz and lb · prices per lb',
        ],
    ],
    'actions' => [
        'save_preferences' => 'Save preferences',
        'save_workspace' => 'Save workspace settings',
    ],
    'status' => [
        'preferences_saved' => 'Preferences saved.',
        'workspace_saved' => 'Workspace settings saved.',
    ],
];
