<?php

return [
    'resource' => [
        'label' => 'AI enrichment batch',
        'plural_label' => 'AI enrichment batches',
        'summary' => 'Research summary',
    ],
    'fields' => ['batch' => 'Batch'],
    'actions' => [
        'run' => 'Run AI enrichment',
        'run_heading' => 'Research selected ingredients',
        'run_description' => 'Queue source-backed research with :model. This paid action proposes translations, identifiers, COSING functions, guidance, and colour labels. Nothing is applied until review and approval.',
        'review' => 'Review proposal',
        'approve' => 'Approve',
        'apply' => 'Apply approved',
        'retry' => 'Retry failures',
        'cancel' => 'Cancel pending',
    ],
    'notifications' => [
        'applied' => 'Applied :applied; unchanged :unchanged; stale :stale; failed :failed.',
        'retried' => 'Failed ingredients were queued again.',
        'cancelled' => 'Pending research was cancelled.',
    ],
    'replace' => [
        'display_name' => 'Replace English display name', 'inci_name' => 'Replace INCI name',
        'category' => 'Replace category', 'subcategory' => 'Replace subcategory',
        'saponification_name' => 'Replace saponification name', 'info_markdown' => 'Replace guidance',
        'identifiers' => 'Replace identifiers', 'cosing_functions' => 'Replace COSING functions',
        'translations' => 'Replace translations', 'market_labels' => 'Replace market labels',
    ],
    'status' => [
        'batch' => [
            'pending' => 'Pending', 'processing' => 'Processing', 'ready_for_review' => 'Ready for review',
            'partially_failed' => 'Partially failed', 'applied' => 'Applied', 'cancelled' => 'Cancelled',
        ],
        'item' => [
            'pending' => 'Pending', 'researching' => 'Researching', 'ready' => 'Ready', 'warning' => 'Warning',
            'failed' => 'Failed', 'approved' => 'Approved', 'applying' => 'Applying', 'stale' => 'Stale',
            'applied' => 'Applied', 'unchanged' => 'Unchanged', 'cancelled' => 'Cancelled',
        ],
    ],
    'validation' => [
        'missing_api_key' => 'OpenAI ingredient research is not configured on this server.',
        'provider_failed' => 'The research provider could not complete this ingredient.',
        'invalid_response' => 'The research provider returned an invalid ingredient result.',
        'disallowed_source' => 'A proposed citation is not from an approved research website.',
        'unconsulted_source' => 'A proposed citation was not present in the pages consulted for this request.',
        'ai_disabled' => 'AI ingredient research is disabled on this server.',
        'selection_size' => 'Select between 1 and :maximum platform ingredients.',
        'platform_only' => 'Only platform ingredients can be researched in this workflow.',
        'not_approvable' => 'This ingredient proposal is not ready for approval.',
        'stale' => 'The ingredient changed after research. Start fresh research before approval.',
        'apply_failed' => 'The approved proposal could not be applied.',
    ],
];
