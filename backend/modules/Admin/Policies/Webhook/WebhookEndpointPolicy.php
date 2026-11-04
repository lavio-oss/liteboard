<?php

namespace Modules\Admin\Policies\Webhook;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Role\PermissionResolver;

final class WebhookEndpointPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::WebhookView, $project);
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->permissions->allows($user, Permission::WebhookView, $endpoint->project()->firstOrFail());
    }

    public function create(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::WebhookManage, $project);
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->permissions->allows($user, Permission::WebhookManage, $endpoint->project()->firstOrFail());
    }

    public function rotateSecret(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->permissions->allows($user, Permission::WebhookManage, $endpoint->project()->firstOrFail());
    }

    public function redeliver(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->permissions->allows($user, Permission::WebhookManage, $endpoint->project()->firstOrFail());
    }
}
