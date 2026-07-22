<?php

return [
    'driver' => env('BSI_VA_DRIVER', 'fake'),
    'enabled' => (bool) env('BSI_ENABLED', false),
    'environment' => env('BSI_ENVIRONMENT', 'sandbox'),
    'base_url' => env('BSI_BASE_URL'), // Must come from official onboarding contract.
    'callback_secret' => env('BSI_CALLBACK_SECRET'), // Temporary envelope; final scheme follows bank contract.
    'timeout' => (int) env('BSI_TIMEOUT', 10),
    'signature_tolerance_seconds' => (int) env('BSI_SIGNATURE_TOLERANCE', 300),
    'strategy' => env('BSI_VA_STRATEGY', 'student'),
];
