<?php

namespace Modules\Core\Controllers\Tenant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Actions\Tenant\CreateTenantAction;
use Modules\Core\Actions\Tenant\UpdateTenantAction;
use Modules\Core\Controllers\Controller;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;
use Modules\Core\Requests\Tenant\StoreTenantRequest;
use Modules\Core\Requests\Tenant\UpdateTenantRequest;
use Modules\Core\Resources\Tenant\TenantResource;
use Symfony\Component\HttpFoundation\Response;

final class TenantController extends Controller
{
    public function store(StoreTenantRequest $request, CreateTenantAction $createTenant): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        /** @var array{name: string, code: string, status?: string} $attributes */
        $attributes = $request->validated();
        $tenant = $createTenant->execute($user, $attributes);

        return TenantResource::make($tenant)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function index(AuthenticatedRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Gate::authorize('viewAny', Tenant::class);

        return TenantResource::collection(
            $user->tenants()->orderBy('name')->get(),
        );
    }

    public function show(AuthenticatedRequest $request, Tenant $tenant): TenantResource
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Gate::authorize('view', $tenant);

        $membership = $user->tenants()->whereKey($tenant->getKey())->first();

        if (! $membership instanceof Tenant) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return TenantResource::make($membership);
    }

    public function update(
        UpdateTenantRequest $request,
        Tenant $tenant,
        UpdateTenantAction $updateTenant,
    ): TenantResource {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        /** @var array{name?: string, code?: string, status?: string} $attributes */
        $attributes = $request->validated();
        $updateTenant->execute($tenant, $attributes);
        $membership = $user->tenants()->whereKey($tenant->getKey())->firstOrFail();

        return TenantResource::make($membership);
    }

    public function destroy(AuthenticatedRequest $request, Tenant $tenant): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Gate::forUser($user)->authorize('delete', $tenant);
        $tenant->delete();

        return response()->noContent();
    }
}
