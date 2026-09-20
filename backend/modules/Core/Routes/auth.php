<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Controllers\Auth\AuthenticationController;
use Modules\Core\Controllers\Auth\PersonalAccessTokenController;
use Modules\Core\Controllers\Tenant\TenantController;

Route::middleware(['web', 'throttle:login'])->post('/login', [AuthenticationController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', [AuthenticationController::class, 'me'])
        ->middleware('abilities:control-plane:read');
    Route::patch('/me', [AuthenticationController::class, 'updateProfile'])
        ->middleware('abilities:control-plane:write');
    Route::patch('/me/password', [AuthenticationController::class, 'updatePassword'])
        ->middleware('abilities:control-plane:write');
    Route::post('/logout', [AuthenticationController::class, 'logout']);
    Route::post('/logout-all', [AuthenticationController::class, 'logoutAll']);

    Route::prefix('tokens')->group(function (): void {
        Route::get('/', [PersonalAccessTokenController::class, 'index'])
            ->middleware('abilities:control-plane:read');
        Route::post('/', [PersonalAccessTokenController::class, 'store'])
            ->middleware('abilities:control-plane:write');
        Route::delete('/{token}', [PersonalAccessTokenController::class, 'destroy'])
            ->whereNumber('token')
            ->middleware('abilities:control-plane:write');
        Route::post('/{token}/rotate', [PersonalAccessTokenController::class, 'rotate'])
            ->whereNumber('token')
            ->middleware('abilities:control-plane:write');
    });

    Route::get('/tenants', [TenantController::class, 'index'])
        ->middleware('abilities:control-plane:read');

    Route::post('/tenants', [TenantController::class, 'store'])
        ->middleware('abilities:control-plane:write');

    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])
        ->scopeBindings()
        ->middleware(['abilities:control-plane:read', 'tenant.context']);

    Route::patch('/tenants/{tenant}', [TenantController::class, 'update'])
        ->scopeBindings()
        ->middleware(['abilities:control-plane:write', 'tenant.context']);

    Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy'])
        ->scopeBindings()
        ->middleware(['abilities:control-plane:write', 'tenant.context']);
});
