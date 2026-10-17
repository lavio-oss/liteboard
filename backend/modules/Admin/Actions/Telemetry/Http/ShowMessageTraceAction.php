<?php

namespace Modules\Admin\Actions\Telemetry\Http;

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Telemetry\ViewDeviceTelemetryRequest;
use Modules\Admin\Resources\Telemetry\TelemetryIngestOutcomeResource;
use Modules\Admin\Resources\Telemetry\TelemetrySampleResource;
use Modules\Admin\Services\Telemetry\TelemetryQueryService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowMessageTraceAction
{
    public function __invoke(
        ViewDeviceTelemetryRequest $request,
        Tenant $tenant,
        Project $project,
        Device $device,
        string $message,
        TelemetryQueryService $telemetryQueryService,
        Responder $responder,
    ): Response {
        $trace = $telemetryQueryService->trace($device, $message);

        return $responder->json(['data' => [
            'outcomes' => TelemetryIngestOutcomeResource::collection($trace['outcomes']),
            'sample' => $trace['sample'] === null ? null : TelemetrySampleResource::make($trace['sample']),
        ]]);
    }
}
