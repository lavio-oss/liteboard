<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', function (): array {
    return [
        'service' => 'liteboard-control-plane',
        'status' => 'ok',
    ];
});

require base_path('modules/Core/Routes/auth.php');
require base_path('modules/Core/Routes/tenant-user.php');
require base_path('modules/Admin/Routes/project.php');
require base_path('modules/Admin/Routes/project-user.php');
require base_path('modules/Admin/Routes/asset.php');
require base_path('modules/Admin/Routes/device.php');
require base_path('modules/Admin/Routes/device-profile.php');
require base_path('modules/Admin/Routes/device-provisioning.php');
