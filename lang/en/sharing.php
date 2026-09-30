<?php

return [
    'ingredient' => 'Ingredient',
    'validation' => [
        'missing_trusted_baseline' => 'An eligible private Ingredient has no usable original chemistry baseline. Correct its source data before sharing.',
        'reference_unavailable' => 'A required Ingredient catalogue reference is missing. Correct the source Ingredient before sharing.',
        'graph_invalid' => 'An Ingredient dependency is missing, inaccessible or circular (:path). Fix the source Formula and preview again.',
        'graph_limit' => 'This Formula has too many Ingredient dependencies to share. Reduce its complexity and try again.',
        'snapshot_size' => 'This offer is too large to share. Reduce optional content or Ingredient dependencies.',
        'options' => 'Choose only the supported content options.',
        'rate_limit' => 'Too many sharing requests. Try again in :seconds seconds.',
        'content' => 'This content format cannot be shared. Save supported text and preview again.',
        'recipient' => 'Enter a valid address for another Workspace.',
        'manufactured_output' => 'Only finished Product formulas can be shared. This Formula produces a manufactured Ingredient.',
        'saved_required' => 'Save a Formula before sharing this Product.',
        'reference' => 'A required catalogue or regulatory reference is unavailable. Fix the source Formula and preview again.',
        'formula' => 'The Saved formula has incomplete or inconsistent lines. Fix it and save again.',
        'settings' => 'The Saved formula has unsupported or missing calculation settings. Fix it and save again.',
        'request' => 'This sharing request is invalid. Preview the offer and try again.',
        'request_reused' => 'This request was already used for another offer. Preview again to send a new offer.',
        'changed' => 'The source Formula, Ingredients or recipient changed. Review a fresh preview before sending.',
        'pending_limit' => 'This Workspace has too many pending offers. Close an existing offer before sending another.',
    ],
];
