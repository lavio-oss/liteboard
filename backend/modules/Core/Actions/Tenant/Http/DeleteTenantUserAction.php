<?php

namespace Modules\Core\Actions\Tenant\Http;

use Modules\Core\Actions\Tenant\RemoveTenantUserAction;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Tenant\DeleteTenantUserRequest;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class DeleteTenantUserAction
{
    public function __invoke(
        DeleteTenantUserRequest $request,
        Tenant $tenant,
        User $user,
        RemoveTenantUserAction $removeTenantUser,
        Responder $responder,
    ): Response {
        $removeTenantUser->execute($tenant, $user);

        return $responder->noContent();
    }
}
