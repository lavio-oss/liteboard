<?php

namespace Modules\Core\Actions\Tenant\Http;

use Modules\Core\Actions\Tenant\CreateTenantAction as CreateTenant;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Tenant\StoreTenantRequest;
use Modules\Core\Resources\Tenant\TenantResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateTenantAction
{
    public function __invoke(
        StoreTenantRequest $request,
        CreateTenant $createTenant,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        /** @var array{name: string, code: string, status?: string} $attributes */
        $attributes = $request->validated();
        $tenant = $createTenant->execute($user, $attributes);

        return $responder->resource(TenantResource::make($tenant), Response::HTTP_CREATED);
    }
}
