<?php

return [
    'catalog' => [
        'free-beta' => [
            'name' => 'Free beta',
            'description' => 'Free registered launch plan. Limits remain admin-editable.',
            'price_label' => '',
        ],
    ],
    'features' => [
        'title' => 'Features',
        'collaboration' => 'Shared workspace collaboration',
        'production_bench' => 'Production Bench provisioning',
        'production_bench_help' => 'Allows new Production Bench grants. Existing active or cancelled grants are preserved.',
        'enabled' => 'Enabled',
        'disabled' => 'Disabled',
        'not_configured' => 'Not configured (not granted)',
    ],
    'limits' => [
        'description' => 'Leave a value empty for no hard limit. Free plans start at 30 ingredient lines per formula; billable plans start at 50. All limits remain admin-editable.',
        'formula_items_per_recipe' => 'Ingredient lines per formula',
        'saved_formula_history' => 'Saved formula history',
        'workspace_members' => 'Workspace members (including owner)',
        'value' => 'Limit',
        'empty_unlimited' => 'Empty means unlimited.',
    ],
];
