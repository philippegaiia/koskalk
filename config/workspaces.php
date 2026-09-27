<?php

return [
    'collaboration_enabled' => env('WORKSPACE_COLLABORATION_ENABLED', false),
    'maximum_pending_invitations' => 100,
    'invitation_recipient_cooldown_seconds' => 60,
];
