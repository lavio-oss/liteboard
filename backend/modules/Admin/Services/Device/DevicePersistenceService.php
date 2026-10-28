<?php

namespace Modules\Admin\Services\Device;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Services\DeviceProfile\DeviceProfilePersistenceService;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DevicePersistenceService
{
    private const string DEFAULT_PROFILE_CODE = 'default';

    public function __construct(
        private readonly DeviceCredentialService $deviceCredentialService,
        private readonly DeviceProfilePersistenceService $deviceProfilePersistenceService,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     code: string,
     *     description?: string|null,
     *     equipment_asset_id?: string|null,
     *     device_profile_version_id?: string|null,
     *     status?: string,
     *     is_gateway?: bool,
     *     gateway_device_id?: string
     * }  $attributes
     */
    public function create(Project $project, array $attributes, bool $issueCredential = true): Device
    {
        return DB::transaction(function () use ($project, $attributes, $issueCredential): Device {
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $quota = $lockedProject->getAttribute('max_devices_quota');

            if ($quota !== null && $lockedProject->devices()->count() >= (int) $quota) {
                throw new ConflictHttpException('PROJECT_DEVICE_QUOTA_EXCEEDED');
            }

            $attributes['device_profile_version_id'] ??= $this->defaultProfileVersionId($lockedProject);

            $device = $lockedProject->devices()->create($attributes);

            if ($issueCredential) {
                $this->deviceCredentialService->issueForDevice($device);
            }

            return $device->refresh()->load('credential');
        });
    }

    /**
     * Sub-devices only reach the platform through their gateway, so they get no credential of their own.
     * The project row lock serializes concurrent first messages that name the same new code.
     */
    public function findOrCreateSubDevice(Device $gateway, string $code): Device
    {
        return DB::transaction(function () use ($gateway, $code): Device {
            $project = Project::query()->whereKey($gateway->getAttribute('project_id'))->lockForUpdate()->firstOrFail();
            $existing = $project->devices()->where('code', $code)->first();

            if ($existing !== null) {
                if ($existing->getAttribute('gateway_device_id') !== $gateway->getKey()) {
                    throw new ConflictHttpException('GATEWAY_SUB_DEVICE_CODE_TAKEN');
                }

                return $existing;
            }

            return $this->create($project, [
                'name' => $code,
                'code' => $code,
                'gateway_device_id' => (string) $gateway->getKey(),
            ], issueCredential: false);
        });
    }

    /**
     * The project's Default profile (code "default") receives every device that has no other profile; it is
     * created schemaless on first use. Callers hold the project row lock, so two devices cannot race to create it.
     */
    private function defaultProfileVersionId(Project $project): string
    {
        $profile = $project->deviceProfiles()->where('code', self::DEFAULT_PROFILE_CODE)->first()
            ?? $this->deviceProfilePersistenceService->create($project, [
                'code' => self::DEFAULT_PROFILE_CODE,
                'name' => 'Default',
            ]);
        $version = $profile->versions()->orderByDesc('version')->first();

        if ($version === null) {
            $schemaCodeTaken = DeviceProfileVersion::query()
                ->where('project_id', $project->getKey())
                ->where('schema_code', self::DEFAULT_PROFILE_CODE)
                ->exists();
            $version = $this->deviceProfilePersistenceService->createVersion(
                $profile,
                $schemaCodeTaken ? self::DEFAULT_PROFILE_CODE.'-'.Str::lower(Str::random(6)) : self::DEFAULT_PROFILE_CODE,
                ['strict_mode' => false, 'fields' => []],
                null,
            );
        }

        return (string) $version->getKey();
    }

    /**
     * @param  array{
     *     name?: string,
     *     code?: string,
     *     description?: string|null,
     *     equipment_asset_id?: string|null,
     *     device_profile_version_id?: string|null,
     *     status?: string,
     *     allow_plaintext?: bool,
     *     is_gateway?: bool
     * }  $attributes
     */
    public function update(Device $device, array $attributes): Device
    {
        return DB::transaction(function () use ($device, $attributes): Device {
            if (array_key_exists('device_profile_version_id', $attributes) && $attributes['device_profile_version_id'] === null) {
                $lockedProject = Project::query()->whereKey($device->getAttribute('project_id'))->lockForUpdate()->firstOrFail();
                $attributes['device_profile_version_id'] = $this->defaultProfileVersionId($lockedProject);
            }

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
