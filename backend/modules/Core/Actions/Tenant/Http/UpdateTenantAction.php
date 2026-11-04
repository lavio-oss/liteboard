<?php

namespace Modules\Core\Actions\Tenant\Http;

use Modules\Core\Actions\Tenant\UpdateTenantAction as UpdateTenant;
use Modules\Core\Models\Tenant\Tenant;
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
        /** @var array{name?: string, code?: string, status?: string} $attributes */
        $attributes = $request->validated();

        return $responder->resource(TenantResource::make($updateTenant->execute($tenant, $attributes)));
    }
}
