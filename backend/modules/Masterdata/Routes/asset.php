<?php

use Illuminate\Support\Facades\Route;
use Modules\Masterdata\Actions\Asset\Http\CreateAssetAction;
use Modules\Masterdata\Actions\Asset\Http\DeleteAssetAction;
use Modules\Masterdata\Actions\Asset\Http\ListAssetsAction;
use Modules\Masterdata\Actions\Asset\Http\ShowAssetAction;
use Modules\Masterdata\Actions\Asset\Http\UpdateAssetAction;

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
