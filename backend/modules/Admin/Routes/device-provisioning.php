<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Actions\DeviceProvisioning\Http\BootstrapDeviceAction;
use Modules\Admin\Actions\DeviceProvisioning\Http\ReadDeviceApprovalStatusAction;

Route::post('/device-provisioning/bootstrap', BootstrapDeviceAction::class)
    ->middleware('throttle:device-provisioning-ip');
Route::get('/device-provisioning/status', ReadDeviceApprovalStatusAction::class)
    ->middleware('throttle:device-provisioning-ip');
