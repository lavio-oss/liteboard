<?php

namespace Modules\Admin\Actions\DeviceProfile\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\DeviceProfile\DeviceProfileIndexRequest;
use Modules\Admin\Resources\DeviceProfile\DeviceProfileResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListDeviceProfilesAction
{
    public function __invoke(
        DeviceProfileIndexRequest $request,
        Tenant $tenant,
        Project $project,
        Responder $responder,
    ): Response {
        $profiles = $project->deviceProfiles()
            ->with(['versions' => static fn ($query) => $query->orderBy('version')])
            ->orderBy('name')
            ->get();

        return $responder->resource(DeviceProfileResource::collection($profiles));
    }
}
