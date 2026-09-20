<?php

use Illuminate\Support\Facades\Route;
use Modules\Masterdata\Controllers\ProductionLine\ProductionLineController;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects/{project}/factories/{factory}/production-lines')
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', [ProductionLineController::class, 'index'])
            ->middleware('abilities:control-plane:read');
        Route::post('/', [ProductionLineController::class, 'store'])
            ->middleware('abilities:control-plane:write');
        Route::get('/{production_line}', [ProductionLineController::class, 'show'])
            ->middleware('abilities:control-plane:read');
        Route::patch('/{production_line}', [ProductionLineController::class, 'update'])
            ->middleware('abilities:control-plane:write');
        Route::delete('/{production_line}', [ProductionLineController::class, 'destroy'])
            ->middleware('abilities:control-plane:write');
    });
