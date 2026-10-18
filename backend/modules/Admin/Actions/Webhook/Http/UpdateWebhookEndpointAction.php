<?php

namespace Modules\Admin\Actions\Webhook\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Admin\Requests\Webhook\UpdateWebhookEndpointRequest;
use Modules\Admin\Resources\Webhook\WebhookEndpointResource;
use Modules\Admin\Services\Webhook\WebhookEndpointService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdateWebhookEndpointAction
{
    public function __invoke(
        UpdateWebhookEndpointRequest $request,
        Tenant $tenant,
        Project $project,
        WebhookEndpoint $webhookEndpoint,
        WebhookEndpointService $webhookEndpointService,
        Responder $responder,
    ): Response {
        return $responder->resource(WebhookEndpointResource::make(
            $webhookEndpointService->update($webhookEndpoint, $request->endpointAttributes()),
        ));
    }
}
