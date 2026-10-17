<?php

namespace Modules\Admin\Actions\Telemetry\Http;

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Telemetry\ListMessageOutcomesRequest;
use Modules\Admin\Resources\Telemetry\TelemetryIngestOutcomeResource;
use Modules\Admin\Services\Telemetry\TelemetryQueryService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListMessageOutcomesAction
{
    public function __invoke(
        ListMessageOutcomesRequest $request,
        Tenant $tenant,
        Project $project,
        Device $device,
        TelemetryQueryService $telemetryQueryService,
        Responder $responder,
    ): Response {
        return $responder->resource(TelemetryIngestOutcomeResource::collection(
            $telemetryQueryService->recentOutcomes($device, $request->integer('limit', 50)),
        ));
    }
}
