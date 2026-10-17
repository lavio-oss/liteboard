<?php

namespace Modules\Admin\Actions\Telemetry\Http;

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Telemetry\ListTelemetryHistoryRequest;
use Modules\Admin\Resources\Telemetry\TelemetrySampleResource;
use Modules\Admin\Services\Telemetry\TelemetryQueryService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListTelemetryHistoryAction
{
    public function __invoke(
        ListTelemetryHistoryRequest $request,
        Tenant $tenant,
        Project $project,
        Device $device,
        TelemetryQueryService $telemetryQueryService,
        Responder $responder,
    ): Response {
        $page = $telemetryQueryService->history(
            $device,
            $request->from(),
            $request->to(),
            $request->metrics(),
            $request->integer('limit', 500),
            $request->has('cursor') ? $request->cursor() : null,
        );

        return $responder->json([
            'data' => TelemetrySampleResource::collection($page['samples']),
            'meta' => ['next_cursor' => $page['next_cursor']],
        ]);
    }
}
