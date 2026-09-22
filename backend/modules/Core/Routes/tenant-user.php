<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Actions\Tenant\Http\CreateTenantUserAction;
use Modules\Core\Actions\Tenant\Http\DeleteTenantUserAction;
use Modules\Core\Actions\Tenant\Http\ListTenantUsersAction;
use Modules\Core\Actions\Tenant\Http\UpdateTenantUserAction;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/users')
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', ListTenantUsersAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/', CreateTenantUserAction::class)
            ->middleware('abilities:control-plane:write');
        Route::patch('/{user}', UpdateTenantUserAction::class)
            ->middleware('abilities:control-plane:write');
        Route::delete('/{user}', DeleteTenantUserAction::class)
            ->middleware('abilities:control-plane:write');
    });
