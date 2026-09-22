<?php

namespace Modules\Core\Actions\Tenant\Http;

use Modules\Core\Actions\Tenant\UpdateTenantUserAction as UpdateTenantUser;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Tenant\UpdateTenantUserRequest;
use Modules\Core\Resources\Tenant\TenantUserResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdateTenantUserAction
{
    public function __invoke(
        UpdateTenantUserRequest $request,
        Tenant $tenant,
        User $user,
        UpdateTenantUser $updateTenantUser,
        Responder $responder,
    ): Response {
        /** @var array{name?: string, email?: string, password?: string|null} $attributes */
        $attributes = $request->validated();
        $updatedUser = $updateTenantUser->execute($user, $attributes);
        $updatedUser->load([
            'tenants' => static function ($query) use ($tenant): void {
                $query->whereKey($tenant->getKey());
            },
        ]);

        return $responder->resource(TenantUserResource::make($updatedUser));
    }
}
