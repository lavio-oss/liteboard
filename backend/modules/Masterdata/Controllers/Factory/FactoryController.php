<?php

namespace Modules\Masterdata\Controllers\Factory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Controllers\Controller;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;
use Modules\Masterdata\Actions\Factory\CreateFactoryAction;
use Modules\Masterdata\Actions\Factory\UpdateFactoryAction;
use Modules\Masterdata\Models\Factory\Factory as FactoryModel;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Factory\FactoryIndexRequest;
use Modules\Masterdata\Requests\Factory\StoreFactoryRequest;
use Modules\Masterdata\Requests\Factory\UpdateFactoryRequest;
use Modules\Masterdata\Resources\Factory\FactoryResource;
use Symfony\Component\HttpFoundation\Response;

final class FactoryController extends Controller
{
    public function index(FactoryIndexRequest $request, Tenant $tenant, Project $project): AnonymousResourceCollection
    {
        $search = $request->string('search')->value();

        $factories = $project->factories()
            ->when($search !== '', static function (Builder $query) use ($search): void {
                $query->where(static function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%$search%")
                        ->orWhere('code', 'like', "%$search%");
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return FactoryResource::collection($factories);
    }

    public function store(
        StoreFactoryRequest $request,
        Tenant $tenant,
        Project $project,
        CreateFactoryAction $createFactory,
    ): JsonResponse {
        /** @var array{name: string, code: string, location?: string|null, status?: string} $attributes */
        $attributes = $request->validated();
        $factory = $createFactory->execute($project, $attributes);

        return FactoryResource::make($factory)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(AuthenticatedRequest $request, Tenant $tenant, Project $project, FactoryModel $factory): FactoryResource
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Gate::forUser($user)->authorize('view', $factory);

        return FactoryResource::make($factory);
    }

    public function update(
        UpdateFactoryRequest $request,
        Tenant $tenant,
        Project $project,
        FactoryModel $factory,
        UpdateFactoryAction $updateFactory,
    ): FactoryResource {
        /** @var array{name?: string, code?: string, location?: string|null, status?: string} $attributes */
        $attributes = $request->validated();
        $factory = $updateFactory->execute($factory, $attributes);

        return FactoryResource::make($factory);
    }

    public function destroy(AuthenticatedRequest $request, Tenant $tenant, Project $project, FactoryModel $factory): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Gate::forUser($user)->authorize('delete', $factory);
        $factory->delete();

        return response()->noContent();
    }
}
