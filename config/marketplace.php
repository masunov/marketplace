<?php
declare(strict_types=1);

return [

    'total_chances' => 100,

    'vendors' => [

        'vendor_a' => [
            'rpm'            => (int)env('VENDOR_A_RPM', 60),
            'outcomes'       => [
                'error'   => (int)env('VENDOR_A_ERROR_RATE', 20),
                'timeout' => (int)env('VENDOR_A_TIMEOUT_RATE', 20),
            ],
        ],

        'vendor_b' => [
            'rpm'            => (int)env('VENDOR_B_RPM', 60),
            'outcomes'       => [
                'error'   => (int)env('VENDOR_B_ERROR_RATE', 10),
                'timeout' => (int)env('VENDOR_B_TIMEOUT_RATE', 10),
            ],
        ],

        'vendor_c' => [
            'rpm'            => (int)env('VENDOR_C_RPM', 30),
            'outcomes'       => [
                'error'        => (int)env('VENDOR_C_ERROR_RATE', 10),
                'timeout'      => (int)env('VENDOR_C_TIMEOUT_RATE', 10),
                'duplicate'    => (int)env('VENDOR_C_DUPLICATE_RATE', 15),
                'foreign_code' => (int)env('VENDOR_C_FOREIGN_CODE_RATE', 15),
                'lying_error'  => (int)env('VENDOR_C_LYING_ERROR_RATE', 20),
            ],
        ],

    ],

    'rate_limit' => [
        'counter_ttl_in_seconds' => (int)env('VENDOR_RPM_COUNTER_TTL', 3600),
    ],

    'delivery' => [

        'queue' => 'delivery-paid',

        'base_delay_in_seconds'  => (int)env('DELIVERY_BASE_DELAY', 5),
        'max_delay_in_seconds'   => (int)env('DELIVERY_MAX_DELAY', 300),
        'jitter_in_seconds'      => (int)env('DELIVERY_JITTER', 5),
        'deadline_in_minutes'    => (int)env('DELIVERY_DEADLINE_MINUTES', 60),
    ],

    'payment_systems' => [
        'ps_mir' => [
            'auto_charge' => (bool)env('PS_MIR_AUTO_CHARGE', true),

            'callback_lock_ttl_in_seconds' => 60,

            'callback_url' => env(
                'PS_MIR_CALLBACK_URL',
                'http://app:8000/api/payment_systems/ps_mir/callback'
            ),

            'charge' => [
                'fail'      => (int)env('PS_MIR_CHARGE_FAIL_RATE', 10),
                'lost'      => (int)env('PS_MIR_CHARGE_LOST_RATE', 5),
                'min_delay' => (int)env('PS_MIR_CALLBACK_MIN_DELAY', 1),
                'max_delay' => (int)env('PS_MIR_CALLBACK_MAX_DELAY', 5),
            ],

            'refund' => [
                'reject'      => (int)env('PS_MIR_REFUND_REJECT_RATE', 5),
                'unavailable' => (int)env('PS_MIR_REFUND_UNAVAILABLE_RATE', 10),
            ],
        ]
    ]

];
