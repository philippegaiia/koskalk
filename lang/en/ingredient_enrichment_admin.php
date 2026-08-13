<?php

return [
    'validation' => [
        'missing_api_key' => 'OpenAI ingredient research is not configured on this server.',
        'provider_failed' => 'The research provider could not complete this ingredient.',
        'invalid_response' => 'The research provider returned an invalid ingredient result.',
        'disallowed_source' => 'A proposed citation is not from an approved research website.',
        'unconsulted_source' => 'A proposed citation was not present in the pages consulted for this request.',
        'ai_disabled' => 'AI ingredient research is disabled on this server.',
        'selection_size' => 'Select between 1 and :maximum platform ingredients.',
        'platform_only' => 'Only platform ingredients can be researched in this workflow.',
    ],
];
