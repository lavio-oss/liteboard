<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Actions\Device\Http\ApproveDeviceAction;
use Modules\Admin\Actions\Device\Http\CreateDeviceAction;
use Modules\Admin\Actions\Device\Http\DeleteDeviceAction;
use Modules\Admin\Actions\Device\Http\ListDevicesAction;
use Modules\Admin\Actions\Device\Http\ListTenantDevicesAction;
use Modules\Admin\Actions\Device\Http\RegisterGatewaySubDeviceAction;
use Modules\Admin\Actions\Device\Http\RejectDeviceAction;
use Modules\Admin\Actions\Device\Http\RotateDeviceCredentialAction;
use Modules\Admin\Actions\Device\Http\ShowDeviceAction;
use Modules\Admin\Actions\Device\Http\ShowDeviceCredentialAction;
use Modules\Admin\Actions\Device\Http\UpdateDeviceAction;
use Modules\Admin\Actions\Device\Http\UpdateDeviceCredentialAction;

Route::post('/internal/gateways/{gateway}/sub-devices', RegisterGatewaySubDeviceAction::class)
    ->whereUuid('gateway');

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/devices')
    ->whereUuid('tenant')
    ->group(function (): void {
        Route::get('/', ListTenantDevicesAction::class)
            ->middleware('abilities:control-plane:read');
    });

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects/{project}/devices')
    ->whereUuid(['tenant', 'project', 'device'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', ListDevicesAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/', CreateDeviceAction::class)
            ->middleware('abilities:control-plane:write');
        Route::get('/{device}', ShowDeviceAction::class)
            ->middleware('abilities:control-plane:read');
        Route::patch('/{device}', UpdateDeviceAction::class)
            ->middleware('abilities:control-plane:write');
        Route::delete('/{device}', DeleteDeviceAction::class)
            ->middleware('abilities:control-plane:write');

        Route::post('/{device}/approve', ApproveDeviceAction::class)
            ->middleware('abilities:control-plane:write');
        Route::post('/{device}/reject', RejectDeviceAction::class)
            ->middleware('abilities:control-plane:write');

        Route::get('/{device}/credentials', ShowDeviceCredentialAction::class)
            ->middleware('abilities:control-plane:read');
        Route::put('/{device}/credentials', UpdateDeviceCredentialAction::class)
            ->middleware('abilities:control-plane:write');
        Route::post('/{device}/credentials/rotate', RotateDeviceCredentialAction::class)
            ->middleware('abilities:control-plane:write');
    });
