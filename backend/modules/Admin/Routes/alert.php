<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Actions\Alert\Http\AcknowledgeAlertAction;
use Modules\Admin\Actions\Alert\Http\ListAlertsAction;
use Modules\Admin\Actions\Alert\Http\ShowAlertAction;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects/{project}/alerts')
    ->whereUuid(['tenant', 'project', 'alert'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', ListAlertsAction::class)
            ->middleware('abilities:control-plane:read');
        Route::get('/{alert}', ShowAlertAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/{alert}/acknowledge', AcknowledgeAlertAction::class)
            ->middleware('abilities:control-plane:write');
    });
