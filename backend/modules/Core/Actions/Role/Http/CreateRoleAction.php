<?php

namespace Modules\Core\Actions\Role\Http;

use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Requests\Role\StoreRoleRequest;
use Modules\Core\Resources\Role\RoleResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateRoleAction
{
    public function __invoke(StoreRoleRequest $request, Tenant $tenant, Responder $responder): Response
    {
        /** @var array{name: string, scope: string, permissions: list<string>} $attributes */
        $attributes = $request->validated();
        $role = $tenant->roles()->create([...$attributes, 'is_system' => false, 'created_by' => $request->user()?->getKey()]);

        return $responder->resource(RoleResource::make($role->refresh()->load('creator')), Response::HTTP_CREATED);
    }
}
