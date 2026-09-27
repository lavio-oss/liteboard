<?php

namespace Modules\Admin\Actions\Device\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Device\DeviceIndexRequest;
use Modules\Admin\Resources\Device\DeviceResource;
use Modules\Admin\Services\Device\DeviceQueryService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListDevicesAction
{
    public function __invoke(
        DeviceIndexRequest $request,
        Tenant $tenant,
        Project $project,
        DeviceQueryService $deviceQueryService,
        Responder $responder,
    ): Response {
        $devices = $deviceQueryService->paginateForProject(
            $project,
            $request->string('search')->value(),
            $request->filled('status') ? $request->string('status')->value() : null,
            $request->filled('connection_state') ? $request->string('connection_state')->value() : null,
            $request->filled('equipment_asset_id') ? $request->string('equipment_asset_id')->value() : null,
            $request->integer('per_page', 15),
        );

        return $responder->resource(DeviceResource::collection($devices));
    }
}
