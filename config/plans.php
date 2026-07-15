<?php

return [
    'default_slug' => 'demo',

    'empresa' => [
        'messages_monthly_limit_min' => 1000,
        'messages_monthly_limit_max' => 10_000_000,
    ],

    'catalog' => [
        'demo' => [
            'name' => 'Demo',
            'messages_monthly_limit' => 100,
            'http_send_per_minute' => 10,
            'job_send_per_minute' => 30,
        ],
        'starter' => [
            'name' => 'Starter',
            'messages_monthly_limit' => 5000,
            'http_send_per_minute' => 30,
            'job_send_per_minute' => 60,
        ],
        'business' => [
            'name' => 'Business',
            'messages_monthly_limit' => 80000,
            'http_send_per_minute' => 60,
            'job_send_per_minute' => 120,
        ],
        'empresa' => [
            'name' => 'Enterprise',
            // null = must supply messagesMonthlyLimit on activate-plan
            'messages_monthly_limit' => null,
            'http_send_per_minute' => 120,
            'job_send_per_minute' => 180,
        ],
    ],
];
