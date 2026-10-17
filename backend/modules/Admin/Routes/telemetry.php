<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Actions\Telemetry\Http\IssueDevicePresenceStreamTicketAction;
use Modules\Admin\Actions\Telemetry\Http\IssueTelemetryStreamTicketAction;
use Modules\Admin\Actions\Telemetry\Http\ListMessageOutcomesAction;
use Modules\Admin\Actions\Telemetry\Http\ListTelemetryAggregatesAction;
use Modules\Admin\Actions\Telemetry\Http\ListTelemetryHistoryAction;
use Modules\Admin\Actions\Telemetry\Http\ListUnresolvedMessagesAction;
use Modules\Admin\Actions\Telemetry\Http\ShowLatestTelemetryAction;
use Modules\Admin\Actions\Telemetry\Http\ShowMessageTraceAction;
use Modules\Admin\Actions\Telemetry\Http\ShowUnresolvedMessageAction;

Route::middleware(['auth:sanctum', 'tenant.context', 'abilities:control-plane:read'])
    ->prefix('tenants/{tenant}/devices')
    ->whereUuid('tenant')
    ->group(function (): void {
        Route::post('/presence-stream-ticket', IssueDevicePresenceStreamTicketAction::class);
    });

Route::middleware(['auth:sanctum', 'tenant.context', 'abilities:control-plane:read'])
    ->prefix('tenants/{tenant}/projects/{project}/devices/{device}')
    ->whereUuid(['tenant', 'project', 'device'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/telemetry/latest', ShowLatestTelemetryAction::class);
        Route::get('/telemetry', ListTelemetryHistoryAction::class);
        Route::get('/telemetry/aggregates', ListTelemetryAggregatesAction::class);
        // Issuing a stream ticket only grants reads, so it uses the read ability.
        Route::post('/telemetry/stream-ticket', IssueTelemetryStreamTicketAction::class);
        Route::get('/messages', ListMessageOutcomesAction::class);
        Route::get('/messages/{message}/trace', ShowMessageTraceAction::class)
            ->where('message', '[^/]{1,128}');
    });

// Central (no tenant context): messages whose topic scope the database could not confirm.
Route::middleware(['auth:sanctum', 'abilities:control-plane:read'])
    ->prefix('platform/telemetry/unresolved-messages')
    ->group(function (): void {
        Route::get('/', ListUnresolvedMessagesAction::class);
        Route::get('/{processingId}', ShowUnresolvedMessageAction::class)->whereUuid('processingId');
    });
