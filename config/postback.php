<?php

return [
    'partner' => [
        'url' => env('PARTNER_API_URL'),
        'connect_timeout' => (int) env('PARTNER_CONNECT_TIMEOUT', 2),
        'timeout' => (int) env('PARTNER_HTTP_TIMEOUT', 5),
    ],
    'worker_timeout' => (int) env('WORKER_TIMEOUT', 15),
    'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 30),
    'delivery_lease_seconds' => (int) env('DELIVERY_LEASE_SECONDS', 45),
    'recovery_lease_seconds' => (int) env('RECOVERY_LEASE_SECONDS', 600),
    'redis_reservation_lease_seconds' => (int) env('REDIS_RESERVATION_LEASE_SECONDS', 15),
    'retry_delays_seconds' => array_map(
        'intval',
        explode(',', (string) env('POSTBACK_RETRY_DELAYS', '1,5')),
    ),
];
