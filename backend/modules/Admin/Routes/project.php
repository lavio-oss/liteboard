<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Actions\Project\Http\CreateProjectAction;
use Modules\Admin\Actions\Project\Http\DeleteProjectAction;
use Modules\Admin\Actions\Project\Http\ListProjectsAction;
use Modules\Admin\Actions\Project\Http\ShowProjectAction;
use Modules\Admin\Actions\Project\Http\UpdateProjectAction;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects')
    ->whereUuid(['tenant', 'project'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', ListProjectsAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/', CreateProjectAction::class)
            ->middleware('abilities:control-plane:write');
        Route::get('/{project}', ShowProjectAction::class)
            ->middleware('abilities:control-plane:read');
        Route::patch('/{project}', UpdateProjectAction::class)
            ->middleware('abilities:control-plane:write');
        Route::delete('/{project}', DeleteProjectAction::class)
            ->middleware('abilities:control-plane:write');
    });
