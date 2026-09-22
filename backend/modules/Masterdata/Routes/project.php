<?php

use Illuminate\Support\Facades\Route;
use Modules\Masterdata\Actions\Project\Http\CreateProjectAction;
use Modules\Masterdata\Actions\Project\Http\DeleteProjectAction;
use Modules\Masterdata\Actions\Project\Http\ListProjectsAction;
use Modules\Masterdata\Actions\Project\Http\ShowProjectAction;
use Modules\Masterdata\Actions\Project\Http\UpdateProjectAction;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects')
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
