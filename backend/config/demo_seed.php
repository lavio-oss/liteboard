<?php

return [
    // Pins DemoWorkflowSeeder's "ESP32 Bench 01" to the IDs and token already flashed on the board.
    'esp32' => [
        'project_id' => env('LITEBOARD_SEED_ESP32_PROJECT_ID'),
        'device_id' => env('LITEBOARD_SEED_ESP32_DEVICE_ID'),
        'access_token' => env('LITEBOARD_SEED_ESP32_ACCESS_TOKEN'),
    ],
];
