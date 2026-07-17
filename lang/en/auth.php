<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'password_requirements' => 'Use at least 12 characters, including an uppercase letter, a lowercase letter, a number, and a symbol.',
    'password_optional_reset' => 'Leave blank to keep the current password.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'login' => [
        'page_title' => 'Sign in',
        'heading' => 'Sign in to your workspace',
        'email' => 'Email',
        'password' => 'Password',
        'remember_me' => 'Remember me',
        'submit' => 'Sign in',
        'invitation_only' => 'Access is provisioned by invitation.',
    ],
    'verification' => [
        'page_title' => 'Account verification',
        'eyebrow' => 'Account security',
        'heading' => 'This account is not verified.',
        'body' => 'Access is provisioned by the administrator. Contact the administrator to verify this account before opening the private workspace.',
        'sign_out' => 'Sign out',
    ],

];
