<?php

namespace Modules\Core\Actions\Tenant\Http;

use Modules\Core\Actions\Tenant\CreateTenantUserAction as CreateTenantUser;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Requests\Tenant\StoreTenantUserRequest;
use Modules\Core\Resources\Tenant\TenantUserResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateTenantUserAction
{
    public function __invoke(
        StoreTenantUserRequest $request,
        Tenant $tenant,
        CreateTenantUser $createTenantUser,
        Responder $responder,
    ): Response {
        /** @var array{name: string, email: string, password: string} $attributes */
        $attributes = $request->validated();
        $user = $createTenantUser->execute($tenant, $attributes);

        return $responder->resource(TenantUserResource::make($user), Response::HTTP_CREATED);
    }
}
