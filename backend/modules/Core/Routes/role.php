<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Actions\Role\Http\CreateRoleAction;
use Modules\Core\Actions\Role\Http\DeleteRoleAction;
use Modules\Core\Actions\Role\Http\ListPermissionsAction;
use Modules\Core\Actions\Role\Http\ListRolesAction;
use Modules\Core\Actions\Role\Http\UpdateRoleAction;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}')
    ->whereUuid(['tenant', 'role'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/permissions', ListPermissionsAction::class)
            ->middleware('abilities:control-plane:read');
        Route::get('/roles', ListRolesAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/roles', CreateRoleAction::class)
            ->middleware('abilities:control-plane:write');
        Route::patch('/roles/{role}', UpdateRoleAction::class)
            ->middleware('abilities:control-plane:write');
        Route::delete('/roles/{role}', DeleteRoleAction::class)
            ->middleware('abilities:control-plane:write');
    });
