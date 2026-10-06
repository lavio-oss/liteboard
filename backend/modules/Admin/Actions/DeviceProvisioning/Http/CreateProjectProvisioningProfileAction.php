<?php

namespace Modules\Admin\Actions\DeviceProvisioning\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\DeviceProvisioning\ManageProjectProvisioningRequest;
use Modules\Admin\Resources\DeviceProvisioning\ProjectProvisioningProfileResource;
use Modules\Admin\Services\DeviceProvisioning\DeviceProvisioningException;
use Modules\Admin\Services\DeviceProvisioning\DeviceProvisioningService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateProjectProvisioningProfileAction
{
    public function __invoke(
        ManageProjectProvisioningRequest $request,
        Tenant $tenant,
        Project $project,
        DeviceProvisioningService $service,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $service->createProfile($project, (string) $user->getKey());
        } catch (DeviceProvisioningException $exception) {
            return $responder->json(['code' => $exception->getMessage()], $exception->status);
        }

        return $responder->json([
            'data' => ProjectProvisioningProfileResource::make($result['profile'])->resolve($request),
            'provision_secret' => $result['secret'],
        ], Response::HTTP_CREATED)->header('Cache-Control', 'no-store');
    }
}
