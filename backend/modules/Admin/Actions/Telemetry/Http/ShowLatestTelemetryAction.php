<?php

namespace Modules\Admin\Actions\Telemetry\Http;

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Telemetry\ViewDeviceTelemetryRequest;
use Modules\Admin\Resources\Telemetry\TelemetryLatestValueResource;
use Modules\Admin\Services\Telemetry\TelemetryQueryService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowLatestTelemetryAction
{
    public function __invoke(
        ViewDeviceTelemetryRequest $request,
        Tenant $tenant,
        Project $project,
        Device $device,
        TelemetryQueryService $telemetryQueryService,
        Responder $responder,
    ): Response {
        return $responder->resource(TelemetryLatestValueResource::collection($telemetryQueryService->latest($device)));
    }
}
