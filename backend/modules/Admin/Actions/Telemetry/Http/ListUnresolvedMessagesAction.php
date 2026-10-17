<?php

namespace Modules\Admin\Actions\Telemetry\Http;

use Modules\Admin\Requests\Telemetry\ListUnresolvedMessagesRequest;
use Modules\Admin\Resources\Telemetry\TelemetryUnresolvedMessageResource;
use Modules\Admin\Services\Telemetry\TelemetryQueryService;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListUnresolvedMessagesAction
{
    public function __invoke(
        ListUnresolvedMessagesRequest $request,
        TelemetryQueryService $telemetryQueryService,
        Responder $responder,
    ): Response {
        return $responder->resource(TelemetryUnresolvedMessageResource::collection(
            $telemetryQueryService->recentUnresolvedMessages($request->integer('limit', 50)),
        ));
    }
}
