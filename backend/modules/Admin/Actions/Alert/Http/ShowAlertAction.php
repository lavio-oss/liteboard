<?php

namespace Modules\Admin\Actions\Alert\Http;

use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Alert\ShowAlertRequest;
use Modules\Admin\Resources\Alert\AlertResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowAlertAction
{
    public function __invoke(
        ShowAlertRequest $request,
        Tenant $tenant,
        Project $project,
        Alert $alert,
        Responder $responder,
    ): Response {
        $alert->load(['events' => static fn ($query) => $query->orderBy('occurred_at')->orderBy('id')]);

        return $responder->resource(AlertResource::make($alert));
    }
}
