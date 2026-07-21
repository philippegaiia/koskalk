<?php

return [
    'page' => [
        'title' => 'Products',
        'heading' => 'Manage your products.',
        'intro' => 'Create and manage soap and cosmetic products, including their formulas, packaging, and saved versions.',
        'aria_label' => 'Products overview',
    ],
    'auth' => [
        'aria_label' => 'Sign in required',
        'heading' => 'Sign in to view your products',
        'description' => 'Open this page from your signed-in account to create and manage products.',
    ],
    'actions' => [
        'new_soap' => 'New soap product',
        'new_cosmetic' => 'New cosmetic product',
        'clear_filters' => 'Clear filters',
        'open_workbench' => 'Open workbench',
        'view_formula_production' => 'View formula & production',
        'duplicate' => 'Duplicate product',
        'lock' => 'Lock product',
        'unlock' => 'Unlock product',
        'delete' => 'Delete product',
        'use_name' => 'Use product name',
        'delete_permanently' => 'Delete permanently',
        'cancel' => 'Cancel',
    ],
    'filters' => [
        'aria_label' => 'Product filters',
        'search' => [
            'label' => 'Search',
            'placeholder' => 'Product name, category, or type',
            'aria_label' => 'Search products',
        ],
        'category' => [
            'label' => 'Category',
            'all' => 'All categories',
        ],
        'type' => [
            'label' => 'Type',
            'all' => 'All types',
        ],
    ],
    'count' => [
        'all' => '{0} 0 products|{1} :count product|[2,*] :count products',
        'matching' => '{0} 0 matching products|{1} :count matching product|[2,*] :count matching products',
    ],
    'empty' => [
        'no_matches' => 'No products match these filters',
        'try_again' => 'Try another product name, category, or type.',
        'no_items' => 'No products yet',
        'description' => 'Create your first soap or cosmetic product, then build its formula and packaging in the workbench.',
    ],
    'card' => [
        'default_category' => 'Product',
        'locked' => 'Locked',
        'updated' => 'Updated :time',
        'just_now' => 'just now',
    ],
    'accessibility' => [
        'actions' => 'Actions for :product',
    ],
    'deletion' => [
        'heading' => 'Delete “:product”?',
        'warning' => 'This permanently deletes the product, its current formula, and all saved versions. This cannot be undone.',
        'confirmation_placeholder' => 'Enter the product name to confirm',
    ],
    'status' => [
        'duplicated' => 'Product duplicated.',
        'locked' => 'Product locked.',
        'unlocked' => 'Product unlocked.',
        'deleted' => 'Product deleted.',
        'version_deleted' => 'Version deleted.',
        'last_version_deleted' => 'Last saved version deleted. This product has no saved versions.',
    ],
    'validation' => [
        'confirmation_mismatch' => 'The confirmation name does not match.',
    ],
];
