<?php

return [
    'available' => filter_var(env('BILLING_AVAILABLE', false), FILTER_VALIDATE_BOOL),
];
