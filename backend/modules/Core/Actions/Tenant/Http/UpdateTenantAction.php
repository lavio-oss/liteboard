<?php

namespace Modules\Core\Actions\Tenant\Http;

use Modules\Core\Actions\Tenant\UpdateTenantAction as UpdateTenant;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Tenant\UpdateTenantRequest;
use Modules\Core\Resources\Tenant\TenantResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdateTenantAction
{
    public function __invoke(
        UpdateTenantRequest $request,
        Tenant $tenant,
        UpdateTenant $updateTenant,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        /** @var array{name?: string, code?: string, status?: string} $attributes */
        $attributes = $request->validated();
        $updateTenant->execute($tenant, $attributes);
        $membership = $user->isPlatformAdmin()
            ? $tenant
            : $user->tenants()->whereKey($tenant->getKey())->firstOrFail();

        return $responder->resource(TenantResource::make($membership));
    }
}
