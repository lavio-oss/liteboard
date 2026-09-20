<?php

namespace Modules\Core\Services\Tenant;

use Illuminate\Support\Facades\Context;
use Modules\Core\Models\Tenant\Tenant;

final class TenantContext
{
    public function set(Tenant $tenant): void
    {
        Context::add('tenant_id', (string) $tenant->getKey());
    }
}
