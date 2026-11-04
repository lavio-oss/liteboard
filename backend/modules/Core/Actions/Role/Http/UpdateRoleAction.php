<?php

namespace Modules\Core\Actions\Role\Http;

use Modules\Core\Models\Role\Role;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Requests\Role\UpdateRoleRequest;
use Modules\Core\Resources\Role\RoleResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdateRoleAction
{
    public function __invoke(UpdateRoleRequest $request, Tenant $tenant, Role $role, Responder $responder): Response
    {
        /** @var array{name?: string, permissions?: list<string>} $attributes */
        $attributes = $request->validated();
        $role->fill($attributes)->save();

        return $responder->resource(RoleResource::make($role->refresh()->load('creator')));
    }
}
