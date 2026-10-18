<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Actions\AlertRule\Http\ArchiveAlertRuleAction;
use Modules\Admin\Actions\AlertRule\Http\CreateAlertRuleAction;
use Modules\Admin\Actions\AlertRule\Http\CreateAlertRuleRevisionAction;
use Modules\Admin\Actions\AlertRule\Http\ListAlertRulesAction;
use Modules\Admin\Actions\AlertRule\Http\ShowAlertRuleAction;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects/{project}/alert-rules')
    ->whereUuid(['tenant', 'project', 'alertRule'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', ListAlertRulesAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/', CreateAlertRuleAction::class)
            ->middleware('abilities:control-plane:write');
        Route::get('/{alertRule}', ShowAlertRuleAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/{alertRule}/revisions', CreateAlertRuleRevisionAction::class)
            ->middleware('abilities:control-plane:write');
        Route::post('/{alertRule}/archive', ArchiveAlertRuleAction::class)
            ->middleware('abilities:control-plane:write');
    });
