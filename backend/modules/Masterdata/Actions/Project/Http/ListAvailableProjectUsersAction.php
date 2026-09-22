<?php

namespace Modules\Masterdata\Actions\Project\Http;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Project\AvailableProjectUserRequest;
use Modules\Masterdata\Resources\Project\AvailableProjectUserResource;
use Symfony\Component\HttpFoundation\Response;

final class ListAvailableProjectUsersAction
{
    public function __invoke(
        AvailableProjectUserRequest $request,
        Tenant $tenant,
        Project $project,
        Responder $responder,
    ): Response {
        $search = $request->string('search')->value();

        $users = $tenant->users()
            ->whereDoesntHave('projects', static function (Builder $query) use ($project): void {
                $query->whereKey($project->getKey());
            })
            ->when($search !== '', static function (Builder $query) use ($search): void {
                $query->where(static function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%$search%")
                        ->orWhere('email', 'like', "%$search%");
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $responder->resource(AvailableProjectUserResource::collection($users));
    }
}
