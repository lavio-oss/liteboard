<?php

namespace Modules\Masterdata\Policies\ProductionLine;

use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Models\Factory\Factory;
use Modules\Masterdata\Models\ProductionLine\ProductionLine;

final class ProductionLinePolicy
{
    public function viewAny(User $user, Factory $factory): bool
    {
        return $this->tenantFor($factory)->hasMember($user);
    }

    public function view(User $user, ProductionLine $productionLine): bool
    {
        return $this->tenantFor($productionLine)->hasMember($user);
    }

    public function create(User $user, Factory $factory): bool
    {
        return $this->tenantFor($factory)->hasRole($user, TenantRole::Owner, TenantRole::Admin);
    }

    public function update(User $user, ProductionLine $productionLine): bool
    {
        return $this->tenantFor($productionLine)->hasRole($user, TenantRole::Owner, TenantRole::Admin);
    }

    public function delete(User $user, ProductionLine $productionLine): bool
    {
        return $this->tenantFor($productionLine)->hasRole($user, TenantRole::Owner, TenantRole::Admin);
    }

    private function tenantFor(Factory|ProductionLine $resource): Tenant
    {
        $factory = $resource instanceof Factory
            ? $resource
            : $resource->productionFactory()->firstOrFail();
        $project = $factory->project()->firstOrFail();

        return $project->tenant()->firstOrFail();
    }
}
