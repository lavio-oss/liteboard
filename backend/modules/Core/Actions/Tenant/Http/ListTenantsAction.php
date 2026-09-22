<?php

namespace Modules\Core\Actions\Tenant\Http;

use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Tenant\Tenant;
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

        Gate::authorize('viewAny', Tenant::class);

        $tenants = $user->isPlatformAdmin()
            ? Tenant::query()->orderBy('name')->get()
            : $user->tenants()->orderBy('name')->get();

        return $responder->resource(TenantResource::collection($tenants));
    }
}
