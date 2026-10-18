<?php

namespace Modules\Admin\Actions\Webhook\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookDelivery;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Admin\Requests\Webhook\RedeliverWebhookDeliveryRequest;
use Modules\Admin\Resources\Webhook\WebhookDeliveryResource;
use Modules\Admin\Services\Webhook\WebhookDispatcher;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class RedeliverWebhookDeliveryAction
{
    public function __invoke(
        RedeliverWebhookDeliveryRequest $request,
        Tenant $tenant,
        Project $project,
        WebhookEndpoint $webhookEndpoint,
        WebhookDelivery $delivery,
        WebhookDispatcher $webhookDispatcher,
        Responder $responder,
    ): Response {
        return $responder->resource(WebhookDeliveryResource::make($webhookDispatcher->redeliver($delivery)));
    }
}
