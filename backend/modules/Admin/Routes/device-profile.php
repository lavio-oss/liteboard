<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Actions\DeviceProfile\Http\CreateDeviceProfileAction;
use Modules\Admin\Actions\DeviceProfile\Http\CreateDeviceProfileVersionAction;
use Modules\Admin\Actions\DeviceProfile\Http\DeleteDeviceProfileAction;
use Modules\Admin\Actions\DeviceProfile\Http\DeleteDeviceProfileVersionAction;
use Modules\Admin\Actions\DeviceProfile\Http\ListDeviceProfilesAction;
use Modules\Admin\Actions\DeviceProfile\Http\ShowDeviceProfileAction;
use Modules\Admin\Actions\DeviceProfile\Http\UpdateDeviceProfileAction;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects/{project}/device-profiles')
    ->whereUuid(['tenant', 'project', 'deviceProfile', 'version'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', ListDeviceProfilesAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/', CreateDeviceProfileAction::class)
            ->middleware('abilities:control-plane:write');
        Route::get('/{deviceProfile}', ShowDeviceProfileAction::class)
            ->middleware('abilities:control-plane:read');
        Route::patch('/{deviceProfile}', UpdateDeviceProfileAction::class)
            ->middleware('abilities:control-plane:write');
        Route::delete('/{deviceProfile}', DeleteDeviceProfileAction::class)
            ->middleware('abilities:control-plane:write');
        Route::post('/{deviceProfile}/versions', CreateDeviceProfileVersionAction::class)
            ->middleware('abilities:control-plane:write');
        Route::delete('/{deviceProfile}/versions/{version}', DeleteDeviceProfileVersionAction::class)
            ->middleware('abilities:control-plane:write');
    });
