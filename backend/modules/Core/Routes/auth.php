<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Actions\Auth\Http\CreatePersonalAccessTokenAction;
use Modules\Core\Actions\Auth\Http\CurrentUserAction;
use Modules\Core\Actions\Auth\Http\ListPersonalAccessTokensAction;
use Modules\Core\Actions\Auth\Http\LoginAction;
use Modules\Core\Actions\Auth\Http\LogoutAction;
use Modules\Core\Actions\Auth\Http\LogoutAllAction;
use Modules\Core\Actions\Auth\Http\RevokePersonalAccessTokenAction;
use Modules\Core\Actions\Auth\Http\RotatePersonalAccessTokenAction;
use Modules\Core\Actions\Auth\Http\UpdatePasswordAction;
use Modules\Core\Actions\Auth\Http\UpdateProfileAction;
use Modules\Core\Actions\Tenant\Http\CreateTenantAction;
use Modules\Core\Actions\Tenant\Http\DeleteTenantAction;
use Modules\Core\Actions\Tenant\Http\ListTenantsAction;
use Modules\Core\Actions\Tenant\Http\ShowTenantAction;
use Modules\Core\Actions\Tenant\Http\UpdateTenantAction;

Route::middleware(['web', 'throttle:login'])->post('/login', LoginAction::class);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', CurrentUserAction::class)
        ->middleware('abilities:control-plane:read');
    Route::patch('/me', UpdateProfileAction::class)
        ->middleware('abilities:control-plane:write');
    Route::patch('/me/password', UpdatePasswordAction::class)
        ->middleware('abilities:control-plane:write');
    Route::post('/logout', LogoutAction::class);
    Route::post('/logout-all', LogoutAllAction::class);

    Route::prefix('tokens')->group(function (): void {
        Route::get('/', ListPersonalAccessTokensAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/', CreatePersonalAccessTokenAction::class)
            ->middleware('abilities:control-plane:write');
        Route::delete('/{token}', RevokePersonalAccessTokenAction::class)
            ->whereNumber('token')
            ->middleware('abilities:control-plane:write');
        Route::post('/{token}/rotate', RotatePersonalAccessTokenAction::class)
            ->whereNumber('token')
            ->middleware('abilities:control-plane:write');
    });

    Route::get('/tenants', ListTenantsAction::class)
        ->middleware('abilities:control-plane:read');

    Route::post('/tenants', CreateTenantAction::class)
        ->middleware('abilities:control-plane:write');

    Route::get('/tenants/{tenant}', ShowTenantAction::class)
        ->scopeBindings()
        ->middleware(['abilities:control-plane:read', 'tenant.context']);

    Route::patch('/tenants/{tenant}', UpdateTenantAction::class)
        ->scopeBindings()
        ->middleware(['abilities:control-plane:write', 'tenant.context']);

    Route::delete('/tenants/{tenant}', DeleteTenantAction::class)
        ->scopeBindings()
        ->middleware(['abilities:control-plane:write', 'tenant.context']);
});
