<?php

namespace Modules\Admin\Actions\Telemetry\Http;

use Modules\Admin\Requests\Telemetry\ViewTenantDevicePresenceRequest;
use Modules\Admin\Services\Telemetry\DevicePresenceStreamTicketService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class IssueDevicePresenceStreamTicketAction
{
    public function __invoke(
        ViewTenantDevicePresenceRequest $request,
        Tenant $tenant,
        DevicePresenceStreamTicketService $ticketService,
        Responder $responder,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        return $responder->json(['data' => $ticketService->issue($user, $tenant)], Response::HTTP_CREATED);
    }
}
