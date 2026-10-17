<?php

namespace Modules\Admin\Actions\Telemetry\Http;

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Telemetry\ListTelemetryAggregatesRequest;
use Modules\Admin\Services\Telemetry\TelemetryQueryService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListTelemetryAggregatesAction
{
    public function __invoke(
        ListTelemetryAggregatesRequest $request,
        Tenant $tenant,
        Project $project,
        Device $device,
        TelemetryQueryService $telemetryQueryService,
        Responder $responder,
    ): Response {
        return $responder->json([
            'data' => $telemetryQueryService->aggregates(
                $device,
                $request->from(),
                $request->to(),
                $request->bucketInterval(),
                $request->metrics(),
            ),
        ]);
    }
}
