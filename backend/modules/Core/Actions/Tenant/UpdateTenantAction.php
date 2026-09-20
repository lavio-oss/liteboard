<?php

namespace Modules\Core\Actions\Tenant;

use Modules\Core\Models\Tenant\Tenant;

final class UpdateTenantAction
{
    /**
     * @param  array{name?: string, code?: string, status?: string}  $attributes
     */
    public function execute(Tenant $tenant, array $attributes): Tenant
    {
        $tenant->fill($attributes);
        $tenant->save();

        return $tenant->refresh();
    }
}
