<?php

namespace Modules\Masterdata\Actions\Project\Http;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Project\ProjectUserIndexRequest;
use Modules\Masterdata\Resources\Project\ProjectUserResource;
use Symfony\Component\HttpFoundation\Response;

final class ListProjectUsersAction
{
    public function __invoke(
        ProjectUserIndexRequest $request,
        Tenant $tenant,
        Project $project,
        Responder $responder,
    ): Response {
        $search = $request->string('search')->value();

        $users = $project->users()
            ->when($search !== '', static function (Builder $query) use ($search): void {
                $query->where(static function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%$search%")
                        ->orWhere('email', 'like', "%$search%");
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $responder->resource(ProjectUserResource::collection($users));
    }
}
