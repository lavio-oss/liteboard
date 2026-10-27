<?php

namespace Modules\Admin\Actions\Project\Http;

use Illuminate\Database\Eloquent\Builder;
use Modules\Admin\Requests\Project\ProjectIndexRequest;
use Modules\Admin\Resources\Project\ProjectResource;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListProjectsAction
{
    public function __invoke(
        ProjectIndexRequest $request,
        Tenant $tenant,
        Responder $responder,
    ): Response {
        $search = $request->string('search')->value();
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $projects = $tenant->projects()
            ->with([
                'users' => static function ($query) use ($user): void {
                    $query->whereKey($user->getKey());
                },
                'provisioningProfile',
            ])
            ->when(
                ! $user->isPlatformAdmin()
                    && ($request->boolean('participating') || ! $tenant->hasRole($user, TenantRole::Owner, TenantRole::Admin)),
                static function (Builder $query) use ($user): void {
                    $query->whereHas('users', static function (Builder $query) use ($user): void {
                        $query->whereKey($user->getKey());
                    });
                },
            )
            ->when($search !== '', static function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%$search%");
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $responder->resource(ProjectResource::collection($projects));
    }
}
