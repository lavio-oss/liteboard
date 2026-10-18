<?php

namespace Modules\Admin\Actions\Alert\Http;

use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Alert\AcknowledgeAlertRequest;
use Modules\Admin\Resources\Alert\AlertResource;
use Modules\Admin\Services\Alert\AlertAcknowledgementService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class AcknowledgeAlertAction
{
    public function __invoke(
        AcknowledgeAlertRequest $request,
        Tenant $tenant,
        Project $project,
        Alert $alert,
        AlertAcknowledgementService $alertAcknowledgementService,
        Responder $responder,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        return $responder->resource(AlertResource::make($alertAcknowledgementService->acknowledge($alert, $user)));
    }
}
