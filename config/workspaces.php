<?php

return [
    'collaboration_enabled' => env('WORKSPACE_COLLABORATION_ENABLED', false),
    'maximum_pending_invitations' => 100,
    'invitation_recipient_cooldown_seconds' => 60,
    'resource_limits' => [
        'exports' => ['actor_per_minute' => 10, 'workspace_per_minute' => 30],
        'prints' => ['actor_per_minute' => 60, 'workspace_per_minute' => 120],
        'temporary_uploads' => ['actor_per_minute' => 60, 'workspace_per_minute' => 120, 'guest_per_minute' => 10],
    ],
];
