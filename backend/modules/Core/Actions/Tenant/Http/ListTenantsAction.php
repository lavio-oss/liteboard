<?php

namespace Modules\Core\Actions\Tenant\Http;

use Modules\Core\Models\User\User;
use Modules\Core\Requests\Tenant\AuthenticatedRequest;
use Modules\Core\Resources\Tenant\TenantResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListTenantsAction
{
    public function __invoke(AuthenticatedRequest $request, Responder $responder): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return $responder->resource(TenantResource::collection($user->tenants()->orderBy('name')->get()));
    }
}
