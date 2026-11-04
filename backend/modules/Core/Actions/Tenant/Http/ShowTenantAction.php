<?php

namespace Modules\Core\Actions\Tenant\Http;

use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Requests\Tenant\AuthenticatedRequest;
use Modules\Core\Resources\Tenant\TenantResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowTenantAction
{
    public function __invoke(
        AuthenticatedRequest $request,
        Tenant $tenant,
        Responder $responder,
    ): Response {
        return $responder->resource(TenantResource::make($tenant));
    }
}
