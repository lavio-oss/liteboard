<?php

namespace Modules\Core\Services\Role;

use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\Role\Role;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/**
 * Resolves a user's effective permissions. A tenant role applies to every project of the tenant;
 * a project role adds to it for that project only. Memberships are memoised for one request.
 */
final class PermissionResolver
{
    /** @var array<string, Role|null> */
    private array $roles = [];

    public function allows(User $user, Permission $permission, Tenant|Project $scope): bool
    {
        return in_array($permission, $this->permissions($user, $scope), true);
    }

    /** @return list<Permission> */
    public function permissions(User $user, Tenant|Project $scope): array
    {
        $tenantId = $scope instanceof Tenant ? (string) $scope->getKey() : (string) $scope->getAttribute('tenant_id');
        $tenantRole = $this->tenantRole($user, $tenantId);

        if ($tenantRole === null) {
            return [];
        }

        if ($scope instanceof Tenant || $tenantRole->isOwner()) {
            return $tenantRole->grantedPermissions();
        }

        $projectRole = $this->projectRole($user, (string) $scope->getKey());

        return array_values(array_unique(
            [...$tenantRole->grantedPermissions(), ...($projectRole?->grantedPermissions() ?? [])],
            SORT_REGULAR,
        ));
    }

    public function tenantRole(User $user, string $tenantId): ?Role
    {
        $memoKey = "tenant:$tenantId:{$user->getKey()}";

        if (! array_key_exists($memoKey, $this->roles)) {
            $this->roles[$memoKey] = Role::query()
                ->join('tenant_user', 'tenant_user.role_id', '=', 'roles.id')
                ->where('tenant_user.tenant_id', $tenantId)
                ->where('tenant_user.user_id', $user->getKey())
                ->first(['roles.*']);
        }

        return $this->roles[$memoKey];
    }

    public function isOwner(User $user, Tenant $tenant): bool
    {
        return $this->tenantRole($user, (string) $tenant->getKey())?->isOwner() === true;
    }

    public function allowsInAnyTenant(User $user, Permission $permission): bool
    {
        return $user->tenants()->get()->contains(
            fn (Tenant $tenant): bool => $this->allows($user, $permission, $tenant),
        );
    }

    public function flush(): void
    {
        $this->roles = [];
    }

    private function projectRole(User $user, string $projectId): ?Role
    {
        $memoKey = "project:$projectId:{$user->getKey()}";

        if (! array_key_exists($memoKey, $this->roles)) {
            $this->roles[$memoKey] = Role::query()
                ->join('project_user', 'project_user.role_id', '=', 'roles.id')
                ->where('project_user.project_id', $projectId)
                ->where('project_user.user_id', $user->getKey())
                ->first(['roles.*']);
        }

        return $this->roles[$memoKey];
    }
}
