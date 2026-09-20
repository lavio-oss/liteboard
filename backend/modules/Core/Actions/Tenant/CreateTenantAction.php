<?php

namespace Modules\Core\Actions\Tenant;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class CreateTenantAction
{
    /**
     * @param  array{name: string, code: string, status?: string}  $attributes
     */
    public function execute(User $user, array $attributes): Tenant
    {
        return DB::transaction(function () use ($user, $attributes): Tenant {
            $tenant = Tenant::query()->create($attributes);
            $tenant->users()->attach($user->getKey(), ['role' => TenantRole::Owner->value]);

            return $user->tenants()->whereKey($tenant->getKey())->firstOrFail();
        });
    }
}
