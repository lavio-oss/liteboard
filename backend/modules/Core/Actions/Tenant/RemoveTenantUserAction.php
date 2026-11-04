<?php

namespace Modules\Core\Actions\Tenant;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class RemoveTenantUserAction
{
    /**
     * Removes the person from the tenant and its projects; the account itself goes only when no other tenant uses it.
     */
    public function execute(Tenant $tenant, User $user): void
    {
        DB::transaction(function () use ($tenant, $user): void {
            $user->projects()->detach($tenant->projects()->pluck('id')->all());
            $tenant->users()->detach($user->getKey());

            if (! $user->tenants()->exists()) {
                $user->delete();
            }
        });
    }
}
