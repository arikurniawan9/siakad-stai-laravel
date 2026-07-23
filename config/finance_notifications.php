<?php

return [
    'email' => [
        'enabled' => filter_var(env('FINANCE_NOTIFICATION_EMAIL_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],
    'whatsapp' => [
        'enabled' => filter_var(env('FINANCE_NOTIFICATION_WHATSAPP_ENABLED', true), FILTER_VALIDATE_BOOL),
        'driver' => env('WHATSAPP_DRIVER', 'log'),
        'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '62'),
        'meta' => [
            'base_url' => env('WHATSAPP_META_BASE_URL', 'https://graph.facebook.com'),
            'graph_version' => env('WHATSAPP_META_GRAPH_VERSION', 'v21.0'),
            'phone_number_id' => env('WHATSAPP_META_PHONE_NUMBER_ID'),
            'access_token' => env('WHATSAPP_META_ACCESS_TOKEN'),
            'template_name' => env('WHATSAPP_META_FINANCE_TEMPLATE', 'siakad_finance_notification'),
            'language' => env('WHATSAPP_META_TEMPLATE_LANGUAGE', 'id'),
        ],
    ],
    'reminder_days' => array_values(array_filter(array_map(
        static fn (string $value): ?int => is_numeric(trim($value)) ? (int) trim($value) : null,
        explode(',', (string) env('FINANCE_NOTIFICATION_REMINDER_DAYS', '7,3,1,0,-1,-7'))
    ), static fn (?int $value): bool => $value !== null)),
    'dispatch' => [
        'batch_size' => (int) env('FINANCE_NOTIFICATION_BATCH_SIZE', 100),
        'max_attempts' => (int) env('FINANCE_NOTIFICATION_MAX_ATTEMPTS', 5),
        'retry_minutes' => (int) env('FINANCE_NOTIFICATION_RETRY_MINUTES', 5),
    ],
];
