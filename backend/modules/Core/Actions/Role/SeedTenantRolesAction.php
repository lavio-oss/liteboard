<?php

namespace Modules\Core\Actions\Role;

use Modules\Core\Enums\Role\DefaultRole;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\Tenant\Tenant;

final class SeedTenantRolesAction
{
    public function execute(Tenant $tenant): void
    {
        foreach (DefaultRole::cases() as $role) {
            $tenant->roles()->firstOrCreate(
                ['scope' => $role->scope(), 'key' => $role->key()],
                [
                    'name' => $role->label(),
                    'is_system' => $role->isSystem(),
                    'permissions' => array_map(
                        static fn (Permission $permission): string => $permission->value,
                        $role->permissions(),
                    ),
                ],
            );
        }
    }
}
