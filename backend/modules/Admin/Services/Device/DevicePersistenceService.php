<?php

namespace Modules\Admin\Services\Device;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use RuntimeException;

final class DevicePersistenceService
{
    public function __construct(private readonly DeviceCredentialService $deviceCredentialService) {}

    /**
     * @param  array{
     *     name: string,
     *     code: string,
     *     description?: string|null,
     *     equipment_asset_id?: string|null,
     *     status?: string
     * }  $attributes
     */
    public function create(Project $project, array $attributes): Device
    {
        return DB::transaction(function () use ($project, $attributes): Device {
            $device = $project->devices()->create($attributes);
            $this->deviceCredentialService->issueForDevice($device);

            return $device->refresh()->load('credential');
        });
    }

    /**
     * @param  array{
     *     name?: string,
     *     code?: string,
     *     description?: string|null,
     *     equipment_asset_id?: string|null,
     *     status?: string
     * }  $attributes
     */
    public function update(Device $device, array $attributes): Device
    {
        return DB::transaction(function () use ($device, $attributes): Device {
            $device->fill($attributes);
            $device->save();

            return $device->refresh()->load('credential');
        });
    }

    public function delete(Device $device): void
    {
        DB::transaction(static function () use ($device): void {
            if (! $device->delete()) {
                throw new RuntimeException('Device deletion was cancelled.');
            }
        });
    }
}
