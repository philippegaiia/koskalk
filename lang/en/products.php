<?php

return [
    'page' => [
        'title' => 'Products',
        'heading' => 'Manage your products.',
        'intro' => 'Create and manage finished products, including their formulas, packaging, and saved versions.',
        'aria_label' => 'Products overview',
    ],
    'auth' => [
        'aria_label' => 'Sign in required',
        'heading' => 'Sign in to view your products',
        'description' => 'Open this page from your signed-in account to create and manage products.',
    ],
    'actions' => [
        'new_product' => 'New product',
        'new_soap' => 'New soap product',
        'new_cosmetic' => 'New cosmetic product',
        'clear_filters' => 'Clear filters',
        'open_workbench' => 'Open workbench',
        'view_formula_production' => 'View formula & production',
        'duplicate' => 'Duplicate product',
        'lock' => 'Lock product',
        'unlock' => 'Unlock product',
        'archive' => 'Archive product',
        'restore' => 'Restore product',
        'delete' => 'Delete product',
        'use_name' => 'Use product name',
        'delete_permanently' => 'Delete permanently',
        'cancel' => 'Cancel',
    ],
    'creation' => [
        'entries' => [
            'soap' => [
                'name' => 'Soap',
                'description' => 'Oils + lye',
            ],
            'cosmetics' => [
                'name' => 'Cosmetics',
                'description' => 'Skin, hair, melt-and-pour and syndets',
            ],
            'home' => [
                'name' => 'Home',
                'description' => 'Candles, cleaning and laundry',
            ],
        ],
        'start' => [
            'title' => 'New product',
            'heading' => 'Create a product',
            'description' => 'Choose the kind of product you want to formulate.',
            'aria_label' => 'Product creation options',
        ],
        'selector' => [
            'title' => 'New :entry product',
            'heading' => 'New :entry product',
            'choose' => 'Choose a product type',
            'description' => 'You can name and complete the formula in the workbench.',
            'back' => 'Back to product kinds',
            'fallback_description' => 'Open a blank formula for this product type.',
        ],
    ],
    'filters' => [
        'aria_label' => 'Product filters',
        'search' => [
            'label' => 'Search',
            'placeholder' => 'Product name, area, category, or type',
            'aria_label' => 'Search products',
        ],
        'area' => [
            'label' => 'Area',
            'all' => 'All areas',
        ],
        'category' => [
            'label' => 'Category',
            'all' => 'All categories',
        ],
        'type' => [
            'label' => 'Type',
            'all' => 'All types',
        ],
        'status' => [
            'label' => 'Status',
            'all' => 'All statuses',
            'active' => 'Active',
            'archived' => 'Archived',
        ],
    ],
    'count' => [
        'all' => '{0} 0 products|{1} :count product|[2,*] :count products',
        'matching' => '{0} 0 matching products|{1} :count matching product|[2,*] :count matching products',
    ],
    'empty' => [
        'no_matches' => 'No products match these filters',
        'try_again' => 'Try another product name, area, category, or type.',
        'no_items' => 'No products yet',
        'description' => 'Create your first soap, cosmetic, or home product, then build its formula and packaging in the workbench.',
    ],
    'card' => [
        'unclassified' => 'Unclassified product',
        'locked' => 'Locked',
        'updated' => 'Updated :time',
        'just_now' => 'just now',
        'production_count' => '{0} 0 productions|{1} :count production|[2,*] :count productions',
    ],
    'accessibility' => [
        'actions' => 'Actions for :product',
    ],
    'deletion' => [
        'heading' => 'Delete “:product”?',
        'warning' => 'This permanently deletes the product, its current formula, and all saved versions. This cannot be undone.',
        'history_note' => 'Production history is fully snapshotted and stays readable.',
        'confirmation_placeholder' => 'Enter the product name to confirm',
    ],
    'archiving' => [
        'heading' => 'Archive “:product”?',
        'warning' => 'The active formula leaves the Workbench and the product disappears from new production selectors. Production history remains fully readable, and the product can be restored at any time.',
    ],
    'status' => [
        'duplicated' => 'Product duplicated.',
        'locked' => 'Product locked.',
        'unlocked' => 'Product unlocked.',
        'deleted' => 'Product deleted.',
        'archived' => 'Product archived.',
        'restored' => 'Product restored.',
        'archive_required' => 'Archive this product before deleting it permanently.',
        'delete_blocked_incomplete_snapshot' => 'This product cannot be deleted yet because some productions still depend on its formula versions.',
        'version_deleted' => 'Version deleted.',
        'last_version_deleted' => 'Last saved version deleted. This product has no saved versions.',
    ],
    'validation' => [
        'confirmation_mismatch' => 'The confirmation name does not match.',
    ],
];
