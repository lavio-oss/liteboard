<?php

return [
    'mqtt_host' => env('DEVICE_PROVISIONING_MQTT_HOST'),
    // Must match the ports published by docker-compose (mosquitto 8883, mosquitto-plain 1883).
    'mqtt_tls_port' => (int) env('DEVICE_PROVISIONING_MQTT_TLS_PORT', 8883),
    'mqtt_plaintext_port' => (int) env('DEVICE_PROVISIONING_MQTT_PLAINTEXT_PORT', 1883),
    'allow_insecure_http' => env('DEVICE_PROVISIONING_ALLOW_INSECURE_HTTP', false),
    'rate_limits' => [
        'ip_per_minute' => (int) env('DEVICE_PROVISIONING_IP_RATE_LIMIT', 30),
        'profile_per_minute' => (int) env('DEVICE_PROVISIONING_PROFILE_RATE_LIMIT', 60),
    ],
];
