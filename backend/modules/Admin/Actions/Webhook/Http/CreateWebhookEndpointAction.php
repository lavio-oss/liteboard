<?php

namespace Modules\Admin\Actions\Webhook\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Webhook\StoreWebhookEndpointRequest;
use Modules\Admin\Resources\Webhook\WebhookEndpointResource;
use Modules\Admin\Services\Webhook\WebhookEndpointService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateWebhookEndpointAction
{
    public function __invoke(
        StoreWebhookEndpointRequest $request,
        Tenant $tenant,
        Project $project,
        WebhookEndpointService $webhookEndpointService,
        Responder $responder,
    ): Response {
        [$endpoint, $secret] = $webhookEndpointService->create($project, $request->endpointAttributes());

        return $responder->resource(
            WebhookEndpointResource::make($endpoint)->additional(['secret' => $secret]),
            Response::HTTP_CREATED,
        );
    }
}
