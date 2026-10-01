<?php

return [
    'collaboration_enabled' => env('WORKSPACE_COLLABORATION_ENABLED', false),
    'maximum_pending_invitations' => 100,
    'invitation_recipient_cooldown_seconds' => 60,
    'formula_sharing' => [
        'enabled' => env('WORKSPACE_FORMULA_SHARING_ENABLED', false),
        'maximum_pending_outgoing' => 100,
        'maximum_pending_per_pair' => 20,
        'rate_limits' => [
            'recipient' => ['actor_per_minute' => 20, 'workspace_per_minute' => 60],
            'send' => ['actor_per_minute' => 5, 'workspace_per_minute' => 20],
            'preview' => ['actor_per_minute' => 10, 'workspace_per_minute' => 30],
            'accept' => ['actor_per_minute' => 10, 'workspace_per_minute' => 30],
        ],
        'limits' => ['nodes' => 200, 'edges' => 1000, 'depth' => 12, 'relation_rows' => 10000, 'bytes' => 2097152],
    ],
    'resource_limits' => [
        'exports' => ['actor_per_minute' => 10, 'workspace_per_minute' => 30],
        'prints' => ['actor_per_minute' => 60, 'workspace_per_minute' => 120],
        'temporary_uploads' => ['actor_per_minute' => 60, 'workspace_per_minute' => 120, 'guest_per_minute' => 10],
    ],
];
