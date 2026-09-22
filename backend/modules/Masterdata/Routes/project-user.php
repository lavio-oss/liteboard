<?php

use Illuminate\Support\Facades\Route;
use Modules\Masterdata\Actions\Project\Http\AttachProjectUserAction;
use Modules\Masterdata\Actions\Project\Http\CreateProjectUserAccountAction;
use Modules\Masterdata\Actions\Project\Http\ListAvailableProjectUsersAction;
use Modules\Masterdata\Actions\Project\Http\ListProjectUsersAction;
use Modules\Masterdata\Actions\Project\Http\RemoveProjectUserAction;
use Modules\Masterdata\Actions\Project\Http\UpdateProjectUserAction;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects/{project}')
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/users', ListProjectUsersAction::class)
            ->middleware('abilities:control-plane:read');
        Route::get('/available-users', ListAvailableProjectUsersAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/users', AttachProjectUserAction::class)
            ->middleware('abilities:control-plane:write');
        Route::post('/user-accounts', CreateProjectUserAccountAction::class)
            ->middleware('abilities:control-plane:write');
        Route::patch('/users/{user}', UpdateProjectUserAction::class)
            ->middleware('abilities:control-plane:write');
        Route::delete('/users/{user}', RemoveProjectUserAction::class)
            ->middleware('abilities:control-plane:write');
    });
