<?php

namespace Modules\Admin\Actions\Webhook\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Webhook\WebhookEndpointIndexRequest;
use Modules\Admin\Resources\Webhook\WebhookEndpointResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListWebhookEndpointsAction
{
    public function __invoke(
        WebhookEndpointIndexRequest $request,
        Tenant $tenant,
        Project $project,
        Responder $responder,
    ): Response {
        return $responder->resource(WebhookEndpointResource::collection(
            $project->webhookEndpoints()->orderBy('name')->get(),
        ));
    }
}
