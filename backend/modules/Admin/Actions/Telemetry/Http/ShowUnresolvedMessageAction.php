<?php

namespace Modules\Admin\Actions\Telemetry\Http;

use Modules\Admin\Models\Telemetry\TelemetryUnresolvedMessage;
use Modules\Admin\Requests\Telemetry\ListUnresolvedMessagesRequest;
use Modules\Admin\Resources\Telemetry\TelemetryUnresolvedMessageResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowUnresolvedMessageAction
{
    public function __invoke(
        ListUnresolvedMessagesRequest $request,
        string $processingId,
        Responder $responder,
    ): Response {
        return $responder->resource(TelemetryUnresolvedMessageResource::make(
            TelemetryUnresolvedMessage::query()->findOrFail($processingId),
        ));
    }
}
