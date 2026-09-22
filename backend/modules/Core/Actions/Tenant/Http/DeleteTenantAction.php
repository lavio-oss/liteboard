<?php

namespace Modules\Core\Actions\Tenant\Http;

use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Tenant\AuthenticatedRequest;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class DeleteTenantAction
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

        Gate::forUser($user)->authorize('delete', $tenant);
        $tenant->delete();

        return $responder->noContent();
    }
}
