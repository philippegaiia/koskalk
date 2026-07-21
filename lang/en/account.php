<?php

return [
    'page' => [
        'title' => 'Account',
        'intro' => 'Manage your personal details, password, plan, and billing.',
    ],
    'profile' => [
        'heading' => 'Profile',
        'description' => 'Update the name associated with your account.',
        'name' => 'Name',
        'email' => 'Email',
        'email_help' => 'Email address changes are not available from this page.',
    ],
    'security' => [
        'heading' => 'Password',
        'description' => 'Use a strong, unique password to protect your account.',
        'current_password' => 'Current password',
        'new_password' => 'New password',
        'confirm_new_password' => 'Confirm new password',
    ],
    'plan' => [
        'heading' => 'Current plan',
        'none' => 'No plan assigned',
        'usage_heading' => 'Workspace usage',
    ],
    'usage' => [
        'products' => 'Products',
        'ingredients' => 'Your ingredients',
        'production_batches' => 'Production batches',
        'used' => ':used / :limit',
        'used_unlimited' => ':used / Unlimited',
        'unlimited' => 'Unlimited',
        'remaining' => ':count remaining',
    ],
    'billing' => [
        'heading' => 'Billing',
        'free_account' => 'Free account',
        'active_subscription' => 'Active subscription',
        'provider' => 'Provider',
        'status' => 'Status',
        'active' => 'Active',
        'no_payment_method' => 'No payment method',
        'online_checkout_unavailable' => 'Online checkout is not available yet.',
        'payment_update_unavailable' => 'Payment method updates are not available yet.',
        'no_active_subscription' => 'No active subscription was found.',
    ],
    'actions' => [
        'sign_out' => 'Sign out',
        'save_profile' => 'Save changes',
        'update_password' => 'Update password',
        'choose_plan' => 'Choose plan',
        'checkout_unavailable' => 'Checkout unavailable',
        'update_payment_method' => 'Update payment method',
    ],
    'status' => [
        'profile_updated' => 'Profile updated.',
        'password_updated' => 'Password updated.',
    ],
];
