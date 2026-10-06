<?php

namespace Modules\Admin\Actions\Device\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Device\StoreDeviceRequest;
use Modules\Admin\Resources\Device\DeviceResource;
use Modules\Admin\Services\Device\DevicePersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CreateDeviceAction
{
    public function __invoke(
        StoreDeviceRequest $request,
        Tenant $tenant,
        Project $project,
        DevicePersistenceService $devicePersistenceService,
        Responder $responder,
    ): Response {
        $deviceInput = $request->deviceInput();
        try {
            $device = $devicePersistenceService->create(
                $project,
                $deviceInput['attributes'],
            );
        } catch (ConflictHttpException $exception) {
            if ($exception->getMessage() !== 'PROJECT_DEVICE_QUOTA_EXCEEDED') {
                throw $exception;
            }

            return $responder->json(['code' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return $responder->resource(DeviceResource::make($device), Response::HTTP_CREATED);
    }
}
