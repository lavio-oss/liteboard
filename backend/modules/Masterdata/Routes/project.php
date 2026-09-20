<?php

use Illuminate\Support\Facades\Route;
use Modules\Masterdata\Controllers\Project\ProjectController;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects')
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', [ProjectController::class, 'index'])
            ->middleware('abilities:control-plane:read');
        Route::post('/', [ProjectController::class, 'store'])
            ->middleware('abilities:control-plane:write');
        Route::get('/{project}', [ProjectController::class, 'show'])
            ->middleware('abilities:control-plane:read');
        Route::patch('/{project}', [ProjectController::class, 'update'])
            ->middleware('abilities:control-plane:write');
        Route::delete('/{project}', [ProjectController::class, 'destroy'])
            ->middleware('abilities:control-plane:write');
    });
