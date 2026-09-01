<?php
declare(strict_types=1);

return [

    'total_chances' => 100,

    'vendors' => [

        'vendor_a' => [
            'outcomes'       => [
                'error'   => (int)env('VENDOR_A_ERROR_RATE', 20),
                'timeout' => (int)env('VENDOR_A_TIMEOUT_RATE', 20),
            ],
        ],

        'vendor_b' => [
            'outcomes'       => [
                'error'   => (int)env('VENDOR_B_ERROR_RATE', 10),
                'timeout' => (int)env('VENDOR_B_TIMEOUT_RATE', 10),
            ],
        ],

    ],


    'delivery' => [
        'base_delay_in_seconds'  => (int)env('DELIVERY_BASE_DELAY', 5),
        'max_delay_in_seconds'   => (int)env('DELIVERY_MAX_DELAY', 300),
        'jitter_in_seconds'      => (int)env('DELIVERY_JITTER', 5),
        'deadline_in_minutes'    => (int)env('DELIVERY_DEADLINE_MINUTES', 60),
    ],


    'payment_systems' => [
        'ps_mir' => [
            'callback_lock_ttl_in_seconds' => 60
        ]
    ]

];
