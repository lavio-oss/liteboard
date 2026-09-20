<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', function (): array {
    return [
        'service' => 'liteboard-control-plane',
        'status' => 'ok',
    ];
});

require base_path('modules/Core/Routes/auth.php');
require base_path('modules/Masterdata/Routes/project.php');
require base_path('modules/Masterdata/Routes/factory.php');
require base_path('modules/Masterdata/Routes/production-line.php');
