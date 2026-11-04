<?php

namespace Modules\Admin\Resources\Project;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Role\PermissionResolver;

/**
 * `permissions` are the requesting user's effective permissions on this project.
 *
 * @mixin Project
 */
final class ProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Project $project */
        $project = $this->resource;
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('A project is only serialized for an authenticated user.');
        }

        $provisioningProfile = $project->provisioningProfile;
        $createdAt = $project->getAttribute('created_at');
        $updatedAt = $project->getAttribute('updated_at');

        return [
            'id' => (string) $project->getKey(),
            'tenant_id' => (string) $project->getAttribute('tenant_id'),
            'name' => (string) $project->name,
            'description' => $project->description,
            'status' => (string) $project->status,
            'max_devices_quota' => $project->getAttribute('max_devices_quota'),
            'device_count' => $project->devices()->count(),
            // null when the project has no provisioning profile yet.
            'provisioning_enabled' => $provisioningProfile === null ? null : (bool) $provisioningProfile->getAttribute('enabled'),
            'permissions' => array_map(
                static fn (Permission $permission): string => $permission->value,
                app(PermissionResolver::class)->permissions($user, $project),
            ),
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toISOString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toISOString() : null,
        ];
    }
}
