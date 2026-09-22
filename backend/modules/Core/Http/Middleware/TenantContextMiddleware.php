<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\Response;

final class TenantContextMiddleware
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->route('tenant');
        $user = $request->user();

        if (
            ! $tenant instanceof Tenant
            || ! $user instanceof User
            || (! $user->isPlatformAdmin() && ! $tenant->hasMember($user))
        ) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $this->tenantContext->set($tenant);

        return $next($request);
    }
}
