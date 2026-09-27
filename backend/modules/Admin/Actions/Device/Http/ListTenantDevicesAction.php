<?php

namespace Modules\Admin\Actions\Device\Http;

use Modules\Admin\Requests\Device\TenantDeviceIndexRequest;
use Modules\Admin\Resources\Device\DeviceResource;
use Modules\Admin\Services\Device\DeviceQueryService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListTenantDevicesAction
{
    public function __invoke(
        TenantDeviceIndexRequest $request,
        Tenant $tenant,
        DeviceQueryService $deviceQueryService,
        Responder $responder,
    ): Response {
        $devices = $deviceQueryService->paginateForTenant(
            $tenant,
            $request->string('search')->value(),
            $request->filled('status') ? $request->string('status')->value() : null,
            $request->filled('connection_state') ? $request->string('connection_state')->value() : null,
            $request->filled('project_id') ? $request->string('project_id')->value() : null,
            $request->filled('equipment_asset_id') ? $request->string('equipment_asset_id')->value() : null,
            $request->integer('per_page', 15),
        );

        return $responder->resource(DeviceResource::collection($devices));
    }
}
