<?php

namespace Modules\Masterdata\Controllers\Project;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Controllers\Controller;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;
use Modules\Masterdata\Actions\Project\CreateProjectAction;
use Modules\Masterdata\Actions\Project\UpdateProjectAction;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Project\ProjectIndexRequest;
use Modules\Masterdata\Requests\Project\StoreProjectRequest;
use Modules\Masterdata\Requests\Project\UpdateProjectRequest;
use Modules\Masterdata\Resources\Project\ProjectResource;
use Symfony\Component\HttpFoundation\Response;

final class ProjectController extends Controller
{
    public function index(ProjectIndexRequest $request, Tenant $tenant): AnonymousResourceCollection
    {
        $search = $request->string('search')->value();

        $projects = $tenant->projects()
            ->when($search !== '', static function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%$search%");
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return ProjectResource::collection($projects);
    }

    public function store(
        StoreProjectRequest $request,
        Tenant $tenant,
        CreateProjectAction $createProject,
    ): JsonResponse {
        /** @var array{name: string, description?: string|null, status?: string} $attributes */
        $attributes = $request->validated();
        $project = $createProject->execute($tenant, $attributes);

        return ProjectResource::make($project)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(AuthenticatedRequest $request, Tenant $tenant, Project $project): ProjectResource
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Gate::forUser($user)->authorize('view', $project);

        return ProjectResource::make($project);
    }

    public function update(
        UpdateProjectRequest $request,
        Tenant $tenant,
        Project $project,
        UpdateProjectAction $updateProject,
    ): ProjectResource {
        /** @var array{name?: string, description?: string|null, status?: string} $attributes */
        $attributes = $request->validated();
        $project = $updateProject->execute($project, $attributes);

        return ProjectResource::make($project);
    }

    public function destroy(AuthenticatedRequest $request, Tenant $tenant, Project $project): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Gate::forUser($user)->authorize('delete', $project);
        $project->delete();

        return response()->noContent();
    }
}
