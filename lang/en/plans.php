<?php

return [
    'catalog' => [
        'free-beta' => [
            'name' => 'Free beta',
            'description' => 'Free registered launch plan. Limits remain admin-editable.',
            'price_label' => '',
        ],
    ],
    'limits' => [
        'description' => 'Leave a value empty for no hard limit. Free plans start at 30 ingredient lines per formula; billable plans start at 50. All limits remain admin-editable.',
        'formula_items_per_recipe' => 'Ingredient lines per formula',
        'value' => 'Limit',
        'empty_unlimited' => 'Empty means unlimited.',
    ],
];
