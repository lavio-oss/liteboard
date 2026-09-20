<?php

use Illuminate\Support\Facades\Route;
use Modules\Masterdata\Controllers\Factory\FactoryController;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects/{project}/factories')
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', [FactoryController::class, 'index'])
            ->middleware('abilities:control-plane:read');
        Route::post('/', [FactoryController::class, 'store'])
            ->middleware('abilities:control-plane:write');
        Route::get('/{factory}', [FactoryController::class, 'show'])
            ->middleware('abilities:control-plane:read');
        Route::patch('/{factory}', [FactoryController::class, 'update'])
            ->middleware('abilities:control-plane:write');
        Route::delete('/{factory}', [FactoryController::class, 'destroy'])
            ->middleware('abilities:control-plane:write');
    });
