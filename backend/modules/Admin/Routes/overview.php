<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Actions\Overview\Http\ShowProjectOverviewAction;

Route::middleware(['auth:sanctum', 'tenant.context', 'abilities:control-plane:read'])
    ->prefix('tenants/{tenant}/projects/{project}/overview')
    ->whereUuid(['tenant', 'project'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', ShowProjectOverviewAction::class);
    });
