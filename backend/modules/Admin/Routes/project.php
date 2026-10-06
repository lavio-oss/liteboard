<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Actions\DeviceProvisioning\Http\CreateProjectProvisioningProfileAction;
use Modules\Admin\Actions\DeviceProvisioning\Http\RotateProjectProvisioningSecretAction;
use Modules\Admin\Actions\DeviceProvisioning\Http\ShowProjectProvisioningProfileAction;
use Modules\Admin\Actions\DeviceProvisioning\Http\UpdateProjectDeviceQuotaAction;
use Modules\Admin\Actions\DeviceProvisioning\Http\UpdateProjectProvisioningProfileAction;
use Modules\Admin\Actions\Project\Http\CreateProjectAction;
use Modules\Admin\Actions\Project\Http\DeleteProjectAction;
use Modules\Admin\Actions\Project\Http\ListProjectsAction;
use Modules\Admin\Actions\Project\Http\ShowProjectAction;
use Modules\Admin\Actions\Project\Http\UpdateProjectAction;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects')
    ->whereUuid(['tenant', 'project'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', ListProjectsAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/', CreateProjectAction::class)
            ->middleware('abilities:control-plane:write');
        Route::get('/{project}', ShowProjectAction::class)
            ->middleware('abilities:control-plane:read');
        Route::patch('/{project}', UpdateProjectAction::class)
            ->middleware('abilities:control-plane:write');
        Route::delete('/{project}', DeleteProjectAction::class)
            ->middleware('abilities:control-plane:write');
        Route::get('/{project}/provisioning-profile', ShowProjectProvisioningProfileAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/{project}/provisioning-profile', CreateProjectProvisioningProfileAction::class)
            ->middleware('abilities:control-plane:write');
        Route::patch('/{project}/provisioning-profile', UpdateProjectProvisioningProfileAction::class)
            ->middleware('abilities:control-plane:write');
        Route::post('/{project}/provisioning-profile/rotate-secret', RotateProjectProvisioningSecretAction::class)
            ->middleware('abilities:control-plane:write');
        Route::put('/{project}/device-quota', UpdateProjectDeviceQuotaAction::class)
            ->middleware('abilities:control-plane:write');
    });
