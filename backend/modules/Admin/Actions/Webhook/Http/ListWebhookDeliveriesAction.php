<?php

namespace Modules\Admin\Actions\Webhook\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Admin\Requests\Webhook\WebhookDeliveryIndexRequest;
use Modules\Admin\Resources\Webhook\WebhookDeliveryResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListWebhookDeliveriesAction
{
    public function __invoke(
        WebhookDeliveryIndexRequest $request,
        Tenant $tenant,
        Project $project,
        WebhookEndpoint $webhookEndpoint,
        Responder $responder,
    ): Response {
        $deliveries = $webhookEndpoint->deliveries()
            ->when($request->has('status'), static fn ($query) => $query->where('status', $request->string('status')->value()))
            ->with(['outboxEvent', 'attemptHistory' => static fn ($query) => $query->orderBy('attempt_no')])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('limit', 50));

        return $responder->resource(WebhookDeliveryResource::collection($deliveries));
    }
}
