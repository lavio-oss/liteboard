<?php

namespace Modules\Core\Actions\Role\Http;

use Modules\Core\Models\Role\Role;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Requests\Role\DeleteRoleRequest;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class DeleteRoleAction
{
    public function __invoke(DeleteRoleRequest $request, Tenant $tenant, Role $role, Responder $responder): Response
    {
        $role->delete();

        return $responder->noContent();
    }
}
