<?php

return [
    'mqtt_host' => env('DEVICE_PROVISIONING_MQTT_HOST'),
    'allow_insecure_http' => env('DEVICE_PROVISIONING_ALLOW_INSECURE_HTTP', false),
    'rate_limits' => [
        'ip_per_minute' => (int) env('DEVICE_PROVISIONING_IP_RATE_LIMIT', 30),
        'profile_per_minute' => (int) env('DEVICE_PROVISIONING_PROFILE_RATE_LIMIT', 60),
    ],
];
