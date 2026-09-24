<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Actions\Asset\Http\CreateAssetAction;
use Modules\Admin\Actions\Asset\Http\DeleteAssetAction;
use Modules\Admin\Actions\Asset\Http\ListAssetsAction;
use Modules\Admin\Actions\Asset\Http\ListTenantAssetsAction;
use Modules\Admin\Actions\Asset\Http\ShowAssetAction;
use Modules\Admin\Actions\Asset\Http\UpdateAssetAction;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/assets')
    ->group(function (): void {
        Route::get('/', ListTenantAssetsAction::class)
            ->middleware('abilities:control-plane:read');
    });

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects/{project}/assets')
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', ListAssetsAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/', CreateAssetAction::class)
            ->middleware('abilities:control-plane:write');
        Route::get('/{asset}', ShowAssetAction::class)
            ->middleware('abilities:control-plane:read');
        Route::patch('/{asset}', UpdateAssetAction::class)
            ->middleware('abilities:control-plane:write');
        Route::delete('/{asset}', DeleteAssetAction::class)
            ->middleware('abilities:control-plane:write');
    });
