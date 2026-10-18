<?php

namespace Modules\Admin\Actions\Webhook\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Admin\Requests\Webhook\RotateWebhookSecretRequest;
use Modules\Admin\Resources\Webhook\WebhookEndpointResource;
use Modules\Admin\Services\Webhook\WebhookEndpointService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class RotateWebhookSecretAction
{
    public function __invoke(
        RotateWebhookSecretRequest $request,
        Tenant $tenant,
        Project $project,
        WebhookEndpoint $webhookEndpoint,
        WebhookEndpointService $webhookEndpointService,
        Responder $responder,
    ): Response {
        $secret = $webhookEndpointService->rotateSecret($webhookEndpoint);

        return $responder->resource(WebhookEndpointResource::make($webhookEndpoint)->additional(['secret' => $secret]));
    }
}
