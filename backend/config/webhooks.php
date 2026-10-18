<?php

return [
    // Delay before attempt n+1 after a retryable failure of attempt n; max_attempts caps the total.
    'backoff_seconds' => [30, 60, 300, 900, 3600, 21600, 21600],
    'max_attempts' => 8,
    'lease_seconds' => 60,
    'connect_timeout_seconds' => 3,
    'timeout_seconds' => 10,
    'batch_size' => 50,
    'allowed_ports' => [443],
    // Local development only: permits http:// and private/loopback destinations such as a mock receiver.
    'allow_private_destinations' => (bool) env('WEBHOOKS_ALLOW_PRIVATE_DESTINATIONS', false),
];
