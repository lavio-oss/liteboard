<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Actions\Webhook\Http\CreateWebhookEndpointAction;
use Modules\Admin\Actions\Webhook\Http\ListWebhookDeliveriesAction;
use Modules\Admin\Actions\Webhook\Http\ListWebhookEndpointsAction;
use Modules\Admin\Actions\Webhook\Http\RedeliverWebhookDeliveryAction;
use Modules\Admin\Actions\Webhook\Http\RotateWebhookSecretAction;
use Modules\Admin\Actions\Webhook\Http\UpdateWebhookEndpointAction;

Route::middleware(['auth:sanctum', 'tenant.context'])
    ->prefix('tenants/{tenant}/projects/{project}/webhook-endpoints')
    ->whereUuid(['tenant', 'project', 'webhookEndpoint', 'delivery'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('/', ListWebhookEndpointsAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/', CreateWebhookEndpointAction::class)
            ->middleware('abilities:control-plane:write');
        Route::patch('/{webhookEndpoint}', UpdateWebhookEndpointAction::class)
            ->middleware('abilities:control-plane:write');
        Route::post('/{webhookEndpoint}/rotate-secret', RotateWebhookSecretAction::class)
            ->middleware('abilities:control-plane:write');
        Route::get('/{webhookEndpoint}/deliveries', ListWebhookDeliveriesAction::class)
            ->middleware('abilities:control-plane:read');
        Route::post('/{webhookEndpoint}/deliveries/{delivery}/redeliver', RedeliverWebhookDeliveryAction::class)
            ->middleware('abilities:control-plane:write');
    });
