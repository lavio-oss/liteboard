<?php

namespace Modules\Core\Actions\Role\Http;

use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Requests\Role\RoleIndexRequest;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListPermissionsAction
{
    public function __invoke(RoleIndexRequest $request, Tenant $tenant, Responder $responder): Response
    {
        return $responder->json([
            'data' => array_map(
                static fn (Permission $permission): array => [
                    'key' => $permission->value,
                    'scope' => $permission->scope()->value,
                ],
                Permission::cases(),
            ),
        ]);
    }
}
