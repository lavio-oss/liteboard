<?php

namespace Modules\Masterdata\Policies\Factory;

use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Models\Factory\Factory as FactoryModel;
use Modules\Masterdata\Models\Project\Project;

final class FactoryPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->tenantFor($project)->hasMember($user);
    }

    public function view(User $user, FactoryModel $factory): bool
    {
        return $this->tenantFor($factory)->hasMember($user);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->tenantFor($project)->hasRole($user, TenantRole::Owner, TenantRole::Admin);
    }

    public function update(User $user, FactoryModel $factory): bool
    {
        return $this->tenantFor($factory)->hasRole($user, TenantRole::Owner, TenantRole::Admin);
    }

    public function delete(User $user, FactoryModel $factory): bool
    {
        return $this->tenantFor($factory)->hasRole($user, TenantRole::Owner, TenantRole::Admin);
    }

    private function tenantFor(FactoryModel|Project $resource): Tenant
    {
        $project = $resource instanceof Project
            ? $resource
            : $resource->project()->firstOrFail();

        return $project->tenant()->firstOrFail();
    }
}
