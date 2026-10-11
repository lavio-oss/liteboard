<?php

namespace Modules\Admin\Actions\DeviceProfile\Http;

use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\DeviceProfile\DeleteDeviceProfileVersionRequest;
use Modules\Core\Models\Tenant\Tenant;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DeleteDeviceProfileVersionAction
{
    public function __invoke(
        DeleteDeviceProfileVersionRequest $request,
        Tenant $tenant,
        Project $project,
        DeviceProfile $deviceProfile,
        DeviceProfileVersion $version,
    ): never {
        throw new ConflictHttpException('DEVICE_PROFILE_VERSION_IMMUTABLE');
    }
}
