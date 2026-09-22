<?php

namespace Modules\Core\Actions\Tenant\Http;

use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
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
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Gate::authorize('view', $tenant);

        $membership = $user->isPlatformAdmin()
            ? $tenant
            : $user->tenants()->whereKey($tenant->getKey())->first();

        if (! $membership instanceof Tenant) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $responder->resource(TenantResource::make($membership));
    }
}
