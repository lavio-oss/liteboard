<?php

it('treats empty ESP32 seed variables as unpinned', function (): void {
    $keys = ['LITEBOARD_SEED_ESP32_PROJECT_ID', 'LITEBOARD_SEED_ESP32_DEVICE_ID', 'LITEBOARD_SEED_ESP32_ACCESS_TOKEN'];

    foreach ($keys as $key) {
        $_ENV[$key] = $_SERVER[$key] = '';
    }

    try {
        expect((require config_path('demo_seed.php'))['esp32'])->toBe([
            'project_id' => null,
            'device_id' => null,
            'access_token' => null,
        ]);
    } finally {
        foreach ($keys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }
});
