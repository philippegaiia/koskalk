<?php

return [
    'page' => [
        'title' => 'Packaging',
        'heading' => 'Manage packaging for your recipes and costing.',
        'intro' => 'Add boxes, jars, labels, inserts, and other reusable packaging with a unit price. Saved items can be reused in your recipes without entering them again.',
        'aria_label' => 'Packaging overview',
    ],
    'auth' => [
        'aria_label' => 'Sign in required',
        'heading' => 'Sign in to manage packaging',
        'description' => 'Open the dashboard from your signed-in session to create and reuse packaging.',
    ],
    'catalog' => [
        'heading' => 'Packaging library',
        'description' => 'Saved packaging available for your recipes and costing.',
        'table_label' => 'Packaging library',
        'filters_label' => 'Packaging library filters',
    ],
    'actions' => [
        'add' => 'Add packaging',
        'back_to_dashboard' => 'Back to dashboard',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'cancel' => 'Cancel',
        'remove_everywhere' => 'Remove everywhere and delete',
    ],
    'search' => [
        'label' => 'Search',
        'placeholder' => 'Name or notes',
        'aria_label' => 'Search packaging',
    ],
    'empty' => [
        'no_matches' => 'No packaging matches',
        'no_items' => 'No packaging yet',
        'description' => 'Add reusable boxes, labels, jars, and inserts once, then reuse them in your recipes and costing.',
    ],
    'table' => [
        'image' => 'Image',
        'name' => 'Name',
        'notes' => 'Notes',
        'actions' => 'Actions',
        'per_page' => 'Packaging items per page',
    ],
    'price' => [
        'column' => 'Unit price (:currency)',
    ],
    'accessibility' => [
        'unit_price' => 'Unit price for :item',
        'edit' => 'Edit :item',
        'delete' => 'Delete :item',
    ],
    'removal' => [
        'used' => [
            'heading' => 'Manage “:item”',
            'description' => '{1} Used in :count formula. Removing it deletes it from every saved formula version, including backups and archived formulas, and removes it from costing.|[2,*] Used in :count formulas. Removing it deletes it from every saved formula version, including backups and archived formulas, and removes it from costing.',
        ],
        'unused' => [
            'heading' => 'Delete “:item”?',
            'description' => 'This removes the packaging item from your library.',
        ],
    ],
    'validation' => [
        'remove_from_formulas' => 'Remove this packaging item from every formula before deleting it.',
        'in_use' => 'This packaging item is still in use and cannot be deleted.',
    ],
    'status' => [
        'deleted' => ':item was deleted.',
        'removed_and_deleted' => ':item was removed from every formula and deleted.',
    ],
    'editor' => [
        'create' => [
            'page_title' => 'Add packaging',
            'heading' => 'Add packaging to your library.',
            'intro' => 'Save the name, unit price, image, and notes once, then reuse this packaging in your products and costing.',
        ],
        'edit' => [
            'heading' => 'Edit packaging details.',
            'intro' => 'Update the details used in your products and costing.',
        ],
        'actions' => [
            'back' => 'Back to packaging',
            'create' => 'Add packaging',
            'save' => 'Save changes',
        ],
        'form' => [
            'section' => 'Packaging details',
            'description' => 'Add the information you need to identify and cost this packaging.',
            'name' => [
                'label' => 'Name',
                'placeholder' => 'e.g. 100 g kraft soap box',
            ],
            'unit_price' => 'Unit price (:currency)',
            'image' => [
                'label' => 'Packaging image',
                'helper' => 'Optional square image shown in your packaging library and selectors.',
            ],
            'notes' => [
                'label' => 'Notes',
                'helper' => 'Add the size, material, supplier reference, or anything else useful.',
            ],
        ],
        'status' => [
            'auth_required' => 'Sign in before saving packaging.',
            'created' => 'Packaging added.',
            'saved' => 'Changes saved.',
        ],
    ],
];
