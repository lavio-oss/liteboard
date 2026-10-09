<?php

namespace Modules\Admin\Services\DeviceProfile;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DeviceProfilePersistenceService
{
    /**
     * @param  array{code: string, name: string, description?: string|null}  $attributes
     */
    public function create(Project $project, array $attributes): DeviceProfile
    {
        return $project->deviceProfiles()->create($attributes);
    }

    /**
     * @param  array{code?: string, name?: string, description?: string|null}  $attributes
     */
    public function update(DeviceProfile $deviceProfile, array $attributes): DeviceProfile
    {
        $deviceProfile->fill($attributes);
        $deviceProfile->save();

        return $deviceProfile->refresh()->load([
            'versions' => static fn ($query) => $query->orderBy('version'),
        ]);
    }

    /**
     * @param array{
     *     strict_mode: bool,
     *     fields: array<string, array{type: string, unit: string, required: bool, min?: int|float, max?: int|float, allowed_values?: list<string>}>
     * } $definition
     */
    public function createVersion(
        DeviceProfile $deviceProfile,
        string $schemaCode,
        array $definition,
        ?string $compatibilityNote,
    ): DeviceProfileVersion {
        return DB::transaction(function () use (
            $deviceProfile,
            $schemaCode,
            $definition,
            $compatibilityNote,
        ): DeviceProfileVersion {
            $lockedProfile = DeviceProfile::query()
                ->whereKey($deviceProfile->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $nextVersion = (int) $lockedProfile->versions()->max('version') + 1;
            $version = $lockedProfile->versions()->make([
                'version' => $nextVersion,
                'schema_code' => $schemaCode,
                'definition' => $definition,
                'compatibility_note' => $compatibilityNote,
            ]);
            $version->setAttribute('project_id', $lockedProfile->getAttribute('project_id'));
            $version->save();

            return $version;
        });
    }

    public function delete(DeviceProfile $deviceProfile): void
    {
        DB::transaction(function () use ($deviceProfile): void {
            $lockedProfile = DeviceProfile::query()
                ->whereKey($deviceProfile->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedProfile->versions()->whereHas('devices')->exists()) {
                throw new ConflictHttpException('DEVICE_PROFILE_IN_USE');
            }

            if (! $lockedProfile->delete()) {
                throw new RuntimeException('Device profile deletion was cancelled.');
            }
        });
    }
}
