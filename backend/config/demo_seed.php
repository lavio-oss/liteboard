<?php

return [
    // Pins DemoWorkflowSeeder's "ESP32 Bench 01" to the IDs and token already flashed on the board.
    // An empty value (KEY=) means unpinned, not an empty ID or token.
    'esp32' => [
        'project_id' => env('LITEBOARD_SEED_ESP32_PROJECT_ID') ?: null,
        'device_id' => env('LITEBOARD_SEED_ESP32_DEVICE_ID') ?: null,
        'access_token' => env('LITEBOARD_SEED_ESP32_ACCESS_TOKEN') ?: null,
    ],
];
