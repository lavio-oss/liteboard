<?php

namespace Modules\Admin\Actions\Telemetry\Http;

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Telemetry\ViewDeviceTelemetryRequest;
use Modules\Admin\Services\Telemetry\TelemetryStreamTicketService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class IssueTelemetryStreamTicketAction
{
    public function __invoke(
        ViewDeviceTelemetryRequest $request,
        Tenant $tenant,
        Project $project,
        Device $device,
        TelemetryStreamTicketService $ticketService,
        Responder $responder,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        return $responder->json(['data' => $ticketService->issue($user, $device)], Response::HTTP_CREATED);
    }
}
