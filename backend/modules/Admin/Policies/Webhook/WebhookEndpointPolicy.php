<?php

namespace Modules\Admin\Policies\Webhook;

use Modules\Admin\Enums\Project\ProjectRole;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\User\User;

/**
 * Endpoints expose integration targets, so viewing needs Engineer; changing them or their secret needs Admin.
 */
final class WebhookEndpointPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->allows($user, $project, ProjectRole::Admin, ProjectRole::Engineer);
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allows($user, $endpoint->project()->firstOrFail(), ProjectRole::Admin, ProjectRole::Engineer);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->allows($user, $project, ProjectRole::Admin);
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allows($user, $endpoint->project()->firstOrFail(), ProjectRole::Admin);
    }

    public function rotateSecret(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allows($user, $endpoint->project()->firstOrFail(), ProjectRole::Admin);
    }

    public function redeliver(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allows($user, $endpoint->project()->firstOrFail(), ProjectRole::Admin);
    }

    private function allows(User $user, Project $project, ProjectRole ...$roles): bool
    {
        return $user->isPlatformAdmin()
            || $project->tenant()->firstOrFail()->hasRole($user, TenantRole::Owner, TenantRole::Admin)
            || $project->hasRole($user, ...$roles);
    }
}
